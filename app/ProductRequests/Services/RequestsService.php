<?php

namespace App\ProductRequests\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Support\Flow;
use App\ProductRequests\Support\NameKey;
use App\ProductRequests\Support\Owned;
use App\ProductRequests\Support\People;
use App\ProductRequests\Support\Shape;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * New-product requests: the way a product enters the catalogue.
 *
 * WHO DOES WHAT. `page.preq` opens the section and reads it. `preq.create` raises requests and works one's OWN
 * requests (edit while it is a draft or was sent back, send, cancel). `preq.review` works ANY request that was sent:
 * take it into review, correct its values, send it back with what to correct, approve it — the product is then
 * created in the catalogue through `ProductsService::create` and linked — or reject it with a reason. Somebody else's
 * draft is not a request yet: only its author and the reviewers see it.
 *
 * SEGREGATION. Whoever raised a request does not approve it himself, unless the policy `preq.allowSelfApproval` is
 * switched on (the house rule of the purchase approvals, `procurement.allowSelfApproval`).
 *
 * NEVER A DUPLICATE. A request is checked when it is sent and again when it is approved (DuplicatesService): a
 * barcode or a product code that is taken refuses it, the same name asks for a person's confirmation — and the
 * catalogue itself refuses a taken code or barcode once more at the moment of creation.
 *
 * OWNERSHIP. While the section follows Odoo a request that came from Odoo is Odoo's (`Owned`): its values and its
 * decision are refused here with PREQ_OWNED_BY_SOURCE; comments, pictures and the OPS product it corresponds to stay
 * OPS's. A request raised in OPS works fully whatever the section follows.
 *
 * Payload arrays are camelCase and already validated by the controller. In an edit, a key that is present with a null
 * value means "sent empty" (clear it) and an absent key means "leave as it is". Every change is one line of the
 * request's history, one record of the audit trail, and a notification to whoever has to act.
 */
class RequestsService
{
    public const WITH = [
        'category:id,code,name_ar,name_en', 'supplier:id,code,name_ar,name_en', 'customer:id,code,name_ar,name_en', 'product:id,sku,name_ar,name_en,active', 'pictures',
    ];

    public const SCOPES = ['all', 'mine', 'review'];

    public const ORIGINS = ['ops', 'odoo', 'odoo_staff', 'odoo_portal'];

    public const SORTS = [
        'createdAt' => 'created_at', 'number' => 'number', 'nameAr' => 'name_ar', 'status' => 'status', 'statusAt' => 'status_at', 'submittedAt' => 'submitted_at',
        'decidedAt' => 'decided_at', 'costPrice' => 'cost_price', 'salePrice' => 'sale_price', 'requestedBy' => 'requested_by_name',
    ];

    /** Columns that are often empty: the empty ones come last whichever way the list is sorted. */
    private const SPARSE = ['status_at', 'submitted_at', 'decided_at', 'cost_price', 'sale_price', 'requested_by_name'];

    /** Text of the form: camelCase key → column. */
    private const TEXT = [
        'nameAr' => 'name_ar', 'nameEn' => 'name_en', 'brand' => 'brand', 'categoryName' => 'category_name', 'supplierName' => 'supplier_name', 'uomName' => 'uom_name',
        'packText' => 'pack_text', 'reason' => 'reason', 'customerName' => 'customer_name',
    ];

    /** Numbers of the form: key → [column, int | float]. */
    private const NUMBERS = [
        'packQty' => ['pack_qty', 'int'], 'shelfLifeDays' => ['shelf_life_days', 'int'], 'costPrice' => ['cost_price', 'float'], 'salePrice' => ['sale_price', 'float'],
        'weightKg' => ['weight_kg', 'float'], 'lengthCm' => ['length_cm', 'float'], 'widthCm' => ['width_cm', 'float'], 'heightCm' => ['height_cm', 'float'],
    ];

    /** Records of the master data the form points at: key → [model, column, the column that keeps its name, error code, Arabic, English]. */
    private const REFS = [
        'category' => [ProductCategory::class, 'category_id', 'category_name', 'PREQ_CATEGORY_UNKNOWN', 'التصنيف غير معروف', 'Unknown category'],
        'supplier' => [Supplier::class, 'supplier_id', 'supplier_name', 'PREQ_SUPPLIER_UNKNOWN', 'المورد غير معروف', 'Unknown supplier'],
        'uom' => [Uom::class, 'uom_id', 'uom_name', 'PREQ_UOM_UNKNOWN', 'وحدة القياس غير معروفة', 'Unknown unit of measure'],
        'packUom' => [Uom::class, 'pack_uom_id', null, 'PREQ_UOM_UNKNOWN', 'وحدة العبوة غير معروفة', 'Unknown pack unit'],
        'customer' => [Customer::class, 'customer_id', 'customer_name', 'PREQ_CUSTOMER_UNKNOWN', 'العميل غير معروف', 'Unknown customer'],
    ];

    public function __construct(
        private readonly RequestLog $log,
        private readonly NumberingService $numbering,
        private readonly DuplicatesService $duplicates,
        private readonly Catalogue $catalogue,
        private readonly SettingsService $settings,
    ) {}

    // ───────────── who ─────────────

    public function reviews(AuthUser $u): bool
    {
        return $u->can('preq.review');
    }

    /** The request is this user's: he asked for it, or typed it in. */
    public function mine(AuthUser $u, ProductRequest $r): bool
    {
        return self::same($r->requested_by_id, $u->id) || self::same($r->created_by_id, $u->id);
    }

    /** May the person who raised a request approve it himself? (policy `preq.allowSelfApproval`, off unless switched on) */
    public function selfApproval(): bool
    {
        return (bool) $this->settings->get('preq.allowSelfApproval');
    }

