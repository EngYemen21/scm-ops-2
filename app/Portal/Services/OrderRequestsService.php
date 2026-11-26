<?php

namespace App\Portal\Services;

use App\Models\Product;
use App\Portal\Models\OrderRequest;
use App\Portal\Models\OrderRequestLine;
use App\Portal\Support\Access;
use App\Portal\Support\Owned;
use App\Portal\Support\Parties;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Sales\SalesService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order requests: a branch of a customer asks for an order through the portal and a person decides.
 *
 * STATE MACHINE (`OrderRequest::TRANSITIONS`). pending → approved | rejected | cancelled, and nothing leaves those
 * three. While a request waits its lines may be corrected — every correction is one line of the log of order-line
 * changes (who, which product, before and after). Approving makes a DRAFT quotation of the sales module for the
 * customer through `SalesService::createQuotation` (it needs `sales.manage` besides `portal.manage`, and every sales
 * rule holds); the request keeps the link and the order is followed from the sales page.
 *
 * OWNERSHIP. While the section follows Odoo a request that came from Odoo is Odoo's: it was decided there and became
 * a sale order there; OPS shows it with its lines, the order it became and the changes made to that order's lines.
 * Notes are OPS's own on every request.
 */
class OrderRequestsService
{
    private const WITH = [Parties::CUSTOMER, Parties::BRANCH, 'quotation:id,number,status'];

    private const SORTS = ['requestDate' => 'request_date', 'number' => 'number', 'amountTotal' => 'amount_total', 'status' => 'status', 'decidedAt' => 'decided_at'];

