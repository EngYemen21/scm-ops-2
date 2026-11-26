<?php

namespace App\Services\Sales;

use App\Integration\Services\OrderEvents;
use App\Integration\Services\OrderIntakeService;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\OrderConsolidation;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\Product;
use App\Models\ProofOfDelivery;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\Warehouse;
use App\Pricing\Services\PriceService;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Inventory\InventoryService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales: Quotation → Sales Order → stock check → Reservation → Allocation (FEFO) → Fulfillment order.
 * Reservation and allocation are separate records; no overselling; expired/quarantined batches are excluded before
 * FEFO (inventory engine). Documents are returned by re-reading them after the transaction committed.
 *
 * Rules that are policies, not code (GET /sales/policy):
 *  - credit:    the gate is CreditService::assertCanOrder; customers.balance is the derived exposure (CreditService::sync);
 *  - discount:  a line discount above `sales.maxDiscountPct` needs a holder of `discount.approve`, on quotations and orders alike;
 *  - validity:  a quotation past `valid_until` cannot be sent, approved or converted — it becomes `expired` (extend to reopen);
 *  - shortage:  with `sales.allowBackorder` an order may be accepted short: what is available is reserved, the rest becomes
 *               ONE linked backorder order that waits for stock (retryBackorders).
 */
class SalesService
{
    private const QT_LIST_SHAPE = ['customer' => ['code', 'nameAr', 'nameEn'], 'lines.*.product' => ['sku', 'nameAr', 'nameEn'], 'so' => ['number', 'status']];

    private const QT_SHAPE = ['lines.*.product' => ['sku', 'nameAr', 'nameEn', 'baseUom'], 'so' => ['number', 'status']];

    private const SO_SHAPE = [
        'customer' => ['code', 'nameAr', 'nameEn', 'zone', 'city', 'terms'], 'warehouse' => ['code', 'nameAr'],
        'lines.*.product' => ['sku', 'nameAr', 'nameEn', 'weightKg', 'storageClass'],
        'lines.*.allocations.*.bin' => ['code'], 'lines.*.allocations.*.batch' => ['batchNo', 'expiryDate'],
        'fos.*' => ['id', 'number', 'status', 'tripId', 'trip'], 'fos.*.trip' => ['number', 'status'],
        'quotation' => ['number'], 'consolidation' => ['number', 'status'],
        'backorderOf' => ['number', 'status'], 'backorders.*' => ['number', 'status', 'fulfilStatus'],
    ];

    private const OC_LIST_SHAPE = [
        'warehouse' => ['code'], 'orders.*' => ['number', 'status', 'customer', 'kg', 'cbm', 'fos'], 'orders.*.customer' => ['nameAr', 'zone'],
        'orders.*.fos.*' => ['number', 'status'], 'trips.*' => ['number', 'status'],
    ];

    private const OC_SHAPE = ['orders.*.lines.*.product' => ['sku', 'nameAr'], 'orders.*.fos.*' => ['number', 'status'], 'trips.*' => ['number', 'status', 'vehicleId', 'driverId']];

    /** Quotation states that are still an offer to the customer — the ones that can run out of validity. */
    private const QT_OPEN = ['draft', 'sent', 'approved'];

    /** Order states a plain cancel accepts: nothing has left its bin. After that the way back is the warehouse's put-back. */
    private const SO_CANCELLABLE = ['draft', 'confirmed', 'reserved', 'allocated', 'preparing'];

    /** Batch states in which orders may still join. */
    private const OC_OPEN = ['open', 'consolidating'];

    /** Batch states in which any order may still leave (nothing has been picked for the batch). */
    private const OC_BEFORE_PICKING = ['open', 'consolidating', 'readypick'];

    /** Fulfilment-order states the batch gates accept as "packed or beyond" / "finished" — a partial or failed delivery is finished too. */
    private const FO_PACKED = ['packed', 'loaded', 'onroute', 'delivered', 'partial', 'failed'];

    private const FO_FINISHED = ['delivered', 'partial', 'failed'];

