<?php

namespace App\Services\Sales;

use App\Integration\Services\OrderEvents;
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
use App\Models\Warehouse;
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

/**
 * Sales: Quotation → Sales Order → stock check → Reservation → Allocation (FEFO) → Fulfillment order.
 * Reservation and allocation are separate records; no overselling; expired/quarantined batches are excluded before
 * FEFO (inventory engine). Credit limit enforced (configurable). Documents are returned by re-reading them after the
 * transaction committed.
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
    ];

    private const OC_LIST_SHAPE = [
        'warehouse' => ['code'], 'orders.*' => ['number', 'status', 'customer', 'kg', 'cbm', 'fos'], 'orders.*.customer' => ['nameAr', 'zone'],
        'orders.*.fos.*' => ['number', 'status'], 'trips.*' => ['number', 'status'],
    ];

    private const OC_SHAPE = ['orders.*.lines.*.product' => ['sku', 'nameAr'], 'orders.*.fos.*' => ['number', 'status'], 'trips.*' => ['number', 'status', 'vehicleId', 'driverId']];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly InventoryService $inventory,
    ) {}

    // ───────────── quotations ─────────────

    /** @param  array{status?:?string, customer?:?string}  $filters */
    public function listQuotations(Paging $page, array $filters): array
    {
        $query = Quotation::with(['customer', 'lines.product', 'so'])->orderByDesc('date')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['customer'])) {
            $query->whereHas('customer', fn ($c) => $c->where('code', $filters['customer']));
        }
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%")->orWhere('name_en', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (Quotation $x) => Shape::apply($x->toArray(), self::QT_LIST_SHAPE) + ['totals' => $this->totals($x->lines)]);
    }

    public function getQuotation(string $idOrNumber): array
    {
        $x = $this->findQuotation($idOrNumber);

        return Shape::apply($x->toArray(), self::QT_SHAPE) + ['totals' => $this->totals($x->lines), 'history' => $this->history('Quotation', $x->id)];
    }

    /**
     * @param  array{customerCode:string, validUntil:string, terms?:?string, delivery?:?string, notes?:?string, action?:string, attachments?:?array,
     *               lines:array<int, array{sku:string, qty:int, price:float|int|string, discPct?:float|int|string|null}>}  $dto
     */
    public function createQuotation(AuthUser $user, array $dto): array
    {
        $action = $dto['action'] ?? 'draft';
        $maxDisc = $this->settings->get('sales.maxDiscountPct');
        foreach ($dto['lines'] as $l) {
            if ((float) ($l['discPct'] ?? 0) > $maxDisc) {
                throw AppError::rule('DISCOUNT_APPROVAL', "الخصم فوق {$maxDisc}% يحتاج اعتماد مدير المبيعات", "Discount above {$maxDisc}% needs sales-manager approval");
            }
        }
        if (Carbon::parse($dto['validUntil'])->lt(Carbon::today())) {
            throw AppError::validation('VALID_PAST', 'تاريخ الصلاحية منتهٍ');
        }

        $id = DB::transaction(function () use ($user, $dto, $action) {
            $c = $this->customer($dto['customerCode']);
            $lines = $this->resolveLines($dto['lines']);
            $number = $this->numbering->next('QT');
            $qt = Quotation::create([
                'number' => $number, 'customer_id' => $c->id, 'valid_until' => Carbon::parse($dto['validUntil']), 'status' => $action,
                'terms' => $dto['terms'] ?? null, 'delivery' => $dto['delivery'] ?? null, 'notes' => $dto['notes'] ?? null,
                'created_by_id' => $user->id, 'created_by' => $user->username, 'attachments' => $dto['attachments'] ?? [],
            ]);
            foreach ($lines as $l) {
                QuotationLine::create(['quotation_id' => $qt->id, 'line_no' => $l['lineNo'], 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'price' => $l['price'], 'disc_pct' => $l['discPct']]);
            }
            $this->audit->log($user, ['action' => 'QT.CREATE', 'entityType' => 'Quotation', 'entityId' => $qt->id, 'entityNumber' => $number,
                'newValue' => ['customer' => $c->code, 'lines' => $dto['lines'], 'status' => $action]]);
            $t = $this->totals($lines);
            $sent = $action === 'sent';
            $this->notify->activity($user, 'Quotation', $qt->id, $number, "أُنشئ عرض سعر {$number} لـ {$c->name_ar}".($sent ? ' وأُرسل للعميل' : '')." — الإجمالي {$t['total']} ر.س", "Quotation {$number} created", $sent ? ['sales'] : []);

            return $qt->id;
        });

        return $this->getQuotation($id);
    }

    public function setQuotationStatus(AuthUser $user, string $idOrNumber, string $to, ?string $note = null): Quotation
    {
        $q = Quotation::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود');
        if ($to === 'converted') {
            throw AppError::validation('USE_CONVERT', 'استخدم إجراء التحويل إلى أمر بيع');
        }
        Sm::assert('QUOTATION_TRANSITIONS', $q->status, $to, 'QT_TRANSITION');

        DB::transaction(function () use ($user, $q, $to, $note) {
            $from = $q->status;
            $q->update(['status' => $to]);
            $this->audit->status($user, 'Quotation', $q->id, $q->number, $from, $to, $note);
            $this->notify->activity($user, 'Quotation', $q->id, $q->number, "عرض السعر {$q->number} → {$to}", null, $to === 'sent' ? ['sales'] : []);
        });

        return $q->refresh();
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
     * Quotation → Sales Order: requires approved; credit check; stock check; reserve + allocate FEFO per line (one transaction).
     *
     * @param  array{warehouseCode?:?string, dueDate?:?string, window?:?string, priority?:?string}  $opts
     */
    public function convertQuotation(AuthUser $user, string $idOrNumber, array $opts = []): array
    {
        $q = $this->findQuotation($idOrNumber);
        if ($q->so_id || $q->status === 'converted') {
            throw AppError::rule('QT_CONVERTED', 'العرض محوَّل مسبقًا', 'Quotation already converted');
        }
        if ($q->status !== 'approved') {
            throw AppError::rule('QT_NOT_APPROVED', 'التحويل يتطلب عرضًا معتمدًا', 'Conversion requires an approved quotation');
        }

        return $this->createOrder($user, [
            'customerCode' => $q->customer->code, 'warehouseCode' => ($opts['warehouseCode'] ?? null) ?: 'RYD',
            'dueDate' => ($opts['dueDate'] ?? null) ?: now()->addDay()->toDateString(), 'window' => ($opts['window'] ?? null) ?: '09:00–13:00',
            'priority' => ($opts['priority'] ?? null) ?: 'normal', 'lines' => $this->linesOf($q),
        ], $q->id);
    }

    // ───────────── sales orders ─────────────

    /** @param  array{status?:?string, customer?:?string, warehouse?:?string, waiting?:?string}  $filters */
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
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (SalesOrder $x) => Shape::apply($x->toArray(), self::SO_SHAPE) + ['totals' => $this->totals($x->lines)]);
    }

    public function getOrder(string $idOrNumber): array
    {
        $x = SalesOrder::with(self::soWith() + ['reservations.allocations' => fn ($q) => $q->orderBy('created_at')->orderBy('id')])
            ->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->first()
            ?? throw AppError::notFound('SO_NOT_FOUND', 'أمر البيع غير موجود');
        $foIds = $x->fos->pluck('id')->all();

        return Shape::apply($x->toArray(), self::SO_SHAPE) + [
            'totals' => $this->totals($x->lines),
            'history' => $this->history('SalesOrder', $x->id),
            'pods' => ProofOfDelivery::whereIn('fo_id', $foIds)->orderBy('at')->get(['number', 'result', 'at', 'receiver_name'])->map->toArray()->all(),
            'returns' => ReturnOrder::whereIn('fo_id', $foIds)->orderBy('created_at')->get(['number', 'status', 'type'])->map->toArray()->all(),
        ];
    }

    /**
     * Direct sales order: credit check + availability per line + reservation/allocation FEFO — single transaction
     * (no partial orders).
     *
     * @param  array{customerCode:string, warehouseCode:string, dueDate:string, window?:?string, priority?:?string,
     *               lines:array<int, array{sku:string, qty:int, price:float|int|string, discPct?:float|int|string|null}>}  $dto
     */
    public function createOrder(AuthUser $user, array $dto, ?string $quotationId = null): array
    {
        $enforceCredit = (bool) $this->settings->get('sales.enforceCreditLimit');
        $txId = $this->inventory->newTxId();

        $id = DB::transaction(function () use ($user, $dto, $quotationId, $enforceCredit, $txId) {
            if ($quotationId && Quotation::whereKey($quotationId)->lockForUpdate()->value('status') !== 'approved') {
                throw AppError::rule('QT_CONVERTED', 'العرض محوَّل مسبقًا', 'Quotation already converted'); // lost a double-click race
            }
            $c = $this->customer($dto['customerCode']);
            $w = Warehouse::where('code', $dto['warehouseCode'])->first() ?? throw AppError::notFound('WH_NOT_FOUND', "المستودع {$dto['warehouseCode']} غير موجود");
            $lines = $this->resolveLines($dto['lines']);
            $t = $this->totals($lines);
            $limit = (float) $c->credit_limit;
            $balance = (float) $c->balance;
            if ($enforceCredit && $limit > 0 && $balance + $t['total'] > $limit) {
                throw AppError::rule('CREDIT_LIMIT', 'مرفوض: يتجاوز الحد الائتماني للعميل ('.self::fmt($limit).') — الرصيد الحالي '.self::fmt($balance), 'Customer credit limit exceeded', ['limit' => $limit, 'balance' => $balance, 'order' => $t['total']]);
            }
            // stock check before creating anything (all-or-nothing)
            foreach ($lines as $l) {
                $p = $l['product'];
                $av = (int) $this->inventory->allocRows($p->id, $w->id)->sum(fn ($r) => $r->on_hand - $r->reserved);
                if ($av < $l['qty']) {
                    throw AppError::rule('INSUFFICIENT_AVAILABLE', "مرفوض: المتاح لـ {$p->name_ar} في {$w->code} ({$av}) أقل من {$l['qty']} — لا Overselling", "Available for {$p->name_en} ({$av}) is less than {$l['qty']}", ['sku' => $p->sku, 'available' => $av, 'requested' => $l['qty']]);
                }
            }
            $number = $this->numbering->next('SO');
            $kg = 0.0;
            foreach ($lines as $l) {
                $kg += $l['qty'] * ($l['product']->weight_kg ?: 12);
            }
            $so = SalesOrder::create([
                'number' => $number, 'customer_id' => $c->id, 'warehouse_id' => $w->id, 'due_date' => Carbon::parse($dto['dueDate']), 'window' => $dto['window'] ?? null,
                'priority' => $dto['priority'] ?? 'normal', 'status' => 'confirmed', 'kg' => round($kg), 'cbm' => round($kg / 200 * 10) / 10,
                'created_by_id' => $user->id, 'created_by' => $user->username,
            ]);
            foreach ($lines as $l) {
                $sl = SalesOrderLine::create(['so_id' => $so->id, 'line_no' => $l['lineNo'], 'product_id' => $l['product']->id, 'qty' => $l['qty'], 'price' => $l['price'], 'disc_pct' => $l['discPct']]);
                $this->inventory->reserve($user, $txId, ['soId' => $so->id, 'soLineId' => $sl->id, 'productId' => $sl->product_id, 'warehouseId' => $w->id, 'qty' => $sl->qty, 'referenceNumber' => $number]);
            }
            $so->update(['status' => 'allocated']);
            $this->adjustBalance($c->id, $t['total']);
            if ($quotationId) {
                Quotation::whereKey($quotationId)->update(['status' => 'converted', 'so_id' => $so->id]);
                $this->audit->status($user, 'Quotation', $quotationId, '', 'approved', 'converted', $number, $txId);
            }
            $this->audit->log($user, ['action' => 'SO.CREATE', 'entityType' => 'SalesOrder', 'entityId' => $so->id, 'entityNumber' => $number,
                'newValue' => ['customer' => $c->code, 'warehouse' => $w->code, 'lines' => $dto['lines'], 'total' => $t['total'], 'quotationId' => $quotationId], 'transactionId' => $txId]);
            $this->audit->status($user, 'SalesOrder', $so->id, $number, null, 'allocated', 'حجز FEFO', $txId);
            $this->notify->activity($user, 'SalesOrder', $so->id, $number, "أُنشئ أمر بيع {$number}".($quotationId ? ' من عرض سعر' : ' مباشر').' — حُجز المخزون FEFO وحُدّث رصيد العميل', "Sales order {$number} created and reserved", ['wm', 'disp']);

            return $so->id;
        });

        return $this->getOrder($id);
    }

    /** @return array{ok:bool} */
    public function cancelOrder(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $so = $this->findOrder($idOrNumber, ['lines', 'fos'], true);
            if (! Sm::can('SO_TRANSITIONS', $so->status, 'cancelled')) {
                throw AppError::rule('SO_CANCEL', "لا يمكن الإلغاء في الحالة «{$so->status}» — بدأ التنفيذ", 'Order cannot be cancelled at this stage');
            }
            if ($so->fos->contains(fn ($f) => ! in_array($f->status, ['alloc', 'cancelled'], true))) {
                throw AppError::rule('SO_CANCEL_FO', 'أمر التنفيذ بدأ التجهيز — لا يمكن الإلغاء', 'Fulfillment already started');
            }
            $t = $this->totals($so->lines);
            $foIds = $so->fos->pluck('id')->all();
            $this->inventory->release($so->id);
            FulfillmentOrder::where('so_id', $so->id)->update(['status' => 'cancelled']);
            PickTask::whereIn('pick_list_id', PickList::whereIn('fo_id', $foIds)->select('id'))->update(['status' => 'cancelled']);
            PickList::whereIn('fo_id', $foIds)->update(['status' => 'cancelled']);
            // an order received from another system never added to the OPS balance: credit is that system's decision
            if (! $so->source_system) {
                $this->adjustBalance($so->customer_id, -$t['total']);
            }
            $from = $so->status;
            $so->update(['status' => 'cancelled']);
            $this->audit->status($user, 'SalesOrder', $so->id, $so->number, $from, 'cancelled', $reason);
            $this->notify->activity($user, 'SalesOrder', $so->id, $so->number, "أُلغي {$so->number} — أُفرج عن الحجز".($reason ? ' — '.$reason : ''), null, ['sales']);

            return ['ok' => true];
        });
    }

    /** Retry reservation for an order waiting allocation (stock arrived). */
    public function allocateOrder(AuthUser $user, string $idOrNumber): array
    {
        $txId = $this->inventory->newTxId();

        $id = DB::transaction(function () use ($user, $idOrNumber, $txId) {
            $so = $this->findOrder($idOrNumber, ['lines'], true);
            if ($so->status !== 'confirmed') {
                throw AppError::rule('SO_NOT_WAITING', 'الأمر ليس بانتظار التخصيص', 'Order is not waiting allocation');
            }
            foreach ($so->lines as $sl) {
                $need = $sl->qty - $sl->reserved_qty;
                if ($need > 0) {
                    $this->inventory->reserve($user, $txId, ['soId' => $so->id, 'soLineId' => $sl->id, 'productId' => $sl->product_id, 'warehouseId' => $so->warehouse_id, 'qty' => $need, 'referenceNumber' => $so->number]);
                }
            }
            $so->update(['status' => 'allocated']);
            $this->audit->status($user, 'SalesOrder', $so->id, $so->number, 'confirmed', 'allocated', 'حجز FEFO', $txId);

            return $so->id;
        });

        return $this->getOrder($id);
    }

    /**
     * Create the fulfillment order (execution document) + pick list from allocations. Idempotent per SO.
     * Joins the caller's transaction when there is one (consolidation → readypick).
     */
    public function fulfill(AuthUser $user, string $idOrNumber): FulfillmentOrder
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $so = $this->findOrder($idOrNumber, ['lines' => fn ($q) => $q->orderBy('line_no'), 'lines.allocations' => fn ($q) => $q->where('status', 'active')->orderBy('created_at')->orderBy('id'), 'lines.allocations.batch', 'fos', 'customer'], true);
            $existing = $so->fos->first(fn ($f) => $f->status !== 'cancelled');
            if ($existing) {
                return $existing;
            }
            if ($so->status !== 'allocated') {
                throw AppError::rule('SO_NOT_ALLOCATED', 'أمر البيع غير مخصص — لا يمكن إنشاء أمر تنفيذ', 'Order not allocated');
            }
            $number = $this->numbering->next('FO');
            $fo = FulfillmentOrder::create([
                'number' => $number, 'so_id' => $so->id, 'customer_id' => $so->customer_id, 'warehouse_id' => $so->warehouse_id, 'status' => 'alloc',
                'cartons' => (int) $so->lines->sum('qty'), 'weight_kg' => $so->kg, 'cbm' => $so->cbm, 'zone_ar' => $so->customer->zone, 'zone_en' => $so->customer->zone,
            ]);
            $foLineBySoLine = [];
            foreach ($so->lines as $l) {
                $foLineBySoLine[$l->id] = FoLine::create(['fo_id' => $fo->id, 'line_no' => $l->line_no, 'so_line_id' => $l->id, 'product_id' => $l->product_id, 'qty' => $l->qty])->id;
            }
            $pl = PickList::create(['number' => $this->numbering->next('PL'), 'warehouse_id' => $so->warehouse_id, 'fo_id' => $fo->id]);

            // pick tasks: one per allocation, sequenced by zone → aisle → rack → bin (location sequencing)
            $tasks = [];
            foreach ($so->lines as $l) {
                foreach ($l->allocations as $al) {
                    $bin = Bin::with(['zone', 'rack'])->findOrFail($al->bin_id);
                    $tasks[] = [
                        'fo_line_id' => $foLineBySoLine[$l->id], 'product_id' => $l->product_id, 'bin_id' => $al->bin_id, 'batch_id' => $al->batch_id,
                        'batch_no' => $al->batch?->batch_no, 'qty' => $al->qty - $al->picked_qty,
                        'key' => [$bin->zone->code, str_pad((string) ($bin->rack?->aisle ?: 0), 2, '0', STR_PAD_LEFT), (string) ($bin->rack?->rack_no ?: 0), $bin->code],
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

        return Shape::apply($x->toArray(), self::OC_SHAPE) + ['history' => $this->history('OrderConsolidation', $x->id)];
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
            throw AppError::notFound('SO_NOT_FOUND', 'أحد الطلبات غير موجود');
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

    public function addRemoveOrder(AuthUser $user, string $idOrNumber, string $orderNumber, bool $add): array
    {
        $oc = $this->findConsolidation($idOrNumber);
        if (! in_array($oc->status, ['open', 'consolidating'], true)) {
            throw AppError::rule('OC_LOCKED', 'لا يمكن تعديل الدفعة بعد بدء التجهيز');
        }
        $so = SalesOrder::where('number', $orderNumber)->first() ?? throw AppError::notFound('SO_NOT_FOUND', 'الطلب غير موجود');
        if ($add && $so->warehouse_id !== $oc->warehouse_id) {
            throw AppError::rule('OC_WAREHOUSE', 'مستودع مختلف');
        }
        DB::transaction(function () use ($user, $oc, $so, $orderNumber, $add) {
            $so->update(['consolidation_id' => $add ? $oc->id : null]);
            $this->audit->log($user, ['action' => $add ? 'OC.ADD' : 'OC.REMOVE', 'entityType' => 'OrderConsolidation', 'entityId' => $oc->id, 'entityNumber' => $oc->number, 'newValue' => $orderNumber]);
        });

        return $this->getConsolidation($oc->id);
    }

    /**
     * Advance the consolidation batch; `readypick` creates fulfillment orders + pick lists for every order; later
     * stages are validated against FO progress.
     */
    public function advanceConsolidation(AuthUser $user, string $idOrNumber): array
    {
        $oc = $this->findConsolidation($idOrNumber);
        $next = config('scm.OC_NEXT')[$oc->status] ?? null;
        if (! $next) {
            throw AppError::rule('OC_DONE', 'الدفعة مكتملة');
        }

        DB::transaction(function () use ($user, $oc, $next) {
            if ($next === 'readypick') {
                foreach ($oc->orders as $so) {
                    if ($so->status === 'confirmed') {
                        throw AppError::rule('OC_UNALLOCATED', "الطلب {$so->number} بانتظار التخصيص — خصّصه أولًا", 'Order waiting allocation');
                    }
                    $this->fulfill($user, $so->id);
                }
            }
            $fos = FulfillmentOrder::whereIn('so_id', $oc->orders->pluck('id')->all())->where('status', '!=', 'cancelled')->get();
            if ($next === 'packed' && $fos->contains(fn ($f) => ! in_array($f->status, ['packed', 'loaded', 'onroute', 'delivered'], true))) {
                throw AppError::rule('OC_NOT_PACKED', 'لم تكتمل تعبئة كل الطلبات', 'Not all orders are packed');
            }
            if ($next === 'dispatched' && $fos->contains(fn ($f) => ! $f->trip_id)) {
                throw AppError::rule('OC_NO_TRIP', 'أنشئ رحلة تضم كل أوامر التنفيذ أولًا (التخطيط → رحلة)', 'Create a trip with all fulfillment orders first');
            }
            if ($next === 'done' && $fos->contains(fn ($f) => ! in_array($f->status, ['delivered', 'partial', 'failed'], true))) {
                throw AppError::rule('OC_NOT_DELIVERED', 'لم يكتمل تسليم كل الطلبات');
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

    /**
     * @param  array<int, array{sku:string, qty:int|string, price:float|int|string, discPct?:float|int|string|null}>  $lines
     * @return array<int, array{lineNo:int, product:Product, qty:int, price:float, discPct:float}>
     */
    private function resolveLines(array $lines): array
    {
        $products = Product::whereIn('sku', array_column($lines, 'sku'))->get()->keyBy('sku');
        $out = [];
        foreach (array_values($lines) as $i => $l) {
            $p = $products->get($l['sku']) ?? throw AppError::validation('SKU_NOT_FOUND', "المنتج {$l['sku']} غير موجود", "Unknown SKU {$l['sku']}");
            if (! $p->active) {
                throw AppError::validation('SKU_INACTIVE', "المنتج {$p->name_ar} موقوف");
            }
            $out[] = ['lineNo' => $i + 1, 'product' => $p, 'qty' => (int) $l['qty'], 'price' => (float) $l['price'], 'discPct' => (float) ($l['discPct'] ?? 0)];
        }

        return $out;
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
            throw AppError::notFound('CUSTOMER_NOT_FOUND', "العميل {$code} غير موجود أو موقوف");
        }

        return $c;
    }

    /** Customer balance ± amount on a locked row (no lost updates between concurrent orders). */
    private function adjustBalance(string $customerId, float $delta): void
    {
        $c = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
        $c->update(['balance' => round((float) $c->balance + $delta, 2)]);
    }

    private function findQuotation(string $idOrNumber): Quotation
    {
        return Quotation::with(['customer', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product.baseUom', 'so'])
            ->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('QT_NOT_FOUND', 'عرض السعر غير موجود');
    }

    /** $lock = SELECT … FOR UPDATE on the order row (call inside a transaction) so concurrent actions on one order serialise. */
    private function findOrder(string $idOrNumber, array $with, bool $lock = false): SalesOrder
    {
        return SalesOrder::with($with)->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))
            ->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('SO_NOT_FOUND', 'أمر البيع غير موجود');
    }

    private function findConsolidation(string $idOrNumber): OrderConsolidation
    {
        return OrderConsolidation::with(['warehouse', 'orders' => fn ($q) => $q->orderBy('number'), 'orders.customer', 'orders.lines' => fn ($q) => $q->orderBy('line_no'), 'orders.lines.product', 'orders.fos', 'trips'])
            ->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('OC_NOT_FOUND', 'دفعة التجميع غير موجودة');
    }

    /** Eager loads of the sales-order document (lines by line number, active allocations in FEFO/creation order). */
    private static function soWith(): array
    {
        return [
            'customer', 'warehouse', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product',
            'lines.allocations' => fn ($q) => $q->where('status', 'active')->orderBy('created_at')->orderBy('id'), 'lines.allocations.bin', 'lines.allocations.batch',
            'fos' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'fos.trip', 'quotation', 'consolidation',
        ];
    }

    private function history(string $entityType, string $entityId): array
    {
        return StatusHistory::where('entity_type', $entityType)->where('entity_id', $entityId)->orderBy('at')->orderBy('id')->get()->map->toArray()->all();
    }

    /** 236000.5 → "236,000.5" (JS toLocaleString('en-US')). */
    private static function fmt(float $n): string
    {
        $s = number_format($n, 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