    public const VALID_DAYS = 14;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SalesService $sales,
        private readonly LineChangesService $changes,
    ) {}

    // ───────────── reading ─────────────

    /** @param  array{status?:?string, customer?:?string, branch?:?string, origin?:?string, from?:?string, to?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $query = $this->filtered($f, $page->q)->with(self::WITH);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        if ($column === null) {
            $query->orderByRaw('request_date IS NULL')->orderByDesc('request_date')->orderByDesc('id');
        } else {
            if (in_array($column, ['request_date', 'decided_at'], true)) {
                $query->orderByRaw("{$column} IS NULL");
            }
            $query->orderBy($column, $page->order)->orderBy('id', $page->order);
        }

        return $page->paginate($query, fn (OrderRequest $r) => Shape::request($r));
    }

    public function get(string $id): array
    {
        $r = $this->find($id);
        $owned = Owned::bySource($r);
        $pending = $r->status === 'pending';
        $lines = OrderRequestLine::with([Parties::PRODUCT])->where('request_id', $r->id)->orderBy('line_no')->orderBy('id')->limit(OrderRequest::MAX_LINES + 50)->get();

        return Shape::request($r, true) + [
            'lines' => $lines->map(fn (OrderRequestLine $l) => Shape::requestLine($l))->all(),
            'changes' => $this->changes->of($r),
            'events' => PortalLog::of('request', $r->id, 100),
            // what may be done to it now — the client shows a button only for what would be accepted
            'can' => ['editLines' => ! $owned && $pending, 'approve' => ! $owned && $pending, 'reject' => ! $owned && $pending, 'cancel' => ! $owned && $pending, 'note' => true],
        ];
    }

    public function filtered(array $f, ?string $q): Builder
    {
        $query = OrderRequest::query();
        $status = trim((string) ($f['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $states = array_values(array_intersect(OrderRequest::STATUSES, explode(',', $status)));
            $states ? $query->whereIn('status', $states) : $query->whereRaw('1 = 0');
        }
        Parties::filterCustomer($query, $f['customer'] ?? null);
        Parties::filterOrigin($query, $f['origin'] ?? null);
        if (! empty($f['branch'])) {
            $query->where('branch_id', $f['branch']);
        }
        if (! empty($f['from'])) {
            $query->where('request_date', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $query->where('request_date', '<', Carbon::parse($f['to'])->startOfDay()->addDay());
        }
        if ($q !== null && $q !== '') {
            $like = Text::like($q);
            $op = Sql::like();
            $query->where(function ($w) use ($like, $op) {
                $w->where('number', $op, $like)->orWhere('requested_by', $op, $like)->orWhere('order_number', $op, $like);
                Parties::searchCustomer($w, $like);
            });
        }

        return $query;
    }

    // ───────────── writing ─────────────

    /**
     * A request entered for a branch by a member of staff, or handed in by the sales platform.
     *
     * @param  array{customerId:string, branchId?:?string, requestedBy?:?string, note?:?string, lines:array<int, array{sku:string, qty:int|string, price?:float|int|string|null}>}  $dto
     */
    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $customer = Parties::customer($dto['customerId'] ?? null);
            if (! $customer->active) {
                throw AppError::rule('CUSTOMER_INACTIVE', "العميل {$customer->name_ar} ({$customer->code}) موقوف — لا يُسجَّل له طلب", "Customer {$customer->code} is inactive");
            }
            $branch = ! empty($dto['branchId']) ? Parties::branch($dto['branchId'], $customer) : null;
            if ($branch && ! $branch->active) {
                throw AppError::rule('PORTAL_BRANCH_ARCHIVED', "الفرع «{$branch->name}» مؤرشف", "Branch \"{$branch->name}\" is archived");
            }
            $lines = $this->resolve((array) $dto['lines']);
            $r = OrderRequest::create([
                'number' => $this->numbering->next('ORQ'), 'status' => 'pending', 'customer_id' => $customer->id, 'branch_id' => $branch?->id,
                'requested_by' => Text::clean($dto['requestedBy'] ?? null, 190) ?? ($user->nameAr ?: $user->username), 'request_date' => now(), 'note' => Text::clean($dto['note'] ?? null, 4000),
                'amount_total' => self::total($lines), 'currency' => 'SAR', 'lines_count' => count($lines), 'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            foreach ($lines as $i => $l) {
                OrderRequestLine::create(['request_id' => $r->id, 'line_no' => $i + 1, 'product_id' => $l['product']->id, 'description' => $l['product']->name_ar,
                    'qty' => $l['qty'], 'price' => $l['price'], 'subtotal' => round($l['qty'] * $l['price'], 2)]);
            }
            PortalLog::add('request', $r->id, 'created', $user, ['data' => ['lines' => count($lines), 'total' => Text::money($r->amount_total)]]);
            $this->audit->log($user, ['action' => 'PORTAL.REQUEST.CREATE', 'entityType' => 'PortalOrderRequest', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'newValue' => ['customer' => $customer->code, 'branch' => $branch?->name, 'lines' => $dto['lines']]]);
            $this->notify->activity($user, 'PortalOrderRequest', $r->id, $r->number, "طلب شراء من البوابة بانتظار القرار: {$r->number} — {$customer->name_ar}".($branch ? " (فرع {$branch->name})" : ''),
                "Portal order request {$r->number} is waiting for a decision — {$customer->name_en}", ApplicationsService::reviewerRoles());

            return $r->id;
        });

        return $this->get($id);
    }

    /**
     * The lines of a waiting request, as they should be now. What differs from the lines it had is written to the log
     * of line changes: a product that came in, one that went out, a quantity or a price that changed.
     *
     * @param  array<int, array{sku:string, qty:int|string, price?:float|int|string|null}>  $lines
     */
    public function setLines(AuthUser $user, string $id, array $lines): array
    {
        DB::transaction(function () use ($user, $id, $lines) {
            $r = $this->locked($id);
            Owned::refuse($r, 'request');
            $this->mustBePending($r);
            $new = $this->resolve($lines);
            $old = OrderRequestLine::where('request_id', $r->id)->orderBy('line_no')->orderBy('id')->get();
            // the line a product has now; a line whose product is not an OPS product cannot be sent again, so it goes
            $byProduct = $old->whereNotNull('product_id')->keyBy(fn (OrderRequestLine $l) => (string) $l->product_id);
            $logged = 0;
            $kept = [];
            foreach ($new as $i => $l) {
                $p = $l['product'];
                $who = ['product_id' => $p->id, 'product_name' => $p->name_ar];
                $row = $byProduct->get((string) $p->id);
                $values = ['line_no' => $i + 1, 'qty' => $l['qty'], 'price' => $l['price'], 'subtotal' => round($l['qty'] * $l['price'], 2)];
                if (! $row) {
                    OrderRequestLine::create($values + ['request_id' => $r->id, 'product_id' => $p->id, 'description' => $p->name_ar]);
                    $this->changes->record($user, $r, $who, 'added', null, null, Text::qty($l['qty']));
                    $logged++;

                    continue;
                }
                $kept[$row->id] = true;
                if (! Shape::same($row->qty, $l['qty'])) {
                    $this->changes->record($user, $r, $who, 'modified', 'qty', Text::qty($row->qty), Text::qty($l['qty']));
                    $logged++;
                }
                if (! Shape::same($row->price, $l['price'])) {
                    $this->changes->record($user, $r, $who, 'modified', 'price', Text::money($row->price), Text::money($l['price']));
                    $logged++;
                }
                if (Shape::changes($row, $values)) {
                    $row->update($values);
                }
            }
            foreach ($old as $row) {
                if (isset($kept[$row->id])) {
                    continue;
                }
                // a line of a request that waits is working data: it goes, and the log keeps that it was there
                $name = $row->product_id ? Product::whereKey($row->product_id)->value('name_ar') : null;
                $this->changes->record($user, $r, ['product_id' => $row->product_id, 'product_ref' => $row->product_ref, 'product_name' => $name ?? $row->product_name ?? $row->description],
                    'removed', null, Text::qty($row->qty), null);
                $logged++;
                $row->delete();
            }
            if ($logged === 0) {
                return;
            }
            $before = Text::money($r->amount_total);
            $r->update(['amount_total' => self::total($new), 'lines_count' => count($new), 'updated_by' => $user->username]);
            PortalLog::add('request', $r->id, 'lines_changed', $user, ['data' => ['changes' => $logged, 'totalFrom' => $before, 'totalTo' => Text::money($r->amount_total)]]);
            $this->audit->log($user, ['action' => 'PORTAL.REQUEST.LINES', 'entityType' => 'PortalOrderRequest', 'entityId' => $r->id, 'entityNumber' => $r->number,
                'oldValue' => ['total' => $before], 'newValue' => ['total' => Text::money($r->amount_total), 'changes' => $logged]]);
        });

        return $this->get($id);
    }

    /**
     * pending → approved: a draft quotation is made for the customer out of the request's lines.
     *
     * @param  array{validUntil?:?string, note?:?string}  $dto
     */
    public function approve(AuthUser $user, string $id, array $dto): array
    {
        // before the transaction: a refused permission is written to the audit trail and must stay there
        Access::need($user, 'sales.manage', 'إنشاء عرض سعر من طلب البوابة', 'making a quotation from a portal order request');

        DB::transaction(function () use ($user, $id, $dto) {
            $r = $this->locked($id);
            Owned::refuse($r, 'request');
            $this->move($r, 'approved');
            $r->load([Parties::CUSTOMER, Parties::BRANCH]);
            $customer = $r->customer_id ? $r->customer : null;
            if (! $customer) {
                throw AppError::rule('PORTAL_NO_CUSTOMER', 'صاحب هذا الطلب ليس عميلًا في نظام العمليات بعد — يُسعَّر له بعد أن يُربط بعميل', 'The owner of this request is not an OPS customer yet — it can be quoted once it is linked to one');
            }
            $lines = [];
            foreach (OrderRequestLine::with([Parties::PRODUCT])->where('request_id', $r->id)->orderBy('line_no')->orderBy('id')->get() as $l) {
                $p = $l->product_id ? $l->product : null;
                $name = $p?->name_ar ?? $l->product_name ?? $l->description ?? "#{$l->line_no}";
                if (! $p) {
                    throw AppError::rule('PORTAL_REQUEST_LINE', "السطر {$l->line_no} ({$name}) ليس صنفًا في نظام العمليات بعد — يُربط الصنف أو يُحذف السطر", "Line {$l->line_no} is not an OPS product yet — link the product or remove the line", ['line' => $l->line_no]);
                }
                $qty = (float) $l->qty;
                if ($qty < 1 || abs($qty - round($qty)) > 0.0005) {
                    throw AppError::rule('PORTAL_REQUEST_LINE', "كمية السطر {$l->line_no} ({$name}) ليست عددًا صحيحًا من الوحدات — صحّحها قبل الاعتماد", "The quantity of line {$l->line_no} is not a whole number of units — correct it before approving", ['line' => $l->line_no]);
                }
                if ((float) $l->price <= 0) {
                    throw AppError::rule('PORTAL_REQUEST_LINE', "السطر {$l->line_no} ({$name}) بلا سعر — أدخل سعره قبل الاعتماد", "Line {$l->line_no} has no price — price it before approving", ['line' => $l->line_no]);
                }
                $lines[] = ['sku' => $p->sku, 'qty' => (int) round($qty), 'price' => (float) $l->price];
            }
            if (! $lines) {
                throw AppError::rule('PORTAL_REQUEST_EMPTY', "الطلب {$r->number} بلا أسطر", "Request {$r->number} has no lines");
            }
            $branch = $r->branch_id ? $r->branch : null;
            $made = $this->sales->createQuotation($user, [
                'customerCode' => $customer->code, 'validUntil' => ($dto['validUntil'] ?? null) ?: now()->addDays(self::VALID_DAYS)->toDateString(),
                'notes' => "طلب البوابة {$r->number}".($branch ? " — فرع {$branch->name}" : '').($r->requested_by ? " — بطلب {$r->requested_by}" : ''), 'action' => 'draft', 'lines' => $lines,
            ]);
            $hours = $r->request_date ? round($r->request_date->diffInSeconds(now(), true) / 3600, 2) : null;
            $r->update(['status' => 'approved', 'decided_by' => $user->nameAr ?: $user->username, 'decided_at' => now(), 'decision_hours' => $hours, 'quotation_id' => $made['id'], 'updated_by' => $user->username]);
            PortalLog::add('request', $r->id, 'approved', $user, ['note' => $dto['note'] ?? null, 'data' => ['quotation' => $made['number'], 'quotationId' => $made['id'], 'total' => $made['totals']['total'] ?? null]]);
            $this->audit->status($user, 'PortalOrderRequest', $r->id, $r->number, 'pending', 'approved', "عرض السعر {$made['number']}");
            $this->notify->activity($user, 'PortalOrderRequest', $r->id, $r->number, "اعتُمد طلب البوابة {$r->number} وأُنشئ عرض السعر {$made['number']} للعميل {$customer->name_ar}",
                "Portal request {$r->number} approved — quotation {$made['number']} created", ['sales']);
        });

        return $this->get($id);
    }

    public function reject(AuthUser $user, string $id, string $reason): array
    {
        return $this->close($user, $id, 'rejected', $reason);
    }

    public function cancel(AuthUser $user, string $id, ?string $reason): array
    {
        return $this->close($user, $id, 'cancelled', $reason);
    }

    /** A note on the timeline. OPS's own on every request — also on one that Odoo owns. */
    public function note(AuthUser $user, string $id, string $text): array
    {
        DB::transaction(function () use ($user, $id, $text) {
            $r = $this->locked($id);
            PortalLog::add('request', $r->id, 'note', $user, ['note' => $text]);
            $this->audit->log($user, ['action' => 'PORTAL.REQUEST.NOTE', 'entityType' => 'PortalOrderRequest', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => mb_substr(trim($text), 0, 300)]);
        });

        return $this->get($id);
    }

    // ───────────── pieces ─────────────

    public function find(string $id): OrderRequest
    {
        return OrderRequest::with(self::WITH)->where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->first()
            ?? throw AppError::notFound('PORTAL_REQUEST_NOT_FOUND', 'طلب الشراء غير موجود', 'Order request not found');
    }

    /** The request, its row held for the rest of the transaction (two people cannot decide it at once). */
    private function locked(string $id): OrderRequest
    {
        return OrderRequest::where(fn ($w) => $w->where('id', $id)->orWhere('number', $id))->lockForUpdate()->first()
            ?? throw AppError::notFound('PORTAL_REQUEST_NOT_FOUND', 'طلب الشراء غير موجود', 'Order request not found');
    }

    private function close(AuthUser $user, string $id, string $to, ?string $reason): array
    {
        DB::transaction(function () use ($user, $id, $to, $reason) {
            $r = $this->locked($id);
            Owned::refuse($r, 'request');
            $this->move($r, $to);
            $reason = Text::clean($reason, 2000);
            $hours = $r->request_date ? round($r->request_date->diffInSeconds(now(), true) / 3600, 2) : null;
            $r->update(['status' => $to, 'decided_by' => $user->nameAr ?: $user->username, 'decided_at' => now(), 'decision_hours' => $hours, 'updated_by' => $user->username]
                + ($to === 'rejected' ? ['rejection_reason' => $reason] : []));
            PortalLog::add('request', $r->id, $to, $user, ['note' => $reason]);
            $this->audit->status($user, 'PortalOrderRequest', $r->id, $r->number, 'pending', $to, $reason === null ? null : mb_substr($reason, 0, 500));
            $this->notify->activity($user, 'PortalOrderRequest', $r->id, $r->number, ($to === 'rejected' ? 'رُفض' : 'أُلغي')." طلب البوابة {$r->number}", "Portal request {$r->number} {$to}");
        });

        return $this->get($id);
    }

    /** Refuses a move the state machine does not allow. */
    private function move(OrderRequest $r, string $to): void
    {
        if (! in_array($to, OrderRequest::TRANSITIONS[$r->status] ?? [], true)) {
            throw AppError::rule('PORTAL_REQUEST_TRANSITION', "طلب البوابة {$r->number} ".self::stateAr($r->status).' — لا يُغيَّر قراره', "Request {$r->number} is {$r->status} — its decision does not change",
                ['from' => $r->status, 'to' => $to]);
        }
    }

    private function mustBePending(OrderRequest $r): void
    {
        if ($r->status !== 'pending') {
            throw AppError::rule('PORTAL_REQUEST_DECIDED', "طلب البوابة {$r->number} ".self::stateAr($r->status).' — لا تُعدَّل أسطره', "Request {$r->number} is {$r->status} — its lines cannot be changed", ['status' => $r->status]);
        }
    }

    /**
     * The lines of a payload against the catalogue: every product exists, is sold and appears once; quantities are
     * whole units (what OPS sells and quotes), a price may still be missing (0) while the request waits.
     *
     * @return array<int, array{product:Product, qty:float, price:float}>
     */
    private function resolve(array $lines): array
    {
        $lines = array_values($lines);
        if (! $lines) {
            throw AppError::validation('PORTAL_REQUEST_EMPTY', 'الطلب يحتاج سطرًا واحدًا على الأقل', 'A request needs at least one line', [['path' => 'lines', 'message' => 'سطر واحد على الأقل']]);
        }
        if (count($lines) > OrderRequest::MAX_LINES) {
            throw AppError::validation('PORTAL_TOO_MANY_LINES', 'عدد الأسطر يتجاوز '.OrderRequest::MAX_LINES, 'More than '.OrderRequest::MAX_LINES.' lines', [['path' => 'lines', 'message' => 'عدد الأسطر كبير']]);
        }
        $out = $seen = [];
        foreach ($lines as $i => $l) {
            $p = Parties::product($l['sku'] ?? null, "lines.{$i}.sku");
            if (! $p->active) {
                throw AppError::validation('SKU_INACTIVE', "المنتج {$p->name_ar} ({$p->sku}) موقوف", "Product {$p->sku} is inactive", [['path' => "lines.{$i}.sku", 'message' => 'منتج موقوف']]);
            }
            if (isset($seen[$p->id])) {
                throw AppError::validation('PORTAL_LINE_TWICE', "المنتج {$p->sku} مكرر في الأسطر", "Product {$p->sku} appears twice", [['path' => "lines.{$i}.sku", 'message' => 'مكرر']]);
            }
            $seen[$p->id] = true;
            $out[] = ['product' => $p, 'qty' => (float) (int) $l['qty'], 'price' => round((float) ($l['price'] ?? 0), 2)];
        }

        return $out;
    }

    /** @param  array<int, array{qty:float, price:float}>  $lines */
    private static function total(array $lines): float
    {
        return round(array_sum(array_map(fn (array $l) => round($l['qty'] * $l['price'], 2), $lines)), 2);
    }

    public static function stateAr(string $status): string
    {
        return ['pending' => 'بانتظار القرار', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'cancelled' => 'ملغى'][$status] ?? $status;
    }
}