    /** The pick walk by temperature: ambient / hazmat first, chilled next, frozen last (the least time out of the cold). */
    private const TEMP_RANK = ['ambient' => 0, 'hazmat' => 0, 'chilled' => 1, 'frozen' => 2];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly InventoryService $inventory,
        private readonly CreditService $credit,
        private readonly BackorderService $backorders,
    ) {}

    /** The configurable sales rules the screens show and pre-check (the server stays the authority). */
    public function policy(): array
    {
        return [
            'maxDiscountPct' => $this->settings->get('sales.maxDiscountPct'),
            'vatPct' => $this->settings->get('sales.vatPct'),
            'allowBackorder' => (bool) $this->settings->get('sales.allowBackorder'),
            'zeroCreditLimitUnlimited' => (bool) $this->settings->get('sales.zeroCreditLimitUnlimited'),
        ];
    }

    // ───────────── quotations ─────────────

    /**
     * `discount=pending`: the approver's work list — open quotations whose discount is above the limit and not approved yet.
     *
     * @param  array{status?:?string, customer?:?string, discount?:?string}  $filters
     */
    public function listQuotations(Paging $page, array $filters): array
    {
        $query = Quotation::with(['customer', 'lines.product', 'so'])->orderByDesc('date')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (($filters['discount'] ?? null) === 'pending') {
            $max = (float) $this->settings->get('sales.maxDiscountPct');
            $query->whereNull('discount_approved_by')->whereIn('status', [...self::QT_OPEN, 'expired'])->whereHas('lines', fn ($l) => $l->where('disc_pct', '>', $max));
        }
        if (! empty($filters['customer'])) {
            $query->whereHas('customer', fn ($c) => $c->where('code', $filters['customer']));
        }
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%")->orWhere('name_en', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (Quotation $x) => Shape::apply($x->toArray(), self::QT_LIST_SHAPE) + $this->quotationFacts($x));
    }

    public function getQuotation(string $idOrNumber): array
    {
        $x = $this->findQuotation($idOrNumber);

        return Shape::apply($x->toArray(), self::QT_SHAPE) + $this->quotationFacts($x) + ['history' => $this->history('Quotation', $x->id)];
    }

    /**
     * A quotation is always saved; whether it may go to the customer is a separate question. A discount above the limit
     * is stamped at once when its author holds `discount.approve`; otherwise the draft waits for approveQuotationDiscount().
     *
     * @param  array{customerCode:string, validUntil:string, terms?:?string, delivery?:?string, notes?:?string, action?:string, attachments?:?array,
     *               lines:array<int, array{sku:string, qty:int, price:float|int|string, discPct?:float|int|string|null}>}  $dto
     */
    public function createQuotation(AuthUser $user, array $dto): array
    {
        $action = $dto['action'] ?? 'draft';
        if (Carbon::parse($dto['validUntil'])->startOfDay()->lt(Carbon::today())) {
            throw AppError::validation('VALID_PAST', 'تاريخ الصلاحية منتهٍ', 'The validity date is in the past');
        }

        $id = DB::transaction(function () use ($user, $dto, $action) {
            $c = $this->customer($dto['customerCode']);
            $lines = $this->resolveLines($dto['lines'], $c);
            $disc = $this->discountState($lines, null);
            $approvedBy = $disc['aboveMax'] && $user->can('discount.approve') ? $user->username : null;
            if ($disc['aboveMax'] && ! $approvedBy && $action === 'sent') {
                throw $this->discountRefusal($disc, 'احفظ العرض كمسودة واطلب اعتماد الخصم قبل إرساله للعميل', 'save it as a draft and have the discount approved before it is sent');
            }
            $number = $this->numbering->next('QT');
            $qt = Quotation::create([
                'number' => $number, 'customer_id' => $c->id, 'valid_until' => Carbon::parse($dto['validUntil']), 'status' => $action,
                'terms' => $dto['terms'] ?? null, 'delivery' => $dto['delivery'] ?? null, 'notes' => $dto['notes'] ?? null,
                'created_by_id' => $user->id, 'created_by' => $user->username, 'attachments' => $dto['attachments'] ?? [], 'discount_approved_by' => $approvedBy,
            ]);
            foreach ($lines as $l) {
                QuotationLine::create(['quotation_id' => $qt->id, 'line_no' => $l['lineNo'], 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'price' => $l['price'], 'disc_pct' => $l['discPct']]);
            }
            $this->audit->log($user, ['action' => 'QT.CREATE', 'entityType' => 'Quotation', 'entityId' => $qt->id, 'entityNumber' => $number,
                'newValue' => ['customer' => $c->code, 'lines' => $dto['lines'], 'status' => $action, 'discountApprovedBy' => $approvedBy]]);
            $t = $this->totals($lines);
            $sent = $action === 'sent';
            $this->notify->activity($user, 'Quotation', $qt->id, $number, "أُنشئ عرض سعر {$number} لـ {$c->name_ar}".($sent ? ' وأُرسل للعميل' : '')." — الإجمالي {$t['total']} ر.س", "Quotation {$number} created", $sent ? ['sales'] : []);
            if ($disc['aboveMax'] && ! $approvedBy) {
                $pct = self::fmt($disc['linePct']);
                $max = self::fmt($disc['maxPct']);
                $this->notify->activity($user, 'Quotation', $qt->id, $number, "عرض السعر {$number} ({$c->name_ar}) فيه خصم {$pct}% فوق الحد {$max}% — بانتظار اعتماد الخصم",
                    "Quotation {$number} carries a {$pct}% discount above the {$max}% limit — waiting for discount approval", $this->rolesWith('discount.approve'));
            }

            return $qt->id;
        });

        return $this->getQuotation($id);
    }

    public function setQuotationStatus(AuthUser $user, string $idOrNumber, string $to, ?string $note = null): Quotation
    {
        $q = Quotation::with('lines')->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود', 'Quotation not found');
        if ($to === 'converted') {
            throw AppError::validation('USE_CONVERT', 'استخدم إجراء التحويل إلى أمر بيع', 'Use the convert action');
        }
        $toCustomer = in_array($to, ['sent', 'approved'], true);
        if ($toCustomer) {
            $this->refuseExpired($user, $q);
        }
        Sm::assert('QUOTATION_TRANSITIONS', $q->status, $to, 'QT_TRANSITION');
        if ($toCustomer) {
            $disc = $this->discountState($q->lines, $q->discount_approved_by);
            if ($disc['needsApproval']) {
                throw $this->discountRefusal($disc, 'يعتمده صاحب صلاحية «اعتماد الخصم» من صفحة العرض أولًا', 'a holder of discount.approve must approve it on the quotation first');
            }
        }

        DB::transaction(function () use ($user, $q, $to, $note) {
            $from = $q->status;
            $q->update(['status' => $to]);
            $this->audit->status($user, 'Quotation', $q->id, $q->number, $from, $to, $note);
            $this->notify->activity($user, 'Quotation', $q->id, $q->number, "عرض السعر {$q->number} → {$to}", null, $to === 'sent' ? ['sales'] : []);
        });

        return $q->refresh();
    }

    /** The holder of `discount.approve` allows the discount of a quotation that is above the limit. Idempotent. */
    public function approveQuotationDiscount(AuthUser $user, string $idOrNumber, ?string $note = null): array
    {
        $q = $this->findQuotation($idOrNumber);
        if (! in_array($q->status, [...self::QT_OPEN, 'expired'], true)) {
            throw AppError::rule('QT_CLOSED', "عرض السعر {$q->number} مغلق ({$q->status}) — لا خصم يُعتمد عليه", "Quotation {$q->number} is closed ({$q->status})");
        }
        $disc = $this->discountState($q->lines, $q->discount_approved_by);
        if (! $disc['aboveMax']) {
            throw AppError::rule('DISCOUNT_NOT_NEEDED', 'لا خصم فوق الحد '.self::fmt($disc['maxPct']).'% في هذا العرض — لا يحتاج اعتمادًا', 'No discount above the limit on this quotation');
        }
        if ($disc['needsApproval']) {
            DB::transaction(function () use ($user, $q, $disc, $note) {
                $q->update(['discount_approved_by' => $user->username]);
                $pct = self::fmt($disc['linePct']);
                $this->audit->log($user, ['action' => 'QT.DISCOUNT_APPROVE', 'entityType' => 'Quotation', 'entityId' => $q->id, 'entityNumber' => $q->number,
                    'oldValue' => ['discountApprovedBy' => null], 'newValue' => ['discountApprovedBy' => $user->username, 'discountPct' => $disc['linePct'], 'maxPct' => $disc['maxPct'], 'note' => $note]]);
                $this->notify->activity($user, 'Quotation', $q->id, $q->number, "اعتُمد خصم {$pct}% على عرض السعر {$q->number}".($note ? " — {$note}" : '').' — يمكن إرساله للعميل',
                    "Discount of {$pct}% approved on quotation {$q->number}", ['sales']);
            });
        }

        return $this->getQuotation($q->id);
    }

    /**
     * A new validity date. An expired quotation comes back as a draft (the offer lapsed: it is sent and approved again);
     * an open one simply gets the date.
     */
    public function extendQuotation(AuthUser $user, string $idOrNumber, string $validUntil): array
    {
        $q = $this->findQuotation($idOrNumber);
        $until = Carbon::parse($validUntil)->startOfDay();
        if ($until->lt(Carbon::today())) {
            throw AppError::validation('VALID_PAST', 'تاريخ الصلاحية منتهٍ', 'The validity date is in the past');
        }
        if (! in_array($q->status, [...self::QT_OPEN, 'expired'], true)) {
            throw AppError::rule('QT_CLOSED', "عرض السعر {$q->number} مغلق ({$q->status}) — انسخه لإنشاء عرض جديد", "Quotation {$q->number} is closed ({$q->status}) — duplicate it instead");
        }

        DB::transaction(function () use ($user, $q, $until) {
            $from = $q->status;
            $old = $q->valid_until?->toDateString();
            $to = $from === 'expired' ? 'draft' : $from;
            if ($to !== $from) {
                Sm::assert('QUOTATION_TRANSITIONS', $from, $to, 'QT_TRANSITION');
            }
            $q->update(['valid_until' => $until, 'status' => $to]);
            $this->audit->log($user, ['action' => 'QT.EXTEND', 'entityType' => 'Quotation', 'entityId' => $q->id, 'entityNumber' => $q->number,
                'oldValue' => ['validUntil' => $old, 'status' => $from], 'newValue' => ['validUntil' => $until->toDateString(), 'status' => $to]]);
            if ($to !== $from) {
                $this->audit->status($user, 'Quotation', $q->id, $q->number, $from, $to, "تمديد الصلاحية حتى {$until->toDateString()}");
            }
            $this->notify->activity($user, 'Quotation', $q->id, $q->number, "مُدّدت صلاحية عرض السعر {$q->number} حتى {$until->toDateString()}".($to !== $from ? ' — عاد مسودة' : ''),
                "Quotation {$q->number} extended to {$until->toDateString()}");
        });

        return $this->getQuotation($q->id);
    }

    /**
     * Daily housekeeping (scm:quotations-expire): every open quotation whose validity date has passed becomes `expired`.
     *
     * @return array{expired:int, numbers:string[]}
     */
    public function expireQuotations(?AuthUser $user = null): array
    {
        $due = Quotation::whereIn('status', self::QT_OPEN)->whereNotNull('valid_until')->where('valid_until', '<', Carbon::today())->orderBy('valid_until')->orderBy('id')->get();
        $numbers = [];
        foreach ($due as $q) {
            if ($this->expire($user, $q)) {
                $numbers[] = $q->number;
            }
        }

        return ['expired' => count($numbers), 'numbers' => $numbers];
    }

    public function duplicateQuotation(AuthUser $user, string $idOrNumber): array
    {
        $q = $this->findQuotation($idOrNumber);

        return $this->createQuotation($user, [
            'customerCode' => $q->customer->code, 'validUntil' => now()->addDays(14)->toDateString(), 'terms' => $q->terms ?: null,
            'delivery' => $q->delivery ?: null, 'notes' => $q->notes ?: null, 'action' => 'draft', 'lines' => $this->linesOf($q),
        ]);
    }

    /**
     * Quotation → Sales Order: requires an approved quotation that is still valid; then the order rules apply (discount,
     * credit, stock — one transaction).
     *
     * @param  array{warehouseCode?:?string, dueDate?:?string, window?:?string, priority?:?string, allowBackorder?:?bool}  $opts
     */
    public function convertQuotation(AuthUser $user, string $idOrNumber, array $opts = []): array
    {
        $q = $this->findQuotation($idOrNumber);
        if ($q->so_id || $q->status === 'converted') {
            throw AppError::rule('QT_CONVERTED', 'العرض محوَّل مسبقًا', 'Quotation already converted');
        }
        $this->refuseExpired($user, $q);
        if ($q->status !== 'approved') {
            throw AppError::rule('QT_NOT_APPROVED', 'التحويل يتطلب عرضًا معتمدًا', 'Conversion requires an approved quotation');
        }

        return $this->createOrder($user, [
            'customerCode' => $q->customer->code, 'warehouseCode' => ($opts['warehouseCode'] ?? null) ?: 'RYD',
            'dueDate' => ($opts['dueDate'] ?? null) ?: now()->addDay()->toDateString(), 'window' => ($opts['window'] ?? null) ?: '09:00–13:00',
            'priority' => ($opts['priority'] ?? null) ?: 'normal', 'lines' => $this->linesOf($q), 'allowBackorder' => ! empty($opts['allowBackorder']),
        ], $q->id);
    }

    // ───────────── sales orders ─────────────

    /**
     * `waiting=allocation`: orders still waiting for stock (status confirmed) — the "معلّقة" list; `shortage=1`: orders that
     * could not ship everything that was asked for; `backorders=1`: the linked backorder orders themselves.
     *
     * @param  array{status?:?string, customer?:?string, warehouse?:?string, waiting?:?string, shortage?:?string, backorders?:?string}  $filters
     */
    public function listOrders(Paging $page, array $filters): array
    {
        $query = SalesOrder::with(self::soWith())->orderByDesc('date')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['customer'])) {
            $query->whereHas('customer', fn ($c) => $c->where('code', $filters['customer']));
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }
        if (($filters['waiting'] ?? null) === 'allocation') {
            $query->where('status', 'confirmed');
        }
        if (self::truthy($filters['shortage'] ?? null)) {
            $query->where('has_shortage', true);
        }
        if (self::truthy($filters['backorders'] ?? null)) {
            $query->whereNotNull('backorder_of_id');
        }
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (SalesOrder $x) => Shape::apply($x->toArray(), self::SO_SHAPE) + ['totals' => $this->totals($x->lines)]);
    }

    public function getOrder(string $idOrNumber): array
    {
        $x = SalesOrder::with(self::soWith() + ['reservations.allocations' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('SO_NOT_FOUND', 'أمر البيع غير موجود', 'Sales order not found');
        $foIds = $x->fos->pluck('id')->all();

        return Shape::apply($x->toArray(), self::SO_SHAPE) + [
            'totals' => $this->totals($x->lines),
            'history' => $this->history('SalesOrder', $x->id),
            'pods' => ProofOfDelivery::whereIn('fo_id', $foIds)->orderBy('at')->get(['number', 'result', 'at', 'receiver_name'])->map->toArray()->all(),
            'returns' => ReturnOrder::whereIn('fo_id', $foIds)->orderBy('created_at')->get(['number', 'status', 'type'])->map->toArray()->all(),
            // what a waiting order still needs, against what the warehouse has right now
            'waiting' => $x->status === 'confirmed' ? $this->missingOf($x) : [],
        ];
    }

    /**
     * Direct sales order (or the order of an approved quotation): discount gate, credit gate, then stock.
     *
     * Stock: every line reserves FEFO what it asks for. When something is short the order is REFUSED as a whole and the
     * error says what is available — unless the caller sends `allowBackorder` and the policy `sales.allowBackorder` is on:
     * then what is available is reserved and the missing quantities move to ONE linked backorder order; when nothing at
     * all is available the order itself waits for stock (`confirmed`, fulfil_status `backordered`).
     *
     * @param  array{customerCode:string, warehouseCode:string, dueDate:string, window?:?string, priority?:?string, allowBackorder?:?bool,
     *               lines:array<int, array{sku:string, qty:int, price:float|int|string, discPct?:float|int|string|null}>}  $dto
     */
    public function createOrder(AuthUser $user, array $dto, ?string $quotationId = null): array
    {
        $txId = $this->inventory->newTxId();
        $backorderPolicy = (bool) $this->settings->get('sales.allowBackorder');
        $acceptShortage = $backorderPolicy && ! empty($dto['allowBackorder']);

        $id = DB::transaction(function () use ($user, $dto, $quotationId, $txId, $backorderPolicy, $acceptShortage) {
            $discountBy = null;
            if ($quotationId) {
                $qt = Quotation::whereKey($quotationId)->lockForUpdate()->first();
                if ($qt?->status !== 'approved') {
                    throw AppError::rule('QT_CONVERTED', 'العرض محوَّل مسبقًا', 'Quotation already converted'); // lost a double-click race
                }
                $discountBy = $qt->discount_approved_by;
            }
            // the customer row is locked: two orders of one customer pass the credit gate one after the other
            $c = Customer::whereKey($this->customer($dto['customerCode'])->id)->lockForUpdate()->firstOrFail();
            $w = Warehouse::where('code', $dto['warehouseCode'])->first() ?? throw AppError::notFound('WH_NOT_FOUND', "المستودع {$dto['warehouseCode']} غير موجود", "Warehouse {$dto['warehouseCode']} not found");
            $lines = $this->resolveLines($dto['lines'], $c);
            $t = $this->totals($lines);

            $disc = $this->discountState($lines, $discountBy);
            if ($disc['needsApproval']) {
                if (! $user->can('discount.approve')) {
                    throw $quotationId
                        ? $this->discountRefusal($disc, 'يعتمد صاحب الصلاحية خصم عرض السعر أولًا', 'the quotation discount must be approved first')
                        : $this->discountRefusal($disc, 'أنشئ عرض سعر واطلب اعتماد خصمه، ثم حوّله إلى أمر بيع', 'create a quotation, have its discount approved, then convert it');
                }
                $discountBy = $user->username;
            }
            // the whole ordered value counts, also the part that will wait on a backorder: it is still owed once it ships
            $this->credit->assertCanOrder($c, $t['total']);

            // what every line can get right now (rows locked); two lines of one product share its availability
            $left = [];
            $take = [];
            $shortages = [];
            foreach ($lines as $i => $l) {
                $p = $l['product'];
                $left[$p->id] ??= (int) $this->inventory->allocRows($p->id, $w->id, true)->sum(fn ($r) => $r->on_hand - $r->reserved);
                $take[$i] = min($l['qty'], $left[$p->id]);
                $left[$p->id] -= $take[$i];
                if ($take[$i] < $l['qty']) {
                    $shortages[] = ['sku' => $p->sku, 'nameAr' => $p->name_ar, 'nameEn' => $p->name_en, 'available' => $take[$i], 'requested' => $l['qty']];
                }
            }
            if ($shortages && ! $acceptShortage) {
                $s = $shortages[0];
                throw AppError::rule('INSUFFICIENT_AVAILABLE',
                    "مرفوض: المتاح لـ {$s['nameAr']} في {$w->code} ({$s['available']}) أقل من {$s['requested']} — لا Overselling".($backorderPolicy ? ' · يمكن قبول الطلب مع تعليق الباقي' : ''),
                    "Available for {$s['nameEn']} ({$s['available']}) is less than {$s['requested']}".($backorderPolicy ? ' — the order can be accepted with the rest on backorder' : ''),
                    ['sku' => $s['sku'], 'available' => $s['available'], 'requested' => $s['requested'], 'shortages' => $shortages, 'backorderAllowed' => $backorderPolicy]);
            }

            $number = $this->numbering->next('SO');
            $so = SalesOrder::create([
                'number' => $number, 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'due_date' => Carbon::parse($dto['dueDate']), 'window' => $dto['window'] ?? null,
                'priority' => $dto['priority'] ?? 'normal', 'status' => 'confirmed', 'created_by_id' => $user->id, 'created_by' => $user->username,
                'discount_approved_by' => $disc['aboveMax'] ? $discountBy : null,
            ] + self::weight($lines, array_column($lines, 'qty')));
            $parts = [];
            foreach ($lines as $i => $l) {
                $sl = SalesOrderLine::create(['so_id' => $so->id, 'line_no' => $l['lineNo'], 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'price' => $l['price'], 'disc_pct' => $l['discPct']]);
                if ($take[$i] > 0) {
                    $this->inventory->reserve($user, $txId, ['soId' => $so->id, 'soLineId' => $sl->id, 'productId' => $sl->product_id, 'warehouseId' => $w->id, 'qty' => $take[$i], 'referenceNumber' => $number]);
                }
                if ($take[$i] < $l['qty']) {
                    $parts[$sl->id] = $l['qty'] - $take[$i];
                }
            }

            $backorder = null;
            if (! $parts) {
                [$status, $note] = ['allocated', 'حجز FEFO'];
                $so->update(['status' => $status, 'fulfil_status' => 'full']);
                $textAr = '— حُجز المخزون FEFO وحُدّث رصيد العميل';
            } elseif (array_sum($take) === 0) {
                // nothing to ship yet: this order is itself the one that waits
                [$status, $note] = ['confirmed', 'بانتظار المخزون — لا رصيد متاح الآن'];
                $so->update(['fulfil_status' => 'backordered']);
                $textAr = '— لا رصيد متاح الآن؛ الأمر معلّق بانتظار المخزون';
            } else {
                $backorder = $this->backorders->split($user, $so, $parts, 'no_stock');
                [$status, $note] = ['allocated', "حجز FEFO للمتاح — الباقي في الطلب المعلّق {$backorder->number}"];
                $so->update(['status' => $status, 'fulfil_status' => 'full'] + self::weight($lines, $take));
                $textAr = "— حُجز المتاح FEFO والباقي في الطلب المعلّق {$backorder->number}";
            }
            if ($quotationId) {
                Quotation::whereKey($quotationId)->update(['status' => 'converted', 'so_id' => $so->id]);
                $this->audit->status($user, 'Quotation', $quotationId, '', 'approved', 'converted', $number, $txId);
            }
            $this->audit->log($user, ['action' => 'SO.CREATE', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $number,
                'newValue' => ['customer' => $c->code, 'warehouse' => $w->code, 'lines' => $dto['lines'], 'total' => $t['total'], 'quotationId' => $quotationId,
                    'discountApprovedBy' => $disc['aboveMax'] ? $discountBy : null, 'shortages' => $shortages, 'backorder' => $backorder?->number], 'transactionId' => $txId]);
            $this->audit->status($user, 'SalesOrder', $so->id, $number, null, $status, $note, $txId);
            $this->notify->activity($user, 'SalesOrder', $so->id, $number, "أُنشئ أمر بيع {$number}".($quotationId ? ' من عرض سعر' : ' مباشر').' '.$textAr,
                "Sales order {$number} created".($parts ? ' with a shortage' : ' and reserved'), $parts ? ['wm', 'disp', 'proc'] : ['wm', 'disp']);
            $this->credit->sync($c->id);

            return $so->id;
        });

        return $this->getOrder($id);
    }

    /**
     * Cancel an order nothing was picked for: its reservation is released and the customer's exposure recomputed. Once
     * the picker started, the goods must go back to their bins first — that is the warehouse's put-back, not this action.
     *
     * @return array{ok:bool}
     */
    public function cancelOrder(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $so = $this->findOrder($idOrNumber, ['lines', 'fos'], true);
            if (! in_array($so->status, self::SO_CANCELLABLE, true) || ! Sm::can('SO_TRANSITIONS', $so->status, 'cancelled')) {
                $how = in_array($so->status, ['picking', 'picked'], true) ? ' — يُعيد المستودع البضاعة إلى مواقعها من شاشة التجهيز (إرجاع للرف) ثم يُلغى' : '';
                throw AppError::rule('SO_CANCEL', "لا يمكن الإلغاء في الحالة «{$so->status}» — بدأ التنفيذ{$how}", 'Order cannot be cancelled at this stage');
            }
            if ($so->fos->contains(fn ($f) => ! in_array($f->status, ['alloc', 'cancelled'], true))) {
                throw AppError::rule('SO_CANCEL_FO', 'أمر التنفيذ بدأ التجهيز — لا يمكن الإلغاء', 'Fulfillment already started');
            }
            $foIds = $so->fos->pluck('id')->all();
            $this->inventory->release($so->id);
            FulfillmentOrder::where('so_id', $so->id)->update(['status' => 'cancelled']);
            PickTask::whereIn('pick_list_id', PickList::whereIn('fo_id', $foIds)->select('id'))->update(['status' => 'cancelled']);
            PickList::whereIn('fo_id', $foIds)->update(['status' => 'cancelled']);
            $from = $so->status;
            $so->update(['status' => 'cancelled']);
            $this->audit->status($user, 'SalesOrder', $so->id, $so->number, $from, 'cancelled', $reason);
            $this->notify->activity($user, 'SalesOrder', $so->id, $so->number, "أُلغي {$so->number} — أُفرج عن الحجز".($reason ? ' — '.$reason : ''), "{$so->number} cancelled — reservation released", ['sales']);
            // an order received from another system never counted in the OPS exposure: credit is that system's decision
            if (! $so->source_system) {
                $this->credit->sync($so->customer_id);
            }

            return ['ok' => true];
        });
    }

    /**
     * Reserve what a waiting order still needs from what is available now: everything → `allocated`; a part → the order
     * keeps waiting with what it got (fulfil_status `partial`); nothing at all → refused, nothing changed.
     */
    public function allocateOrder(AuthUser $user, string $idOrNumber): array
    {
        // An order another system sent is reserved by the integration layer, which also tells that system (order.reserved /
        // order.backordered) and keeps first-come-first-served among such orders: the button asks it to try now.
        $probe = $this->findOrder($idOrNumber, ['lines:id,so_id,product_id']);
        if ($probe->source_system) {
            if ($probe->status === 'confirmed') {
                app(OrderIntakeService::class)->topUp($probe->lines->pluck('product_id')->unique()->values()->all());
            }
            $after = $this->findOrder($probe->id, self::allocWith());
            $missing = $after->lines->filter(fn ($l) => $l->qty > $l->reserved_qty)
                ->map(fn ($l) => ['sku' => $l->product?->sku, 'nameAr' => $l->product?->name_ar, 'need' => $l->qty - $l->reserved_qty])->values()->all();

            return $this->getOrder($after->id) + ['allocation' => ['complete' => ! $missing, 'gained' => null, 'missing' => $missing]];
        }
        $txId = $this->inventory->newTxId();
        $r = DB::transaction(function () use ($user, $idOrNumber, $txId) {
            $so = $this->findOrder($idOrNumber, self::allocWith(), true);

            return ['id' => $so->id, 'number' => $so->number] + $this->allocateLocked($user, $so, $txId);
        });
        if (! $r['complete'] && $r['gained'] === 0) {
            $m = $r['missing'][0];
            throw AppError::rule('INSUFFICIENT_AVAILABLE', "لا رصيد متاح الآن لـ {$m['nameAr']} (ينقص {$m['need']}) — يبقى {$r['number']} بانتظار المخزون",
                "Nothing available yet for {$m['sku']} (short by {$m['need']}) — {$r['number']} keeps waiting", ['sku' => $m['sku'], 'available' => 0, 'requested' => $m['need'], 'missing' => $r['missing']]);
        }

        return $this->getOrder($r['id']) + ['allocation' => ['complete' => $r['complete'], 'gained' => $r['gained'], 'missing' => $r['missing']]];
    }

    /**
     * Stock arrived (or was released): our orders that wait for it are tried oldest first, each in its own transaction.
     * `$limit` bounds the orders actually attempted — an order none of whose products has stock is skipped cheaply, so a
     * long queue of unfillable orders cannot starve the ones behind it. Orders of other systems are topped up by the
     * integration layer (OrderIntakeService::topUp). Run by `scm:backorders-allocate`.
     *
     * @return array{checked:int, tried:int, completed:int, partial:int, orders:string[]}
     */
    public function retryBackorders(int $limit = 50, ?AuthUser $user = null): array
    {
        $out = ['checked' => 0, 'tried' => 0, 'completed' => 0, 'partial' => 0, 'orders' => []];
        $available = []; // "warehouse:product" → units free right now (re-read after every reservation that touches it)
        $free = function (string $warehouseId, string $productId) use (&$available): int {
            return $available["{$warehouseId}:{$productId}"] ??= (int) $this->inventory->allocRows($productId, $warehouseId)->sum(fn ($r) => $r->on_hand - $r->reserved);
        };
        // the queue is read slim and far beyond the attempt budget (at least 500 orders): skipping costs one cached lookup
        $waiting = SalesOrder::with(['lines:id,so_id,product_id,qty,reserved_qty'])->whereNull('source_system')->where('status', 'confirmed')
            ->orderBy('created_at')->orderBy('id')->limit(max(500, $limit * 40))->get(['id', 'number', 'warehouse_id']);
        foreach ($waiting as $row) {
            if ($out['tried'] >= $limit) {
                break;
            }
            $out['checked']++;
            $needs = $row->lines->filter(fn ($l) => $l->qty > $l->reserved_qty);
            if ($needs->isEmpty() || ! $needs->contains(fn ($l) => $free($row->warehouse_id, $l->product_id) > 0)) {
                continue;
            }
            $out['tried']++;
            $txId = $this->inventory->newTxId();
            $r = DB::transaction(function () use ($row, $user, $txId) {
                $so = $this->findOrder($row->id, self::allocWith(), true);
                if ($so->status !== 'confirmed' || $so->lines->every(fn ($l) => $l->qty <= 0)) {
                    return null; // someone else got there first, or nothing is left on the order
                }
                $r = $this->allocateLocked($user, $so, $txId);
                if ($r['complete']) {
                    $this->notify->activity($user, 'SalesOrder', $so->id, $so->number, "وصل المخزون — اكتمل حجز الطلب المعلّق {$so->number} وأصبح جاهزًا للتنفيذ",
                        "Stock arrived — waiting order {$so->number} is fully reserved", ['sales', 'wm']);
                }

                return $r;
            });
            foreach ($needs as $l) {
                unset($available["{$row->warehouse_id}:{$l->product_id}"]);
            }
            if ($r && $r['complete']) {
                $out['completed']++;
                $out['orders'][] = $row->number;
            } elseif ($r && $r['gained'] > 0) {
                $out['partial']++;
            }
        }

        return $out;
    }

    /**
     * Create the fulfillment order (execution document) + pick list from allocations. Idempotent per SO.
     * Joins the caller's transaction when there is one (consolidation → readypick).
     */
    public function fulfill(AuthUser $user, string $idOrNumber): FulfillmentOrder
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $so = $this->findOrder($idOrNumber, ['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,storage_class', 'lines.allocations' => fn ($q) => $q->where('status', 'active')->orderBy('created_at')->orderBy('id'), 'lines.allocations.batch', 'fos', 'customer'], true);
            $existing = $so->fos->first(fn ($f) => $f->status !== 'cancelled');
            if ($existing) {
                return $existing;
            }
            if ($so->status !== 'allocated') {
                throw $so->status === 'confirmed'
                    ? AppError::rule('SO_NOT_ALLOCATED', "أمر البيع {$so->number} ما زال بانتظار المخزون — خصّصه أولًا", 'Order is still waiting for stock')
                    : AppError::rule('SO_NOT_ALLOCATED', 'أمر البيع غير مخصص — لا يمكن إنشاء أمر تنفيذ', 'Order not allocated');
            }
            // a line whose whole quantity went to a backorder (qty 0) ships nothing: it gets no execution line
            $lines = $so->lines->filter(fn ($l) => $l->qty > 0)->values();
            $number = $this->numbering->next('FO');
            $fo = FulfillmentOrder::create([
                'number' => $number, 'so_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id, 'status' => 'alloc',
                'cartons' => (int) $lines->sum('qty'), 'weight_kg' => $so->kg, 'cbm' => $so->cbm, 'zone_ar' => $so->customer->zone, 'zone_en' => $so->customer->zone,
            ]);
            $foLineBySoLine = [];
            foreach ($lines as $l) {
                $foLineBySoLine[$l->id] = FoLine::create(['fo_id' => $fo->id, 'line_no' => $l->line_no, 'so_line_id' => $l->id, 'product_id' => $l->product_id, 'qty' => $l->qty])->id;
            }
            $pl = PickList::create(['number' => $this->numbering->next('PL'), 'warehouse_id' => $so->warehouse_id, 'fo_id' => $fo->id]);

            // pick tasks: one per allocation. The walk goes by temperature first — ambient / hazmat, then chilled, frozen
            // last, so cold goods spend the least time outside their zone — then zone → aisle → rack → bin.
            $bins = Bin::with(['zone', 'rack'])->whereIn('id', $lines->flatMap(fn ($l) => $l->allocations->pluck('bin_id'))->unique()->all())->get()->keyBy('id');
            $tasks = [];
            foreach ($lines as $l) {
                foreach ($l->allocations as $al) {
                    $qty = $al->qty - $al->picked_qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $bin = $bins->get($al->bin_id) ?? Bin::with(['zone', 'rack'])->findOrFail($al->bin_id);
                    $temp = max(self::TEMP_RANK[$bin->zone->type] ?? 0, self::TEMP_RANK[$l->product?->storage_class] ?? 0);
                    $tasks[] = [
                        'fo_line_id' => $foLineBySoLine[$l->id], 'product_id' => $l->product_id, 'bin_id' => $al->bin_id, 'batch_id' => $al->batch_id,
                        'batch_no' => $al->batch?->batch_no, 'qty' => $qty,
                        'key' => [(string) $temp, $bin->zone->code, str_pad((string) ($bin->rack?->aisle ?: 0), 2, '0', STR_PAD_LEFT), str_pad((string) ($bin->rack?->rack_no ?: 0), 3, '0', STR_PAD_LEFT), $bin->code],
                    ];
                }
            }
            usort($tasks, function ($a, $b) {
                foreach ($a['key'] as $i => $part) {
                    if (($cmp = strcmp($part, $b['key'][$i])) !== 0) {
                        return $cmp;
                    }
                }

                return 0;
            });
            foreach ($tasks as $i => $tk) {
                unset($tk['key']);
                PickTask::create($tk + ['pick_list_id' => $pl->id, 'seq' => $i + 1]);
            }
            $so->update(['status' => 'preparing']);
            $this->audit->status($user, 'SalesOrder', $so->id, $so->number, 'allocated', 'preparing', "FO {$number} · {$pl->number}");
            app(OrderEvents::class)->emit($so->id, 'order.released', ['fulfilmentOrder' => $number, 'pickList' => $pl->number]);
            $this->audit->log($user, ['action' => 'FO.CREATE', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $number,
                'newValue' => ['so' => $so->number, 'pickList' => $pl->number, 'tasks' => count($tasks)]]);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $number, "أُنشئ أمر التنفيذ {$number} وقائمة التجهيز {$pl->number} لـ {$so->number}", null, ['wm', 'worker']);

            return $fo->refresh()->load(['lines' => fn ($q) => $q->orderBy('line_no')]);
        });
    }

    // ───────────── consolidation ─────────────

    /** @param  array{status?:?string, warehouse?:?string}  $filters */
    public function listConsolidations(Paging $page, array $filters): array
    {
        $query = OrderConsolidation::with(['warehouse', 'orders' => fn ($q) => $q->orderBy('number'), 'orders.customer', 'orders.fos', 'trips'])->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }

        return $page->paginate($query, fn (OrderConsolidation $x) => Shape::apply($x->toArray(), self::OC_LIST_SHAPE));
    }

    public function getConsolidation(string $idOrNumber): array
    {
        $x = $this->findConsolidation($idOrNumber);

        return Shape::apply($x->toArray(), self::OC_SHAPE) + ['history' => $this->history('OrderConsolidation', $x->id), 'actions' => $this->batchActions($x)];
    }

    /** @param  array{orderNumbers:string[], rule?:?string}  $dto */
    public function createConsolidation(AuthUser $user, array $dto): array
    {
        $numbers = $dto['orderNumbers'];
        if (count($numbers) < 2) {
            throw AppError::validation('OC_MIN', 'اختر طلبين على الأقل للتجميع', 'Select at least two orders');
        }
        $sos = SalesOrder::whereIn('number', $numbers)->get();
        if ($sos->count() !== count($numbers)) {
            throw AppError::notFound('SO_NOT_FOUND', 'أحد الطلبات غير موجود', 'One of the orders was not found');
        }
        if ($sos->pluck('warehouse_id')->unique()->count() > 1) {
            throw AppError::rule('OC_WAREHOUSE', 'مرفوض: الطلبات من مستودعات مختلفة — التجميع داخل مستودع واحد', 'Orders must be from one warehouse');
        }
        if ($sos->contains(fn ($s) => ! in_array($s->status, ['confirmed', 'allocated'], true) || $s->consolidation_id)) {
            throw AppError::rule('OC_STATE', 'مرفوض: كل الطلبات يجب أن تكون بحالة «مؤكد/مخصص» وغير مجمّعة', 'All orders must be confirmed/allocated and not consolidated');
        }

        $id = DB::transaction(function () use ($user, $dto, $numbers, $sos) {
            $number = $this->numbering->next('OC');
            $oc = OrderConsolidation::create(['number' => $number, 'warehouse_id' => $sos[0]->warehouse_id, 'rule' => ($dto['rule'] ?? null) ?: 'المنطقة + تاريخ التسليم', 'created_by_id' => $user->id, 'created_by' => $user->username]);
            SalesOrder::whereIn('id', $sos->pluck('id')->all())->update(['consolidation_id' => $oc->id]);
            $this->audit->log($user, ['action' => 'OC.CREATE', 'entityType' => 'OrderConsolidation', 'entityId' => $oc->id, 'entityNumber' => $number, 'newValue' => ['orders' => $numbers, 'rule' => $dto['rule'] ?? null]]);
            $this->audit->status($user, 'OrderConsolidation', $oc->id, $number, null, 'open');
            $customers = $sos->pluck('customer_id')->unique()->count();
            $this->notify->activity($user, 'OrderConsolidation', $oc->id, $number, "أُنشئت دفعة تجميع {$number} من {$sos->count()} طلبات · {$customers} عملاء", null, ['wm', 'disp']);

            return $oc->id;
        });

        return $this->getConsolidation($id);
    }

    /**
     * Add: only while the batch is open / consolidating, an order of the same warehouse that is `confirmed | allocated`
     * and in no batch. Remove: an order of THIS batch, in any state before picking — and later too when the order has no
     * live fulfilment order any more (it went back to waiting or was cancelled), so the batch can go on without it.
     */
    public function addRemoveOrder(AuthUser $user, string $idOrNumber, string $orderNumber, bool $add, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($user, $idOrNumber, $orderNumber, $add, $reason) {
            $oc = OrderConsolidation::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('OC_NOT_FOUND', 'دفعة التجميع غير موجودة', 'Consolidation batch not found');
            if ($add && ! in_array($oc->status, self::OC_OPEN, true)) {
                throw AppError::rule('OC_LOCKED', 'لا يمكن إضافة طلبات إلى الدفعة بعد بدء التجهيز', 'Orders cannot join the batch once picking was prepared');
            }
            $so = SalesOrder::with('fos')->where(fn ($w) => $w->where('number', $orderNumber)->orWhere('id', $orderNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('SO_NOT_FOUND', 'الطلب غير موجود', 'Order not found');
            if ($add) {
                if ($so->warehouse_id !== $oc->warehouse_id) {
                    throw AppError::rule('OC_WAREHOUSE', 'مستودع مختلف — التجميع داخل مستودع واحد', 'Different warehouse');
                }
                if ($so->consolidation_id === $oc->id) {
                    throw AppError::rule('OC_IN_BATCH', "الطلب {$so->number} موجود في هذه الدفعة", "{$so->number} is already in this batch");
                }
                if ($so->consolidation_id) {
                    $other = OrderConsolidation::whereKey($so->consolidation_id)->value('number');
                    throw AppError::rule('OC_IN_BATCH', "الطلب {$so->number} في دفعة أخرى ({$other}) — أزله منها أولًا", "{$so->number} belongs to another batch ({$other})");
                }
                if (! in_array($so->status, ['confirmed', 'allocated'], true)) {
                    throw AppError::rule('OC_STATE', "الطلب {$so->number} بحالة «{$so->status}» — يُضاف إلى الدفعة المؤكد أو المخصص فقط", "{$so->number} is {$so->status}: only confirmed / allocated orders can join");
                }
            } else {
                if ($so->consolidation_id !== $oc->id) {
                    throw AppError::rule('OC_NOT_MEMBER', "الطلب {$so->number} ليس في الدفعة {$oc->number}", "{$so->number} is not in batch {$oc->number}");
                }
                if (! in_array($oc->status, self::OC_BEFORE_PICKING, true) && self::liveFo($so)) {
                    throw AppError::rule('OC_LOCKED', "بدأ تجهيز {$so->number} ضمن الدفعة — لا يُزال إلا إذا أُعيد للانتظار (إرجاع للرف) أو أُلغي", "{$so->number} is being fulfilled in this batch — it can leave only after a put-back or a cancellation");
                }
            }
            $so->update(['consolidation_id' => $add ? $oc->id : null]);
            $this->audit->log($user, ['action' => $add ? 'OC.ADD' : 'OC.REMOVE', 'entityType' => 'OrderConsolidation', 'entityId' => $oc->id, 'entityNumber' => $oc->number,
                'newValue' => $reason ? ['order' => $so->number, 'reason' => $reason] : $so->number]);
            if (! $add) {
                $this->notify->activity($user, 'OrderConsolidation', $oc->id, $oc->number, "أُزيل {$so->number} من دفعة التجميع {$oc->number}".($reason ? " — {$reason}" : ''), "{$so->number} removed from batch {$oc->number}", ['sales']);
            }

            return $oc->id;
        });

        return $this->getConsolidation($id);
    }

    /**
     * Dissolve a batch: its orders go back to the pool and the batch document is removed (its audit trail stays).
     * Allowed while open / consolidating, and at any stage for a batch that has no live order left.
     *
     * @return array{ok:bool, number:string, released:string[]}
     */
    public function cancelConsolidation(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $oc = OrderConsolidation::with(['orders' => fn ($q) => $q->orderBy('number')])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('OC_NOT_FOUND', 'دفعة التجميع غير موجودة', 'Consolidation batch not found');
            $why = $this->cancelBlock($oc);
            if ($why) {
                throw AppError::rule($why[0], $why[1], $why[2]);
            }
            $numbers = $oc->orders->pluck('number')->all();
            SalesOrder::where('consolidation_id', $oc->id)->update(['consolidation_id' => null]);
            Trip::where('consolidation_id', $oc->id)->update(['consolidation_id' => null]);
            $this->audit->log($user, ['action' => 'OC.CANCEL', 'entityType' => 'OrderConsolidation', 'entityId' => $oc->id, 'entityNumber' => $oc->number,
                'oldValue' => ['status' => $oc->status, 'orders' => $numbers, 'rule' => $oc->rule], 'newValue' => ['reason' => $reason]]);
            $this->audit->status($user, 'OrderConsolidation', $oc->id, $oc->number, $oc->status, 'cancelled', $reason);
            $this->notify->activity($user, 'OrderConsolidation', $oc->id, $oc->number, "أُلغيت دفعة التجميع {$oc->number}".($numbers ? ' — عادت الطلبات '.implode('، ', $numbers).' إلى قائمة التجميع' : '').($reason ? " — {$reason}" : ''),
                "Batch {$oc->number} cancelled".($numbers ? ' — its orders are back in the pool' : ''), ['sales', 'wm']);
            $oc->delete();

            return ['ok' => true, 'number' => $oc->number, 'released' => $numbers];
        });
    }

    /**
     * Advance the consolidation batch. Cancelled orders leave it first. `readypick` reserves once more for any order that
     * still waits, then creates fulfillment orders + pick lists for every order; later stages are validated against the
     * fulfilment orders (a partial or failed delivery counts as finished).
     */
    public function advanceConsolidation(AuthUser $user, string $idOrNumber): array
    {
        $oc = $this->findConsolidation($idOrNumber);
        $next = config('scm.OC_NEXT')[$oc->status] ?? null;
        if (! $next) {
            throw AppError::rule('OC_DONE', 'الدفعة مكتملة', 'The batch is complete');
        }
        // committed on its own: the advance below may still be refused, the cancelled orders are gone either way
        if ($this->detachCancelled($user, $oc)) {
            $oc = $this->findConsolidation($oc->id);
        }
        if ($oc->orders->isEmpty()) {
            throw AppError::rule('OC_EMPTY', "لا طلبات قائمة في الدفعة {$oc->number} — ألغِ الدفعة", "Batch {$oc->number} has no live order — cancel it");
        }
        $txId = $this->inventory->newTxId();

        DB::transaction(function () use ($user, $oc, $next, $txId) {
            if (OrderConsolidation::whereKey($oc->id)->lockForUpdate()->value('status') !== $oc->status) {
                throw AppError::conflict('OC_CHANGED', 'تغيّرت حالة الدفعة — حدّث الصفحة', 'The batch changed — refresh the page');
            }
            $numbers = fn (array $list) => implode('، ', $list);
            if ($next === 'readypick') {
                $waiting = [];
                foreach ($oc->orders as $so) {
                    if ($so->status !== 'confirmed') {
                        continue;
                    }
                    try {
                        $complete = $this->allocateLocked($user, $this->findOrder($so->id, self::allocWith(), true), $txId)['complete'];
                    } catch (AppError $e) {
                        if ($e->errorCode !== 'SO_EMPTY') {
                            throw $e;
                        }
                        $complete = false;
                    }
                    if (! $complete) {
                        $waiting[] = $so->number;
                    }
                }
                if ($waiting) {
                    throw AppError::rule('OC_UNALLOCATED', "ما زال بانتظار المخزون: {$numbers($waiting)} — أزله من الدفعة للمتابعة بدونه، أو انتظر التوريد ثم أعد المحاولة",
                        "Still waiting for stock: {$numbers($waiting)} — remove it from the batch to go on without it, or retry after the stock arrives", ['orders' => $waiting]);
                }
                foreach ($oc->orders as $so) {
                    $this->fulfill($user, $so->id);
                }
            }
            $fos = FulfillmentOrder::whereIn('so_id', $oc->orders->pluck('id')->all())->where('status', '!=', 'cancelled')->orderBy('created_at')->orderBy('id')->get();
            $foOf = $fos->keyBy('so_id');
            /** orders whose fulfilment order is not in one of $states (an order with no live fulfilment order counts as late) */
            $late = fn (array $states) => $oc->orders->filter(fn ($o) => ! in_array($foOf->get($o->id)?->status, $states, true))->pluck('number')->values()->all();
            if (in_array($next, ['packed', 'readydisp', 'dispatched'], true) && ($list = $late(self::FO_PACKED))) {
                throw AppError::rule('OC_NOT_PACKED', "لم تكتمل تعبئة: {$numbers($list)} — أكمل تجهيزها، أو أزل من الدفعة ما عاد للانتظار", "Not packed yet: {$numbers($list)} — finish them, or remove the ones that went back to waiting", ['orders' => $list]);
            }
            if ($next === 'dispatched' && ($list = $fos->filter(fn ($f) => ! $f->trip_id && ! in_array($f->status, self::FO_FINISHED, true))->pluck('number')->values()->all())) {
                throw AppError::rule('OC_NO_TRIP', "أنشئ رحلة تضم كل أوامر التنفيذ أولًا (التخطيط → رحلة) — بلا رحلة: {$numbers($list)}", "Create a trip with all fulfillment orders first — without a trip: {$numbers($list)}", ['fulfilmentOrders' => $list]);
            }
            if ($next === 'done' && ($list = $late(self::FO_FINISHED))) {
                throw AppError::rule('OC_NOT_DELIVERED', "لم يكتمل تسليم: {$numbers($list)}", "Not delivered yet: {$numbers($list)}", ['orders' => $list]);
            }
            $tripId = $fos->first(fn ($f) => $f->trip_id)?->trip_id;
            $from = $oc->status;
            $oc->update(['status' => $next, 'trip_id' => $next === 'dispatched' ? $tripId : $oc->trip_id]);
            $this->audit->status($user, 'OrderConsolidation', $oc->id, $oc->number, $from, $next);
            $this->notify->activity($user, 'OrderConsolidation', $oc->id, $oc->number, "دفعة التجميع {$oc->number} → {$next} — {$oc->orders->count()} أوامر بيع", null, ['sales']);
        });

        return $this->getConsolidation($oc->id);
    }

    // ───────────── internals ─────────────

    /**
     * @param  iterable<int, mixed>  $lines  models (qty, price, disc_pct) or resolved arrays (qty, price, discPct)
     * @return array{sub:float, vatPct:int|float, vat:float, total:float}
     */
    private function totals(iterable $lines): array
    {
        $vat = $this->settings->get('sales.vatPct');
        $sub = 0.0;
        foreach ($lines as $l) {
            $disc = is_array($l) ? ($l['discPct'] ?? 0) : $l->disc_pct;
            $sub += (is_array($l) ? $l['qty'] : $l->qty) * (float) (is_array($l) ? $l['price'] : $l->price) * (1 - ((float) $disc ?: 0) / 100);
        }

        return ['sub' => round($sub * 100) / 100, 'vatPct' => $vat, 'vat' => round($sub * $vat) / 100, 'total' => round($sub * (1 + $vat / 100) * 100) / 100];
    }

    /** Totals + the two facts every quotation screen needs: where its discount stands and whether it is still valid. */
    private function quotationFacts(Quotation $q): array
    {
        $open = in_array($q->status, self::QT_OPEN, true);
        $days = $q->valid_until ? (int) Carbon::today()->diffInDays($q->valid_until->copy()->startOfDay(), false) : null;

        return [
            'totals' => $this->totals($q->lines),
            'discount' => $this->discountState($q->lines, $q->discount_approved_by),
            'validity' => ['expired' => $q->status === 'expired' || ($open && $days !== null && $days < 0), 'daysLeft' => $open ? $days : null],
        ];
    }

    /**
     * Where a document stands against the discount rule.
     *
     * @param  iterable<int, mixed>  $lines  models (disc_pct) or resolved arrays (discPct)
     * @return array{maxPct:float, linePct:float, aboveMax:bool, approvedBy:?string, needsApproval:bool}
     */
    private function discountState(iterable $lines, ?string $approvedBy): array
    {
        $max = (float) $this->settings->get('sales.maxDiscountPct');
        $top = 0.0;
        foreach ($lines as $l) {
            $top = max($top, (float) (is_array($l) ? ($l['discPct'] ?? 0) : $l->disc_pct));
        }

        return ['maxPct' => $max, 'linePct' => $top, 'aboveMax' => $top > $max, 'approvedBy' => $approvedBy, 'needsApproval' => $top > $max && ! $approvedBy];
    }

    private function discountRefusal(array $disc, string $howAr, string $howEn): AppError
    {
        $pct = self::fmt($disc['linePct']);
        $max = self::fmt($disc['maxPct']);

        return AppError::rule('DISCOUNT_APPROVAL', "الخصم {$pct}% فوق الحد {$max}% ويحتاج اعتماد صاحب صلاحية اعتماد الخصم — {$howAr}",
            "A {$pct}% discount is above the {$max}% limit and needs discount approval — {$howEn}", ['discountPct' => $disc['linePct'], 'maxPct' => $disc['maxPct']]);
    }

    /** Role keys that hold a permission in the database — who is told that an approval is waiting. */
    private function rolesWith(string $permission): array
    {
        return DB::table('roles')->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('permissions.key', $permission)->pluck('roles.key')->unique()->values()->all();
    }

    /**
     * A quotation past its validity date is not sent, approved or converted: it is moved to `expired` (committed before
     * the refusal, so the rejection does not roll it back) and the caller gets QT_EXPIRED.
     */
    private function refuseExpired(?AuthUser $user, Quotation $q): void
    {
        if ($q->status !== 'expired') {
            $past = $q->valid_until && $q->valid_until->copy()->startOfDay()->lt(Carbon::today());
            if (! in_array($q->status, self::QT_OPEN, true) || ! $past) {
                return;
            }
            $this->expire($user, $q);
        }
        $until = $q->valid_until?->toDateString() ?? '—';
        throw AppError::rule('QT_EXPIRED', "انتهت صلاحية عرض السعر {$q->number} في {$until} — مدّد الصلاحية أولًا", "Quotation {$q->number} expired on {$until} — extend its validity first", ['validUntil' => $until]);
    }

    /** open → expired, audited. Returns false when someone else already moved the quotation on. */
    private function expire(?AuthUser $user, Quotation $q): bool
    {
        return DB::transaction(function () use ($user, $q) {
            $from = Quotation::whereKey($q->id)->lockForUpdate()->value('status');
            if (! in_array($from, self::QT_OPEN, true)) {
                return false;
            }
            $q->update(['status' => 'expired']);
            $until = $q->valid_until?->toDateString();
            $this->audit->status($user, 'Quotation', $q->id, $q->number, $from, 'expired', "انتهت الصلاحية في {$until}");
            $this->notify->activity($user, 'Quotation', $q->id, $q->number, "انتهت صلاحية عرض السعر {$q->number} ({$until}) — مدّد الصلاحية لإعادته مسودة", "Quotation {$q->number} expired on {$until}", ['sales']);

            return true;
        });
    }

    /**
     * Reserves what a waiting order still needs from what is available now — never throws for a shortage.
     * Caller: inside a transaction, order row locked, lines loaded with their product.
     *
     * @return array{complete:bool, gained:int, missing:array<int, array{sku:?string, nameAr:?string, need:int}>}
     */
    private function allocateLocked(?AuthUser $user, SalesOrder $so, string $txId): array
    {
        if ($so->status !== 'confirmed') {
            throw AppError::rule('SO_NOT_WAITING', 'الأمر ليس بانتظار التخصيص', 'Order is not waiting allocation');
        }
        $lines = $so->lines->filter(fn ($l) => $l->qty > 0);
        if ($lines->isEmpty()) {
            throw AppError::rule('SO_EMPTY', "لا كمية باقية على {$so->number} — كل ما طُلب نُقل إلى طلب معلّق أو أُلغي؛ ألغِ الأمر", "Nothing is left on {$so->number} — cancel it");
        }
        $gained = 0;
        $missing = [];
        foreach ($lines as $sl) {
            $need = $sl->qty - $sl->reserved_qty;
            $got = $this->reserveUpTo($user, $txId, $so, $sl, $need);
            $gained += $got;
            if ($got < $need) {
                $missing[] = ['sku' => $sl->product?->sku, 'nameAr' => $sl->product?->name_ar, 'need' => $need - $got];
            }
        }
        if (! $missing) {
            $so->update(['status' => 'allocated', 'fulfil_status' => 'full']);
            $this->audit->status($user, 'SalesOrder', $so->id, $so->number, 'confirmed', 'allocated', 'حجز FEFO', $txId);
        } elseif ($gained > 0) {
            $so->update(['fulfil_status' => 'partial']);
            $this->audit->log($user, ['action' => 'SO.ALLOCATE_PARTIAL', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $so->number,
                'newValue' => ['reserved' => $gained, 'missing' => $missing], 'transactionId' => $txId]);
        }

        return ['complete' => ! $missing, 'gained' => $gained, 'missing' => $missing];
    }

    /** Reserves min(available, $qty) for one line; returns what was reserved. */
    private function reserveUpTo(?AuthUser $user, string $txId, SalesOrder $so, SalesOrderLine $sl, int $qty): int
    {
        if ($qty <= 0) {
            return 0;
        }
        $available = (int) $this->inventory->allocRows($sl->product_id, $so->warehouse_id, true)->sum(fn ($r) => $r->on_hand - $r->reserved);
        $take = min($qty, $available);
        if ($take > 0) {
            $this->inventory->reserve($user, $txId, ['soId' => $so->id, 'soLineId' => $sl->id, 'productId' => $sl->product_id, 'warehouseId' => $so->warehouse_id, 'qty' => $take, 'referenceNumber' => $so->number]);
        }

        return $take;
    }

    /**
     * What a waiting order still needs, line by line, next to what is free in its warehouse right now.
     *
     * @return array<int, array{sku:?string, nameAr:?string, nameEn:?string, need:int, available:int}>
     */
    private function missingOf(SalesOrder $so): array
    {
        $out = [];
        foreach ($so->lines as $l) {
            $need = $l->qty - $l->reserved_qty;
            if ($need > 0) {
                $out[] = ['sku' => $l->product?->sku, 'nameAr' => $l->product?->name_ar, 'nameEn' => $l->product?->name_en, 'need' => $need,
                    'available' => (int) $this->inventory->allocRows($l->product_id, $so->warehouse_id)->sum(fn ($r) => $r->on_hand - $r->reserved)];
            }
        }

        return $out;
    }

    /**
     * Weight / volume of an order from its resolved lines and the quantity each one keeps.
     *
     * @param  array<int, array{product:Product}>  $lines
     * @param  array<int, int>  $qty  same keys as $lines
     * @return array{kg:float, cbm:float}
     */
    private static function weight(array $lines, array $qty): array
    {
        $kg = 0.0;
        foreach ($lines as $i => $l) {
            $kg += $qty[$i] * ($l['product']->weight_kg ?: 12);
        }

        return ['kg' => round($kg), 'cbm' => round($kg / 200 * 10) / 10];
    }

    /**
     * A line sent WITH a price keeps it — the price a person typed always wins. A line sent without one takes the price
     * of the customer's price list (the «قوائم الأسعار» section decides which list and which rule); when the list has no
     * price for the product the line is refused, by its index, and the person types it.
     *
     * @param  array<int, array{sku:string, qty:int|string, price?:float|int|string|null, discPct?:float|int|string|null}>  $lines
     * @return array<int, array{lineNo:int, product:Product, qty:int, price:float, discPct:float}>
     */
    private function resolveLines(array $lines, ?Customer $customer = null): array
    {
        $products = Product::whereIn('sku', array_column($lines, 'sku'))->get()->keyBy('sku');
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $p = $products->get($l['sku']) ?? throw AppError::validation('SKU_NOT_FOUND', "المنتج {$l['sku']} غير موجود", "Unknown SKU {$l['sku']}");
            if (! $p->active) {
                throw AppError::validation('SKU_INACTIVE', "المنتج {$p->name_ar} موقوف", "Product {$p->sku} is inactive");
            }
            $price = ($l['price'] ?? null) === null || $l['price'] === '' ? $this->listPrice($customer, $p, (int) $l['qty'], $i) : (float) $l['price'];
            $out[] = ['lineNo' => $i + 1, 'product' => $p, 'qty' => (int) $l['qty'], 'price' => $price, 'discPct' => (float) ($l['discPct'] ?? 0)];
        }

        return $out;
    }

    /** The customer's list price of a product, for a line that carries none (see resolveLines). */
    private function listPrice(?Customer $customer, Product $p, int $qty, int $index): float
    {
        // the price lists arrive with their own migration: a database that does not have them yet has no list price
        $answer = $customer && Schema::hasTable('price_lists') ? app(PriceService::class)->customerPrice((string) $customer->id, (string) $p->id, max(1, $qty)) : null;
        if (! ($answer['found'] ?? false) || (float) $answer['price'] <= 0) {
            throw AppError::validation('PRICE_REQUIRED', "لا سعر للمنتج {$p->sku} في قائمة أسعار العميل — اكتب سعر السطر",
                "No price for {$p->sku} in the customer's price list — type the line price", ['field' => "lines.{$index}.price", 'sku' => $p->sku]);
        }

        return round((float) $answer['price'], 2);
    }

    /** Lines of a quotation as an order/quotation payload. */
    private function linesOf(Quotation $q): array
    {
        return $q->lines->map(fn ($l) => ['sku' => $l->product->sku, 'qty' => $l->qty, 'price' => (float) $l->price, 'discPct' => $l->disc_pct])->all();
    }

    private function customer(string $code): Customer
    {
        $c = Customer::where('code', $code)->first();
        if (! $c || ! $c->active) {
            throw AppError::notFound('CUSTOMER_NOT_FOUND', "العميل {$code} غير موجود أو موقوف", "Customer {$code} not found or inactive");
        }

        return $c;
    }

    private function findQuotation(string $idOrNumber): Quotation
    {
        return Quotation::with(['customer', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product.baseUom', 'so'])
            ->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود', 'Quotation not found');
    }

    /** $lock = SELECT … FOR UPDATE on the order row (call inside a transaction) so concurrent actions on one order serialise. */
    private function findOrder(string $idOrNumber, array $with, bool $lock = false): SalesOrder
    {
        return SalesOrder::with($with)->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('SO_NOT_FOUND', 'أمر البيع غير موجود', 'Sales order not found');
    }

    private function findConsolidation(string $idOrNumber): OrderConsolidation
    {
        return OrderConsolidation::with(['warehouse', 'orders' => fn ($q) => $q->orderBy('number'), 'orders.customer', 'orders.lines' => fn ($q) => $q->orderBy('line_no'), 'orders.lines.product', 'orders.fos', 'trips'])
            ->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('OC_NOT_FOUND', 'دفعة التجميع غير موجودة', 'Consolidation batch not found');
    }

    /** Cancelled orders leave their batch, audited. Returns their numbers. */
    private function detachCancelled(AuthUser $user, OrderConsolidation $oc): array
    {
        $gone = $oc->orders->where('status', 'cancelled');
        if ($gone->isEmpty()) {
            return [];
        }
        $numbers = $gone->pluck('number')->values()->all();
        DB::transaction(function () use ($user, $oc, $gone, $numbers) {
            SalesOrder::whereIn('id', $gone->pluck('id')->all())->where('consolidation_id', $oc->id)->update(['consolidation_id' => null]);
            $this->audit->log($user, ['action' => 'OC.DETACH', 'entityType' => 'OrderConsolidation', 'entityId' => $oc->id, 'entityNumber' => $oc->number,
                'newValue' => ['orders' => $numbers, 'reason' => 'cancelled']]);
            $this->notify->activity($user, 'OrderConsolidation', $oc->id, $oc->number, 'خرجت من دفعة التجميع '.$oc->number.' الطلبات الملغاة: '.implode('، ', $numbers),
                "Cancelled orders left batch {$oc->number}: ".implode(', ', $numbers));
        });

        return $numbers;
    }

    /** What the batch screen may offer right now; the endpoints enforce the same rules. */
    private function batchActions(OrderConsolidation $oc): array
    {
        $beforePicking = in_array($oc->status, self::OC_BEFORE_PICKING, true);
        $why = $this->cancelBlock($oc);

        return [
            'canAdd' => in_array($oc->status, self::OC_OPEN, true),
            'removable' => $oc->orders->filter(fn ($o) => $beforePicking || ! self::liveFo($o))->pluck('number')->values()->all(),
            'canCancel' => $why === null, 'cancelReasonAr' => $why[1] ?? null, 'cancelReasonEn' => $why[2] ?? null,
        ];
    }

    /** @return array{0:string, 1:string, 2:string}|null  why the batch cannot be dissolved: [code, ar, en] */
    private function cancelBlock(OrderConsolidation $oc): ?array
    {
        if ($oc->status === 'done') {
            return ['OC_DONE', 'الدفعة مكتملة', 'The batch is complete'];
        }
        if (! in_array($oc->status, self::OC_OPEN, true) && $oc->orders->contains(fn ($o) => $o->status !== 'cancelled')) {
            return ['OC_LOCKED', "بدأ تجهيز الدفعة {$oc->number} — لا تُلغى ما دام فيها طلب قائم؛ أزل ما يمكن إزالته أو أكمل التنفيذ", "Batch {$oc->number} is being fulfilled — it can be cancelled only when no live order is left in it"];
        }

        return null;
    }

    /** The order has a fulfilment order that is not cancelled (needs `fos` loaded). */
    private static function liveFo(SalesOrder $so): bool
    {
        return $so->fos->contains(fn ($f) => $f->status !== 'cancelled');
    }

    /** Eager loads of the sales-order document (lines by line number, active allocations in FEFO/creation order). */
    private static function soWith(): array
    {
        return [
            'customer', 'warehouse', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product',
            'lines.allocations' => fn ($q) => $q->where('status', 'active')->orderBy('created_at')->orderBy('id'), 'lines.allocations.bin', 'lines.allocations.batch',
            'fos' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'fos.trip', 'quotation', 'consolidation',
            'backorderOf', 'backorders' => fn ($q) => $q->orderBy('created_at')->orderBy('id'),
        ];
    }

    /** Eager loads allocateLocked() needs. */
    private static function allocWith(): array
    {
        return ['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product:id,sku,name_ar,name_en'];
    }

    private function history(string $entityType, string $entityId): array
    {
        return StatusHistory::where('entity_type', $entityType)->where('entity_id', $entityId)->orderBy('at')->orderBy('id')->get()->map->toArray()->all();
    }

    private static function truthy(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'true'], true);
    }

    /** 236000.5 → "236,000.5" (JS toLocaleString('en-US')). */
    private static function fmt(float $n): string
    {
        $s = number_format($n, 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