    // ───────────── reading ─────────────

    /**
     * @param  array{scope?:?string, status?:?string, category?:?string, supplier?:?string, customer?:?string, requester?:?string, origin?:?string, type?:?string,
     *               storage?:?string, hasProduct?:?string, from?:?string, to?:?string}  $f
     */
    public function list(AuthUser $u, Paging $page, array $f): array
    {
        $query = $this->filtered($u, $f, $page->q)->with(self::WITH);
        $this->order($query, $page->sort, $page->order);
        $out = $page->paginate($query);
        $out['items'] = $this->presenter($u)($out['items']);

        return $out;
    }

    /**
     * What waits, and for whom: the requests waiting for a reviewer, the ones in review and the ones sent back to
     * their authors — the oldest first, each with its age — and how old the review queue is as a whole.
     */
    public function board(AuthUser $u, array $f, ?string $q, int $per): array
    {
        unset($f['status'], $f['scope']);
        $base = fn (): Builder => $this->filtered($u, $f, $q);
        $present = $this->presenter($u);
        $columns = [];
        foreach ([Flow::SUBMITTED, Flow::IN_REVIEW, Flow::RETURNED] as $status) {
            $n = $base()->where('status', $status)->count();
            // exactly the order of the list under sort=statusAt&order=asc: the rest of a column is read through the
            // list, page by page, and must continue where these cards stop (requests of the same moment included)
            $rows = $n ? $this->order($base()->where('status', $status)->with(self::WITH), 'statusAt', 'asc')->limit($per)->get()->all() : [];
            $columns[] = [
                'status' => $status, 'count' => $n, 'oldestAt' => $rows ? $rows[0]->status_at?->toJSON() : null,
                'items' => $present($rows), 'more' => $n > count($rows),
            ];
        }
        // how long the reviewers' queue has been waiting: up to a day · 1–3 days · 3–7 days · more than a week
        $now = now();
        $edges = [$now->copy()->subDay(), $now->copy()->subDays(3), $now->copy()->subDays(7)];
        $waiting = fn (): Builder => $base()->whereIn('status', Flow::WAITING);
        $ageing = [
            ['key' => 'day', 'count' => $waiting()->where('status_at', '>=', $edges[0])->count()],
            ['key' => 'days3', 'count' => $waiting()->where('status_at', '<', $edges[0])->where('status_at', '>=', $edges[1])->count()],
            ['key' => 'week', 'count' => $waiting()->where('status_at', '<', $edges[1])->where('status_at', '>=', $edges[2])->count()],
            ['key' => 'older', 'count' => $waiting()->where(fn ($w) => $w->where('status_at', '<', $edges[2])->orWhereNull('status_at'))->count()],
        ];

        return ['columns' => $columns, 'per' => $per, 'ageing' => $ageing, 'waiting' => array_sum(array_column($ageing, 'count'))];
    }

    /** The figures of the page head and the badges of its tabs. */
    public function summary(AuthUser $u): array
    {
        $byStatus = array_fill_keys(Flow::STATUSES, 0);
        foreach ($this->visible($u)->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get() as $row) {
            $byStatus[$row->status] = (int) $row->n;
        }
        $mine = array_fill_keys(Flow::OPEN, 0);
        foreach ($this->visible($u)->where(fn ($w) => $w->where('requested_by_id', $u->id)->orWhere('created_by_id', $u->id))->whereIn('status', Flow::OPEN)
            ->toBase()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get() as $row) {
            $mine[$row->status] = (int) $row->n;
        }
        $oldest = $this->visible($u)->whereIn('status', Flow::WAITING)->whereNotNull('status_at')->min('status_at');
        $oldest = $oldest ? Carbon::parse($oldest) : null;

        return [
            'byStatus' => $byStatus, 'total' => array_sum($byStatus),
            'waiting' => $byStatus[Flow::SUBMITTED] + $byStatus[Flow::IN_REVIEW],
            'oldestWaitingAt' => $oldest?->toJSON(), 'oldestWaitingDays' => $oldest ? intdiv(max(0, now()->getTimestamp() - $oldest->getTimestamp()), 86400) : null,
            'mine' => $mine + ['open' => array_sum($mine)],
            'approvedThisMonth' => $this->visible($u)->where('status', Flow::APPROVED)->where('decided_at', '>=', now()->startOfMonth())->count(),
            'fromOdoo' => $this->visible($u)->where('source_system', ProductRequest::ODOO)->count(),
            'follows' => Owned::following(), 'can' => ['create' => $u->can('preq.create'), 'review' => $this->reviews($u)],
        ];
    }

    /** What the filters and the forms of the page need that the master data does not already give. */
    public function lookups(AuthUser $u): array
    {
        $requesters = $this->visible($u)->toBase()->selectRaw('requested_by_id, requested_by_name, COUNT(*) AS n')->groupBy('requested_by_id', 'requested_by_name')
            ->orderByDesc('n')->limit(60)->get();
        $people = (new People)->load($requesters->pluck('requested_by_id')->all());
        $list = [];
        foreach ($requesters as $row) {
            $person = $people->person($row->requested_by_id, $row->requested_by_name);
            if ($person) {
                $key = $person['id'] ?? 'name:'.$person['nameAr'];
                $list[$key] = ['key' => $key, 'nameAr' => $person['nameAr'], 'nameEn' => $person['nameEn'], 'count' => ($list[$key]['count'] ?? 0) + (int) $row->n];
            }
        }
        $label = fn (string $k, array $l) => ['key' => $k, 'ar' => $l[0], 'en' => $l[1]];

        return [
            'follows' => Owned::following(),
            'can' => ['create' => $u->can('preq.create'), 'review' => $this->reviews($u)],
            'selfApproval' => $this->selfApproval(),
            'statuses' => array_map(fn (string $k) => $label($k, Flow::LABELS[$k]), Flow::STATUSES),
            'sourceStates' => array_map(fn (string $k) => $label($k, Flow::SOURCE_STATES[$k]), array_keys(Flow::SOURCE_STATES)),
            'storageClasses' => Flow::STORAGE,
            'requesters' => array_values($list),
            'limits' => ['pictureBytes' => PicturesService::MAX_BYTES, 'pictures' => PicturesService::MAX_PICTURES],
        ];
    }

    public function get(AuthUser $u, string $idOrNumber): array
    {
        return $this->detail($u, $this->find($u, $idOrNumber));
    }

    /** The request by id or number — for somebody who may see it (anybody else is told it does not exist). */
    public function find(AuthUser $u, string $idOrNumber, bool $lock = false): ProductRequest
    {
        $query = $this->visible($u)->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber));

        return ($lock ? $query->lockForUpdate() : $query)->first()
            ?? throw AppError::notFound('PREQ_NOT_FOUND', 'طلب الصنف غير موجود', 'Product request not found');
    }

    /**
     * The duplicates of what is being typed (`$probe`). `$requestId`: of that request as it stands (what was typed
     * replaces its values). `$exceptId`: the request whose form is being typed — it is not its own duplicate.
     */
    public function duplicates(AuthUser $u, array $probe, ?string $requestId = null, ?string $exceptId = null): array
    {
        if ($requestId !== null && $requestId !== '') {
            $r = $this->find($u, $requestId);
            $probe += ['nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'packText' => $r->pack_text, 'barcode' => $r->barcode, 'sku' => $r->proposed_sku];

            return $this->duplicates->check($probe, $r->id);
        }

        return $this->duplicates->check($probe, $exceptId !== null && $exceptId !== '' ? $this->find($u, $exceptId)->id : null);
    }

    /**
     * The requests this user may see: all of them, except what other people never sent — a draft, or a draft its
     * author dropped — which only its author and the reviewers see. What came from Odoo was always everybody's.
     */
    public function visible(AuthUser $u): Builder
    {
        $query = ProductRequest::query();
        if (! $this->reviews($u)) {
            $query->where(fn ($w) => $w->whereNotNull('submitted_at')->orWhereNotNull('source_system')->orWhere('requested_by_id', $u->id)->orWhere('created_by_id', $u->id));
        }

        return $query;
    }

    /** The filters every list of requests shares (the board reads through it too). */
    public function filtered(AuthUser $u, array $f, ?string $q): Builder
    {
        $query = $this->visible($u);
        match ($f['scope'] ?? 'all') {
            'mine' => $query->where(fn ($w) => $w->where('requested_by_id', $u->id)->orWhere('created_by_id', $u->id)),
            'review' => $query->whereIn('status', Flow::WAITING),
            default => null,
        };
        if (! empty($f['status'])) {
            $statuses = [];
            foreach (explode(',', (string) $f['status']) as $s) {
                $statuses = array_merge($statuses, Flow::GROUPS[$s] ?? [$s]);
            }
            $query->whereIn('status', array_values(array_unique($statuses)));
        }
        foreach (['category' => [ProductCategory::class, 'category_id'], 'supplier' => [Supplier::class, 'supplier_id'], 'customer' => [Customer::class, 'customer_id']] as $key => [$class, $column]) {
            if (! empty($f[$key])) {
                $query->whereIn($column, $class::query()->where('id', $f[$key])->orWhere('code', $f[$key])->select('id'));
            }
        }
        if (! empty($f['requester'])) {
            $requester = (string) $f['requester'];
            match (true) {
                $requester === 'me' => $query->where('requested_by_id', $u->id),
                str_starts_with($requester, 'name:') => $query->whereNull('requested_by_id')->where('requested_by_name', substr($requester, 5)),
                default => $query->where('requested_by_id', $requester),
            };
        }
        match ($f['origin'] ?? null) {
            'ops' => $query->whereNull('source_system'),
            'odoo' => $query->where('source_system', ProductRequest::ODOO),
            'odoo_staff' => $query->where('source_system', ProductRequest::ODOO)->where('source_model', ProductRequest::STAFF_MODEL),
            'odoo_portal' => $query->where('source_system', ProductRequest::ODOO)->where('source_model', ProductRequest::PORTAL_MODEL),
            default => null,
        };
        if (! empty($f['type'])) {
            $query->where('request_type', $f['type']);
        }
        if (! empty($f['storage'])) {
            $query->where('storage_class', $f['storage']);
        }
        if (isset($f['hasProduct']) && $f['hasProduct'] !== '' && $f['hasProduct'] !== null) {
            in_array((string) $f['hasProduct'], ['1', 'true'], true) ? $query->whereNotNull('product_id') : $query->whereNull('product_id');
        }
        if (! empty($f['from'])) {
            $query->where('created_at', '>=', Carbon::parse($f['from'])->startOfDay());
        }
        if (! empty($f['to'])) {
            $query->where('created_at', '<', Carbon::parse($f['to'])->startOfDay()->addDay());
        }
        if ($q !== null && $q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $op = Sql::like();
            $key = NameKey::of($q);
            $query->where(function ($w) use ($like, $op, $q, $key) {
                foreach (['number', 'source_number', 'name_ar', 'name_en', 'brand', 'barcode', 'proposed_sku', 'product_sku', 'requested_by_name', 'customer_name', 'supplier_name', 'category_name'] as $column) {
                    $w->orWhere($column, $op, $like);
                }
                if (mb_strlen($key) >= 2) {
                    // the name as duplicate detection folds it: «جبنه» finds «جبنة»
                    $folded = '%'.addcslashes($key, '\\%_').'%';
                    $w->orWhere('name_key', $op, $folded)->orWhere('name_en_key', $op, $folded);
                }
                if (ctype_digit($q)) {
                    $w->orWhere('external_ref', $q); // the id people know the request by in Odoo
                }
            });
        }

        return $query;
    }

    private function order(Builder $query, ?string $sort, string $order): Builder
    {
        $column = self::SORTS[$sort ?? ''] ?? null;
        if ($column === null) {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }
        if (in_array($column, self::SPARSE, true)) {
            $query->orderByRaw("{$column} IS NULL");
        }

        return $query->orderBy($column, $order)->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Shapes a page of requests with one read of the people and of the archived pictures. @return callable(iterable): array */
    private function presenter(AuthUser $u): callable
    {
        return function (iterable $rows) use ($u): array {
            $rows = is_array($rows) ? $rows : iterator_to_array($rows);
            $people = (new People)->load(array_map(fn (ProductRequest $r) => $r->requested_by_id, $rows));
            $refs = [];
            foreach ($rows as $r) {
                foreach ($r->pictures as $p) {
                    $refs[] = $p->external_ref;
                }
            }
            $archived = PicturesService::archived($refs);
            $now = now();

            return array_map(fn (ProductRequest $r) => Shape::card($r, $people, $archived, $now, $this->mine($u, $r), Owned::bySource($r)), array_values($rows));
        };
    }

    private function detail(AuthUser $u, ProductRequest $r): array
    {
        $r->load([...self::WITH, 'uom:id,code,name_ar,name_en', 'packUom:id,code,name_ar,name_en', 'events']);
        $people = (new People)->load([$r->requested_by_id, $r->reviewer_id, $r->decided_by_id]);
        $archived = PicturesService::archived($r->pictures->pluck('external_ref')->all());
        $owned = Owned::bySource($r);
        $mine = $this->mine($u, $r);
        $reviews = $this->reviews($u);
        $creates = $u->can('preq.create');
        $waiting = in_array($r->status, Flow::WAITING, true);
        $editing = in_array($r->status, [Flow::DRAFT, Flow::RETURNED], true);
        $self = $this->selfApproval();
        $missing = $r->open() && $r->request_type === 'new' ? $this->catalogue->missing($r) : [];
        $more = Shape::detail($r, $people, $archived);
        // a picture is taken off by whoever added it (while the request is open) or by a reviewer; a picture of Odoo is Odoo's while the request is
        $more['pictures'] = array_map(fn (array $p) => $p + [
            'canRemove' => ! ($p['origin'] === 'odoo' && $owned) && ($reviews || ($r->open() && self::same($p['uploadedById'], $u->id))),
        ], $more['pictures']);

        return Shape::card($r, $people, $archived, now(), $mine, $owned) + $more + [
            'can' => [
                'edit' => ! $owned && (($mine && $creates && $editing) || ($reviews && $waiting)),
                'submit' => ! $owned && $mine && $creates && $editing,
                'startReview' => ! $owned && $reviews && $r->status === Flow::SUBMITTED,
                'sendBack' => ! $owned && $reviews && $waiting,
                'approve' => ! $owned && $reviews && $waiting && ($self || ! $mine),
                'reject' => ! $owned && $reviews && $waiting,
                'cancel' => ! $owned && $r->open() && (($mine && $creates) || ($reviews && $r->status !== Flow::DRAFT)),
                'comment' => $creates || $reviews,
                'addPicture' => $this->blockPicture($u, $r) === null,
                'linkProduct' => $reviews && $r->status === Flow::APPROVED && ($r->product_id === null || $r->product_link !== 'created'),
            ],
            // the reviewer raised it himself and the policy keeps the two apart: somebody else approves
            'selfApprovalBlocked' => ! $owned && $reviews && $waiting && $mine && ! $self,
            'missing' => $missing,
            'estimate' => $missing ? $this->catalogue->estimate($r->name_ar, $r->name_en, $r->pack_text, $r->category?->name_ar ?? $r->category_name) : null,
        ];
    }

    // ───────────── raising and editing ─────────────

    public function create(AuthUser $u, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $dto) {
            $values = $this->values($dto);
            $r = ProductRequest::create($values + [
                'number' => $this->numbering->next('PREQ'), 'request_type' => 'new', 'status' => Flow::DRAFT, 'status_at' => now(), 'currency' => 'SAR',
                'storage_class' => $values['storage_class'] ?? 'ambient',
                'requested_by_id' => $u->id, 'requested_by_name' => $u->nameAr, 'created_by_id' => $u->id, 'created_by' => $u->username,
            ]);
            $this->log->event($r, 'created', $u, null, [], null, Flow::DRAFT);
            $this->log->audit($u, $r, 'CREATE', null, ['nameAr' => $r->name_ar, 'nameEn' => $r->name_en, 'barcode' => $r->barcode, 'costPrice' => $r->cost_price, 'salePrice' => $r->sale_price]);
            if (! empty($dto['submit'])) {
                $this->send($u, $r, ! empty($dto['confirmDuplicate']));
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function update(AuthUser $u, string $idOrNumber, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $dto) {
            $r = $this->find($u, $idOrNumber, true);
            $this->change($u, $r, $dto);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /**
     * Applies the values of the form to a request, for whoever may: its author while it is a draft or was sent back,
     * a reviewer while it waits for review (his corrections are told apart in the history).
     *
     * @return bool something changed
     */
    private function change(AuthUser $u, ProductRequest $r, array $dto): bool
    {
        Owned::refuse($r);
        $byAuthor = $this->mine($u, $r) && $u->can('preq.create') && in_array($r->status, [Flow::DRAFT, Flow::RETURNED], true);
        $byReviewer = $this->reviews($u) && in_array($r->status, Flow::WAITING, true);
        if (! $byAuthor && ! $byReviewer) {
            throw $this->notEditable($u, $r);
        }
        $changes = $this->diff($r, $this->values($dto));
        if (! $changes) {
            return false;
        }
        $old = array_map(fn (array $c) => $c[0], $changes);
        $new = array_map(fn (array $c) => $c[1], $changes);
        $r->forceFill($new)->save();
        $told = $this->told($changes, $dto);
        $this->log->event($r, $byAuthor ? 'edited' : 'corrected', $u, null, ['changes' => $told]);
        $this->log->audit($u, $r, $byAuthor ? 'UPDATE' : 'CORRECT', $old, $new);

        return true;
    }

    /** Why this user may not edit this request now. */
    private function notEditable(AuthUser $u, ProductRequest $r): AppError
    {
        if (! $r->open()) {
            return AppError::rule('PREQ_CLOSED', 'الطلب مغلق ولا يُعدَّل', 'The request is closed and cannot be edited');
        }
        if ($this->mine($u, $r) && $u->can('preq.create')) {
            return AppError::rule('PREQ_NOT_EDITABLE', 'أُرسل الطلب للمراجعة — يصحّحه المراجع أو يعيده إليك للتصحيح', 'The request was sent for review — the reviewer corrects it or sends it back to you');
        }
        if ($this->reviews($u)) {
            return AppError::rule('PREQ_NOT_EDITABLE', 'الطلب عند صاحبه (مسودة أو أُعيد للتصحيح) — يُعدَّل بعد إرساله', 'The request is with its author (a draft, or sent back) — it is edited once it is sent');
        }

        return AppError::forbidden('PREQ_NOT_YOURS', 'هذا الطلب لغيرك — يعدّله صاحبه أو مراجع الأصناف', 'This request is somebody else\'s — its author or a reviewer edits it');
    }

    // ───────────── the moves ─────────────

    /** The author sends his draft — or what he corrected — to the reviewers. */
    public function submit(AuthUser $u, string $idOrNumber, bool $confirmDuplicate = false): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $confirmDuplicate) {
            $r = $this->find($u, $idOrNumber, true);
            Owned::refuse($r);
            if (! $this->mine($u, $r)) {
                throw AppError::forbidden('PREQ_NOT_YOURS', 'يرسل الطلب صاحبه', 'A request is sent by its author');
            }
            $this->send($u, $r, $confirmDuplicate);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    private function send(AuthUser $u, ProductRequest $r, bool $confirmDuplicate): void
    {
        Flow::assert($r->status, Flow::SUBMITTED);
        $found = $this->duplicates->gate($r, $confirmDuplicate);
        $again = $r->status === Flow::RETURNED;
        $from = $r->status;
        $r->forceFill(['status' => Flow::SUBMITTED, 'status_at' => now(), 'submitted_at' => $r->submitted_at ?? now()])->save();
        $this->log->event($r, $again ? 'resubmitted' : 'submitted', $u, null, self::confirmed($found, $confirmDuplicate), $from, Flow::SUBMITTED);
        $this->log->status($u, $r, $from, Flow::SUBMITTED);
        if ($again && $r->reviewer_id) {
            $this->log->tell($r->reviewer_id, $r, "صُحّح طلب الصنف {$r->number} «{$r->name_ar}» وأُعيد إرساله", "Product request {$r->number} was corrected and sent again", $u);
        } else {
            $this->log->tellReviewers($r, "طلب صنف جديد بانتظار المراجعة: {$r->number} «{$r->name_ar}»", "A new-product request waits for review: {$r->number}");
        }
    }

    /** A reviewer takes a request off the queue: it is his to decide. */
    public function startReview(AuthUser $u, string $idOrNumber): array
    {
        return $this->decide($u, $idOrNumber, function (ProductRequest $r) use ($u) {
            $from = $this->move($r, Flow::IN_REVIEW, ['reviewer_id' => $u->id, 'reviewer_name' => $u->nameAr, 'review_started_at' => now()]);
            $this->log->event($r, 'review_started', $u, null, [], $from, Flow::IN_REVIEW);
            $this->log->status($u, $r, $from, Flow::IN_REVIEW);
        });
    }

    /** Sent back to its author with what to correct. */
    public function sendBack(AuthUser $u, string $idOrNumber, string $reason): array
    {
        $reason = self::reason($reason, 'ما المطلوب تصحيحه؟ اكتب السبب', 'Say what has to be corrected');

        return $this->decide($u, $idOrNumber, function (ProductRequest $r) use ($u, $reason) {
            $from = $this->move($r, Flow::RETURNED, ['return_reason' => $reason, 'returns_count' => $r->returns_count + 1, 'reviewer_id' => $u->id, 'reviewer_name' => $u->nameAr]);
            $this->log->event($r, 'returned', $u, $reason, [], $from, Flow::RETURNED);
            $this->log->status($u, $r, $from, Flow::RETURNED, $reason);
            $this->log->tell($r->requested_by_id, $r, "أُعيد طلب الصنف {$r->number} للتصحيح: {$reason}", "Product request {$r->number} was sent back for correction: {$reason}", $u);
        });
    }

    /**
     * Rejected, with the reason. When the reason is "it already exists", the product it duplicates may be named: the
     * person who asked then finds it from his request.
     */
    public function reject(AuthUser $u, string $idOrNumber, string $reason, ?string $existingProduct = null): array
    {
        $reason = self::reason($reason, 'سبب الرفض مطلوب', 'A reason is needed to reject a request');

        return $this->decide($u, $idOrNumber, function (ProductRequest $r) use ($u, $reason, $existingProduct) {
            $product = $existingProduct !== null && trim($existingProduct) !== '' ? $this->product($existingProduct) : null;
            $from = $this->move($r, Flow::REJECTED, ['reject_reason' => $reason, 'decided_by_id' => $u->id, 'decided_by_name' => $u->nameAr, 'decided_at' => now()]
                + ($product ? ['product_id' => $product->id, 'product_sku' => $product->sku, 'product_link' => 'existing'] : []));
            $this->log->event($r, 'rejected', $u, $reason, $product ? ['existingProduct' => $product->sku] : [], $from, Flow::REJECTED);
            $this->log->status($u, $r, $from, Flow::REJECTED, $reason);
            $this->log->tell($r->requested_by_id, $r, "رُفض طلب الصنف {$r->number} «{$r->name_ar}»: {$reason}".($product ? " — الصنف الموجود: {$product->sku}" : ''),
                "Product request {$r->number} was rejected: {$reason}".($product ? " — existing product: {$product->sku}" : ''), $u);
        });
    }

    /**
     * Approved: the product is created in the catalogue and the request keeps the link to it. The reviewer may send
     * his last corrections (`changes`, the keys of the form), decide the product code (`sku`; otherwise the code the
     * request asks for, otherwise the next free one of the series) and confirm that a product of the same name is
     * another product (`confirmDuplicate`). All of it happens together or not at all.
     *
     * @param  array{sku?:?string, confirmDuplicate?:?bool, note?:?string, changes?:?array}  $opts
     */
    public function approve(AuthUser $u, string $idOrNumber, array $opts = []): array
    {
        return $this->decide($u, $idOrNumber, function (ProductRequest $r) use ($u, $opts) {
            Flow::assert($r->status, Flow::APPROVED);
            $own = $this->mine($u, $r);
            if ($own && ! $this->selfApproval()) {
                throw AppError::rule('PREQ_SELF_APPROVAL', 'لا يعتمد الطلبَ من رفعه — يعتمده مراجع آخر', 'A request is not approved by the person who raised it — another reviewer approves it');
            }
            if (! empty($opts['changes'])) {
                $this->change($u, $r, (array) $opts['changes']);
            }
            $note = ($x = trim((string) ($opts['note'] ?? ''))) !== '' ? $x : null;
            $confirmed = ! empty($opts['confirmDuplicate']);
            $more = ['decided_by_id' => $u->id, 'decided_by_name' => $u->nameAr, 'decided_at' => now()];
            if ($r->request_type !== 'new') {
                // a request for a product the catalogue has: nothing is created, the request points at that product
                if (! $r->product_id) {
                    throw AppError::rule('PREQ_PRODUCT_REQUIRED', 'هذا طلب لصنف موجود — اربطه بالصنف أولًا', 'This request is about an existing product — link it to that product first');
                }
                $from = $this->move($r, Flow::APPROVED, $more + ['product_link' => $r->product_link ?? 'existing']);
                $meta = ['sku' => $r->product_sku];
            } else {
                $this->catalogue->assertComplete($r);
                // one approval at a time decides a product code: the numbering row of the requests is the lock
                DB::table('number_sequences')->where('key', 'PREQ')->lockForUpdate()->first();
                $asked = trim((string) ($opts['sku'] ?? '')) !== '' ? (string) $opts['sku'] : ($r->proposed_sku ?: null);
                $sku = $asked !== null ? Catalogue::sku($asked) : $this->catalogue->nextSku();
                $found = $this->duplicates->gate($r, $confirmed, $sku);
                $product = $this->catalogue->create($u, $r, $sku);
                $from = $this->move($r, Flow::APPROVED, $more + ['product_id' => $product['id'], 'product_sku' => $product['sku'], 'product_link' => 'created']);
                $meta = ['sku' => $product['sku'], 'productId' => $product['id']] + self::confirmed($found, $confirmed);
            }
            $this->log->event($r, 'approved', $u, $note, $meta + ($own ? ['selfApproved' => true] : []), $from, Flow::APPROVED);
            $this->log->status($u, $r, $from, Flow::APPROVED, $note);
            $this->log->audit($u, $r, 'APPROVE', null, $meta + ['nameAr' => $r->name_ar] + ($own ? ['selfApproved' => true] : []));
            $sku = (string) ($meta['sku'] ?? '');
            $this->log->activity($u, $r, "اعتُمد طلب الصنف {$r->number} — الصنف {$sku} «{$r->name_ar}» في الكتالوج", "Product request {$r->number} approved — product {$sku} is in the catalogue");
            $this->log->tell($r->requested_by_id, $r, "اعتُمد طلبك {$r->number}: الصنف «{$r->name_ar}» أُضيف إلى الكتالوج بالرمز {$sku}", "Your request {$r->number} was approved: the product is in the catalogue as {$sku}", $u);
        });
    }

    /** Withdrawn by its author, or closed by a reviewer. */
    public function cancel(AuthUser $u, string $idOrNumber, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $reason) {
            $r = $this->find($u, $idOrNumber, true);
            Owned::refuse($r);
            $own = $this->mine($u, $r) && $u->can('preq.create');
            $asReviewer = ! $own && $this->reviews($u);
            if (! $own && ! $asReviewer) {
                throw AppError::forbidden('PREQ_NOT_YOURS', 'يلغي الطلب صاحبه أو مراجع الأصناف', 'A request is cancelled by its author or by a reviewer');
            }
            Flow::assert($r->status, Flow::CANCELLED);
            if ($asReviewer && $r->status === Flow::DRAFT) {
                throw AppError::forbidden('PREQ_NOT_YOURS', 'المسودة عند صاحبها — لم تُرسل بعد', 'A draft is its author\'s — it was not sent yet');
            }
            $reason = ($x = trim((string) $reason)) !== '' ? mb_substr($x, 0, 1000) : null;
            if ($asReviewer && $reason === null) {
                throw AppError::validation('PREQ_REASON_REQUIRED', 'اكتب سبب إلغاء طلب غيرك', 'Give the reason for cancelling somebody else\'s request');
            }
            $from = $this->move($r, Flow::CANCELLED, ['cancel_reason' => $reason]);
            $this->log->event($r, 'cancelled', $u, $reason, [], $from, Flow::CANCELLED);
            $this->log->status($u, $r, $from, Flow::CANCELLED, $reason);
            $asReviewer
                ? $this->log->tell($r->requested_by_id, $r, "أُلغي طلب الصنف {$r->number}: {$reason}", "Product request {$r->number} was cancelled: {$reason}", $u)
                : $this->log->tell($r->reviewer_id, $r, "سحب صاحب الطلب {$r->number} طلبه «{$r->name_ar}»", "The author of request {$r->number} withdrew it", $u);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** A line of the conversation. OPS's own on every request, whoever owns the request. */
    public function comment(AuthUser $u, string $idOrNumber, string $text): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $text) {
            $r = $this->find($u, $idOrNumber, true);
            $this->log->event($r, 'comment', $u, $text);
            // the other side of the conversation is told: the author when somebody else writes, the reviewer when he does
            $this->mine($u, $r)
                ? $this->log->tell($r->reviewer_id, $r, "ملاحظة على طلب الصنف {$r->number}: ".mb_substr($text, 0, 140), "A comment on product request {$r->number}", $u)
                : $this->log->tell($r->requested_by_id, $r, "ملاحظة على طلبك {$r->number}: ".mb_substr($text, 0, 140), "A comment on your request {$r->number}", $u);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /**
     * Says which OPS product an approved request corresponds to — for a request of Odoo whose product could not be
     * recognised, or to put a wrong link right. The product a request created HERE is its product for good.
     */
    public function linkProduct(AuthUser $u, string $idOrNumber, string $product): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $product) {
            $r = $this->find($u, $idOrNumber, true);
            if ($r->status !== Flow::APPROVED) {
                throw AppError::rule('PREQ_NOT_APPROVED', 'يُربط الصنف بطلب معتمد فقط', 'Only an approved request is linked to a product');
            }
            if ($r->product_id && $r->product_link === 'created') {
                throw AppError::rule('PREQ_PRODUCT_LINKED', "هذا الطلب أنشأ الصنف {$r->product_sku} — لا يُربط بغيره", "This request created product {$r->product_sku} — it is not linked to another");
            }
            $p = $this->product($product);
            if (self::same($r->product_id, $p->id)) {
                return $r->id;
            }
            $old = $r->product_sku;
            $r->forceFill(['product_id' => $p->id, 'product_sku' => $p->sku, 'product_link' => 'manual'])->save();
            $this->log->event($r, 'product_linked', $u, null, array_filter(['from' => $old, 'to' => $p->sku]));
            $this->log->audit($u, $r, 'LINK_PRODUCT', $old, $p->sku, 'product');

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** Why this user may not add a picture to this request (null = he may). */
    public function blockPicture(AuthUser $u, ProductRequest $r): ?AppError
    {
        if (! $r->open()) {
            return AppError::rule('PREQ_CLOSED', 'الطلب مغلق — لا تُضاف إليه صور', 'The request is closed — no picture is added to it');
        }
        if ($this->reviews($u)) {
            return null;
        }
        if (! $u->can('preq.create')) {
            return AppError::forbidden('FORBIDDEN', 'إضافة الصور تحتاج صلاحية رفع الطلبات أو مراجعتها', 'Adding pictures needs preq.create or preq.review');
        }

        // a request of Odoo has no author in OPS: whoever raises requests may add a picture to it
        return $this->mine($u, $r) || $r->fromOdoo() ? null
            : AppError::forbidden('PREQ_NOT_YOURS', 'هذا الطلب لغيرك — يضيف إليه الصور صاحبه أو مراجع الأصناف', 'This request is somebody else\'s — its author or a reviewer adds pictures to it');
    }

    // ───────────── helpers ─────────────

    /** A reviewer's move on a request that is not Odoo's, in one transaction. */
    private function decide(AuthUser $u, string $idOrNumber, callable $work): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $work) {
            $r = $this->find($u, $idOrNumber, true);
            Owned::refuse($r);
            $work($r);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** Moves the request (refusing an illegal move) and returns the status it left. */
    private function move(ProductRequest $r, string $to, array $more = []): string
    {
        $from = $r->status;
        Flow::assert($from, $to);
        $r->forceFill(['status' => $to, 'status_at' => now()] + $more)->save();

        return $from;
    }

    private function product(string $idOrSku): Product
    {
        $key = trim($idOrSku);

        return Product::where('id', $key)->orWhere('sku', mb_strtoupper($key))->first()
            ?? throw AppError::notFound('PREQ_PRODUCT_NOT_FOUND', 'الصنف غير موجود في الكتالوج', 'The product is not in the catalogue');
    }

    /** What the person confirmed when he sent or approved a request whose name already exists. */
    private static function confirmed(array $found, bool $confirmed): array
    {
        if (! $confirmed) {
            return [];
        }
        $exact = fn (array $rows) => array_values(array_filter($rows, fn (array $x) => $x['match'] === 'exact'));
        $names = array_merge(array_column($exact($found['names']['products']), 'sku'), array_column($exact($found['names']['requests']), 'number'));

        return $names ? ['confirmedDuplicateOf' => array_slice($names, 0, 10)] : [];
    }

    private static function reason(string $reason, string $ar, string $en): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw AppError::validation('PREQ_REASON_REQUIRED', $ar, $en);
        }

        return mb_substr($reason, 0, 1000);
    }

    /**
     * The columns the form sets, for the keys that were sent. A reference is given by id or code and must exist.
     *
     * @return array<string, mixed> column → value
     */
    private function values(array $dto): array
    {
        $v = [];
        foreach (self::TEXT as $key => $column) {
            if (array_key_exists($key, $dto)) {
                $text = trim((string) ($dto[$key] ?? ''));
                $v[$column] = $text === '' ? null : $text;
            }
        }
        if (array_key_exists('name_ar', $v) && $v['name_ar'] === null) {
            throw AppError::validation('PREQ_NAME_REQUIRED', 'اسم الصنف بالعربية مطلوب', 'The Arabic name of the product is required');
        }
        foreach (self::REFS as $key => [$class, $column, $nameColumn, $code, $ar, $en]) {
            if (! array_key_exists($key, $dto)) {
                continue;
            }
            $given = trim((string) ($dto[$key] ?? ''));
            $row = $given === '' ? null : ($class::query()->where('id', $given)->orWhere('code', $given)->first() ?? throw AppError::validation($code, $ar, $en));
            $v[$column] = $row?->getKey();
            // the name is kept beside the link (the lists search it) and goes with it; a name typed in the same call stays as typed
            if ($nameColumn && ! array_key_exists($nameColumn, $v)) {
                $v[$nameColumn] = $row ? (string) $row->getAttribute('name_ar') : null;
            }
        }
        foreach (self::NUMBERS as $key => [$column, $type]) {
            if (array_key_exists($key, $dto)) {
                $n = $dto[$key];
                $v[$column] = $n === null || $n === '' ? null : ($type === 'int' ? (int) $n : round((float) $n, 4));
            }
        }
        if (array_key_exists('barcode', $dto)) {
            $v['barcode'] = DuplicatesService::barcode($dto['barcode']) ?: null;
        }
        if (array_key_exists('proposedSku', $dto)) {
            $v['proposed_sku'] = trim((string) ($dto['proposedSku'] ?? '')) === '' ? null : Catalogue::sku((string) $dto['proposedSku']);
        }
        if (array_key_exists('storageClass', $dto)) {
            $v['storage_class'] = $dto['storageClass'] ?: null;
        }
        if (array_key_exists('tracksExpiry', $dto)) {
            $v['tracks_expiry'] = filter_var($dto['tracksExpiry'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('name_ar', $v)) {
            $v['name_key'] = (NameKey::core($v['name_ar']) ?: NameKey::of($v['name_ar'])) ?: null;
        }
        if (array_key_exists('name_en', $v)) {
            $v['name_en_key'] = (NameKey::core($v['name_en']) ?: NameKey::of($v['name_en'])) ?: null;
        }

        return $v;
    }

    /**
     * The values that differ from what the request holds: column → [old, new]. 5 equals "5.0000", a number equals
     * itself to four decimals, and an empty text equals nothing.
     *
     * @return array<string, array{0:mixed, 1:mixed}>
     */
    private function diff(ProductRequest $r, array $values): array
    {
        $out = [];
        foreach ($values as $column => $new) {
            $old = $r->getAttribute($column);
            $same = match (true) {
                is_bool($new) => (bool) $old === $new,
                $old === null || $new === null => $old === $new || ($old === null && $new === '') || ($new === null && $old === ''),
                is_numeric($old) && is_numeric($new) && ! is_string($new) => abs((float) $old - (float) $new) < 0.00005,
                default => (string) $old === (string) $new,
            };
            if (! $same) {
                $out[$column] = [$old, $new];
            }
        }

        return $out;
    }

    /**
     * The changes as the history tells them: the field by its form key, a linked record by its name.
     *
     * @param  array<string, array{0:mixed, 1:mixed}>  $changes
     * @param  array  $dto  what the form sent
     * @return array<int, array{field:string, old:mixed, new:mixed}>
     */
    private function told(array $changes, array $dto): array
    {
        $keys = array_flip(self::TEXT) + array_combine(array_column(self::NUMBERS, 0), array_keys(self::NUMBERS))
            + ['barcode' => 'barcode', 'proposed_sku' => 'proposedSku', 'storage_class' => 'storageClass', 'tracks_expiry' => 'tracksExpiry'];
        $refs = [];
        $namesOfRefs = [];
        foreach (self::REFS as $key => [$class, $column, $nameColumn]) {
            $refs[$column] = [$key, $class];
            // the name kept beside a link follows the link: it is a change of its own only when a person typed it
            if ($nameColumn && isset($changes[$column]) && ! array_key_exists($keys[$nameColumn], $dto)) {
                $namesOfRefs[$nameColumn] = true;
            }
        }
        $out = [];
        foreach ($changes as $column => [$old, $new]) {
            if (isset($namesOfRefs[$column])) {
                continue;
            }
            if (isset($refs[$column])) {
                [$key, $class] = $refs[$column];
                $name = fn ($id) => $id ? ($class::query()->whereKey($id)->value('name_ar') ?? (string) $id) : null;
                $out[] = ['field' => $key, 'old' => $name($old), 'new' => $name($new)];
            } elseif (isset($keys[$column])) {
                $out[] = ['field' => $keys[$column], 'old' => $old, 'new' => $new];
            }
        }

        return $out;
    }

    private static function same(?string $a, ?string $b): bool
    {
        return $a !== null && $a !== '' && $b !== null && strcasecmp($a, $b) === 0;
    }
}
