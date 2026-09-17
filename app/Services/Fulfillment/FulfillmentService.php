<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\InventoryAllocation;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\LoadingLine;
use App\Models\LoadingPlan;
use App\Models\OpsException;
use App\Models\Package;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StagingEntry;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Sales\Shape;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Facades\DB;

/**
 * Fulfillment execution: FO (alloc) → Pick list (scan location → scan product → qty, partial/short allowed, no over-pick,
 * no wrong bin/product, no negative stock) → Packing (packages, weight, volume) → Outbound Staging (STG-OUT) → Loading
 * plan (reverse delivery order, vehicle scan, order scan, cumulative weight/volume, vehicle state) → Dispatch
 * (transaction: trip/vehicle/driver/orders → on trip, timestamps, audit, outbox event). Every stock change goes through
 * the inventory engine: pick = bin → PACK · pack = PACK → STG-OUT · load = STG-OUT → vehicle (leaves warehouse stock).
 */
class FulfillmentService
{
    private const FO_SHAPE = [
        'customer' => ['code', 'nameAr', 'nameEn', 'zone', 'contact', 'address'], 'warehouse' => ['code', 'nameAr'],
        'so' => ['number', 'status', 'window', 'dueDate', 'priority'],
        'lines.*.product' => ['sku', 'nameAr', 'nameEn', 'weightKg', 'storageClass', 'barcodes'],
        'pickLists.*.tasks.*.bin' => ['code', 'zone'], 'pickLists.*.tasks.*.bin.zone' => ['code'], 'pickLists.*.tasks.*.product' => ['sku', 'nameAr'],
        'trip' => ['number', 'status', 'vehicle', 'driver'], 'trip.vehicle' => ['code', 'plateAr', 'plateEn'], 'trip.driver' => ['code', 'nameAr'],
        'pods.*' => ['number', 'result', 'at', 'receiverName'], 'returns.*' => ['number', 'status'],
    ];

    private const PL_SHAPE = ['fo' => ['number', 'status', 'customer'], 'fo.customer' => ['nameAr', 'zone'], 'tasks.*.bin' => ['code'], 'tasks.*.product' => ['sku', 'nameAr']];

    private const MOVEMENT_SHAPE = ['*' => ['number', 'type', 'qty', 'batchNo', 'createdAt', 'srcBin', 'dstBin', 'product'], '*.srcBin' => ['code'], '*.dstBin' => ['code'], '*.product' => ['sku']];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly InventoryService $inventory,
    ) {}

    /** @param  array{status?:?string, warehouse?:?string, trip?:?string, customer?:?string}  $filters */
    public function list(Paging $page, array $filters): array
    {
        $query = FulfillmentOrder::with(self::foWith())->orderBy('seq')->orderByDesc('created_at')->orderByDesc('id');
        if (! empty($filters['status'])) {
            $filters['status'] === 'active' ? $query->whereIn('status', ['alloc', 'picking', 'picked', 'packed', 'loaded']) : $query->where('status', $filters['status']);
        }
        foreach (['warehouse' => 'code', 'trip' => 'number', 'customer' => 'code'] as $relation => $column) {
            if (! empty($filters[$relation])) {
                $query->whereHas($relation, fn ($r) => $r->where($column, $filters[$relation]));
            }
        }
        if ($page->q) {
            $query->where(fn ($w) => $w->where('number', 'like', "%{$page->q}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name_ar', 'like', "%{$page->q}%"))
                ->orWhereHas('so', fn ($s) => $s->where('number', 'like', "%{$page->q}%")));
        }

        return $page->paginate($query, fn (FulfillmentOrder $fo) => Shape::apply($fo->toArray(), self::FO_SHAPE));
    }

    public function get(string $idOrNumber): array
    {
        $fo = FulfillmentOrder::with(self::foWith())->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('FO_NOT_FOUND', 'أمر التنفيذ غير موجود');
        $movements = InventoryMovement::with(['srcBin', 'dstBin', 'product'])->where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)
            ->orderBy('created_at')->orderBy('number')->get()->map->toArray()->all();

        return Shape::apply($fo->toArray(), self::FO_SHAPE) + [
            'history' => StatusHistory::where('entity_type', 'FulfillmentOrder')->where('entity_id', $fo->id)->orderBy('at')->orderBy('id')->get()->map->toArray()->all(),
            'movements' => Shape::apply($movements, self::MOVEMENT_SHAPE),
        ];
    }

    // ───────────── picking ─────────────

    /** @param  array{warehouse?:?string, status?:?string}  $filters */
    public function pickLists(Paging $page, array $filters): array
    {
        $query = PickList::with(['fo.customer', 'tasks' => fn ($q) => $q->orderBy('seq'), 'tasks.bin', 'tasks.product'])
            ->where('status', ($filters['status'] ?? null) ?: 'open')->orderBy('created_at')->orderBy('id');
        if (! empty($filters['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $filters['warehouse']));
        }

        return $page->paginate($query, fn (PickList $pl) => Shape::apply($pl->toArray(), self::PL_SHAPE));
    }

    /**
     * Confirm one pick task: right bin, right product (SKU or barcode), no over-pick, no expired / quarantined batch,
     * no negative stock. Moves bin → PACK and consumes the allocation.
     *
     * @param  array{scannedBin?:?string, scannedProduct?:?string, qty?:int|string|null}  $dto
     */
    public function pick(AuthUser $user, string $taskId, array $dto): array
    {
        $exists = PickTask::whereKey($taskId)->exists();
        if (! $exists) {
            throw AppError::notFound('TASK_NOT_FOUND', 'مهمة التجهيز غير موجودة');
        }
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $taskId, $dto, $txId) {
            // the task row is locked so a double scan cannot pick the same quantity twice
            $t = PickTask::with(['bin', 'product.barcodes', 'batch', 'foLine.fo', 'pickList'])->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $fo = $t->foLine->fo;
            if (! in_array($fo->status, ['alloc', 'picking'], true)) {
                throw AppError::rule('FO_STATE', "الطلب ليس في مرحلة التجهيز ({$fo->status})", "Order is not in picking stage ({$fo->status})");
            }
            if ($t->status === 'done') {
                throw AppError::conflict('TASK_DONE', 'السطر مكتمل — لا تكرار', 'Task already completed');
            }
            if ($t->status === 'cancelled') {
                throw AppError::rule('TASK_CANCELLED', 'المهمة ملغاة');
            }
            $scannedBin = trim((string) ($dto['scannedBin'] ?? ''));
            $sp = trim((string) ($dto['scannedProduct'] ?? ''));
            if ($scannedBin === '' || $sp === '') {
                throw AppError::validation('SCAN_REQUIRED', 'امسح الموقع والمنتج أولًا (Scan Location → Scan Product)', 'Scan location and product first');
            }
            if (mb_strtoupper($scannedBin) !== mb_strtoupper($t->bin->code)) {
                throw AppError::rule('WRONG_BIN', "Scan موقع خاطئ: {$scannedBin} — المطلوب {$t->bin->code}", "Wrong location {$scannedBin}, expected {$t->bin->code}");
            }
            if (mb_strtoupper($sp) !== mb_strtoupper($t->product->sku) && ! $t->product->barcodes->contains(fn ($b) => $b->barcode === $sp)) {
                throw AppError::rule('WRONG_PRODUCT', "Scan منتج خاطئ: {$sp} — المطلوب {$t->product->sku}", "Wrong product {$sp}, expected {$t->product->sku}");
            }
            $remaining = $t->qty - $t->picked_qty;
            $q = isset($dto['qty']) ? (int) $dto['qty'] : $remaining;
            if ($q <= 0) {
                throw AppError::validation('BAD_QTY', 'كمية غير صالحة', 'Invalid quantity');
            }
            if ($q > $remaining) {
                throw AppError::rule('OVER_PICK', "مرفوض: {$q} أكبر من المتبقي ({$remaining}) — لا Over-Picking", "{$q} exceeds remaining {$remaining} — no over-picking");
            }
            if ($t->batch?->expiry_date && $t->batch->expiry_date->lt(now())) {
                throw AppError::rule('EXPIRED_BATCH', "الدفعة {$t->batch->batch_no} منتهية — لا يُصرف منها", 'Expired batch cannot be picked');
            }

            $row = InventoryBalance::where('product_id', $t->product_id)->where('bin_id', $t->bin_id)->where('batch_key', $t->batch_id ?? '')->first();
            if (! $row || $row->on_hand < $q) {
                $onHand = $row?->on_hand ?? 0;
                throw AppError::rule('NEGATIVE_STOCK', "الرصيد في {$t->bin->code} غير كافٍ ({$onHand}) — لا رصيد سالب", 'Insufficient stock at bin');
            }
            if ($row->quarantine) {
                throw AppError::rule('QUARANTINED', 'الدفعة '.($t->batch_no ?? '').' محجورة — لا يُصرف منها', 'Quarantined batch');
            }
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $mv = $this->inventory->post($user, $txId, [
                'type' => 'pick', 'productId' => $t->product_id, 'batchId' => $t->batch_id, 'qty' => $q,
                'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $t->bin_id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id],
                'consumeReserved' => $q, 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number],
                'note' => "{$t->pickList->number} · {$fo->number}".($q < $remaining ? ' · جزئي' : ''),
            ]);
            // consume the allocation of this line / bin / batch
            $al = $this->activeAllocation($t);
            $al?->update(['picked_qty' => $al->picked_qty + $q, 'status' => $al->picked_qty + $q >= $al->qty ? 'consumed' : 'active']);
            if ($t->foLine->so_line_id) {
                SalesOrderLine::whereKey($t->foLine->so_line_id)->update(['picked_qty' => DB::raw("picked_qty + {$q}")]);
            }
            $before = $t->picked_qty;
            $np = $before + $q;
            $lineDone = $np >= $t->qty;
            $t->update(['picked_qty' => $np, 'status' => $lineDone ? 'done' : 'partial', 'picked_by_id' => $user->id, 'picked_by' => $user->username, 'picked_at' => now()]);
            FoLine::whereKey($t->fo_line_id)->update(['picked_qty' => DB::raw("picked_qty + {$q}")]);
            $allDone = PickTask::where('pick_list_id', $t->pick_list_id)->whereIn('status', ['open', 'partial'])->count() === 0;
            $newStatus = $allDone ? 'picked' : 'picking';
            if ($fo->status !== $newStatus) {
                $from = $fo->status;
                $fo->update(['status' => $newStatus]);
                $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, $newStatus, null, $txId);
                if ($fo->so_id) {
                    SalesOrder::whereKey($fo->so_id)->update(['status' => $newStatus]);
                }
            }
            if ($allDone) {
                PickList::whereKey($t->pick_list_id)->update(['status' => 'done', 'completed_at' => now()]);
            }
            $this->audit->log($user, ['action' => 'PICK.CONFIRM', 'entityType' => 'PickTask', 'entityId' => $t->id, 'entityNumber' => $t->pickList->number,
                'field' => $t->product->sku, 'oldValue' => $before, 'newValue' => $np, 'transactionId' => $txId]);
            $left = $t->qty - $np;

            return [
                'task' => $t->id, 'picked' => $np, 'remaining' => $left, 'lineDone' => $lineDone, 'orderDone' => $allDone, 'movement' => $mv->number,
                'message' => $allDone ? "اكتمل تجهيز {$fo->number} — انتقل لمحطة التعبئة" : ($lineDone ? 'تم السطر — Scan الموقع والمنتج مطابق ✓' : "تجهيز جزئي: {$np} / {$t->qty} — المتبقي {$left}"),
            ];
        });
    }

    /**
     * Short pick: close the task with less than required, raise an exception, keep the order pickable for the rest.
     *
     * @return array{ok:bool, shortQty:int}
     */
    public function shortPick(AuthUser $user, string $taskId, string $reason): array
    {
        if (! PickTask::whereKey($taskId)->exists()) {
            throw AppError::notFound('TASK_NOT_FOUND', 'مهمة التجهيز غير موجودة');
        }

        return DB::transaction(function () use ($user, $taskId, $reason) {
            $t = PickTask::with(['foLine.fo', 'product', 'pickList'])->whereKey($taskId)->lockForUpdate()->firstOrFail();
            if (in_array($t->status, ['done', 'cancelled'], true)) {
                throw AppError::conflict('TASK_CLOSED', 'المهمة مغلقة');
            }
            $fo = $t->foLine->fo;
            $t->update(['status' => 'short']);
            $shortQty = $t->qty - $t->picked_qty;
            // release the unpicked allocation so stock is not stuck
            $al = $this->activeAllocation($t);
            if ($al) {
                $al->update(['status' => 'released']);
                $this->unreserve($t, $shortQty);
            }
            FoLine::whereKey($t->fo_line_id)->update(['qty' => DB::raw("qty - {$shortQty}")]);
            $this->exceptions->raise($user, [
                'kind' => 'missing', 'severity' => 'w', 'ownerRole' => 'wm', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'textAr' => "نقص تجهيز {$t->product->name_ar}: {$shortQty} غير متوفرة في الموقع — {$reason}", 'textEn' => "Short pick {$t->product->sku}: {$shortQty} — {$reason}",
            ]);
            $open = PickTask::where('pick_list_id', $t->pick_list_id)->whereIn('status', ['open', 'partial'])->count();
            if ($open === 0) {
                $from = $fo->status;
                $fo->update(['status' => 'picked']);
                PickList::whereKey($t->pick_list_id)->update(['status' => 'done', 'completed_at' => now()]);
                $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, $from, 'picked', 'short pick');
            }
            $this->audit->log($user, ['action' => 'PICK.SHORT', 'entityType' => 'PickTask', 'entityId' => $t->id, 'entityNumber' => $t->pickList->number, 'newValue' => ['shortQty' => $shortQty, 'reason' => $reason]]);

            return ['ok' => true, 'shortQty' => $shortQty];
        });
    }

    // ───────────── packing ─────────────

    /**
     * @param  array{cartons?:int|string, weightKg?:float|int|string|null, volumeM3?:float|int|string|null}  $dto
     * @return array{package:string, status:string}
     */
    public function pack(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $idOrNumber, $dto, $txId) {
            $fo = FulfillmentOrder::with(['lines' => fn ($q) => $q->orderBy('line_no')])->where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('FO_NOT_FOUND', 'أمر التنفيذ غير موجود');
            if ($fo->status !== 'picked') {
                throw AppError::rule('PACK_STATE', "التعبئة تتطلب اكتمال التجهيز أولًا ({$fo->status})", "Packing requires a fully picked order ({$fo->status})");
            }
            $cartons = (int) ($dto['cartons'] ?? 1);
            $weightKg = isset($dto['weightKg']) ? (float) $dto['weightKg'] : $fo->weight_kg;
            $volumeM3 = isset($dto['volumeM3']) ? (float) $dto['volumeM3'] : null;
            $pack = $this->inventory->specialBin($fo->warehouse_id, 'PACK');
            $out = $this->inventory->specialBin($fo->warehouse_id, 'STG-OUT');
            foreach ($fo->lines as $l) {
                if ($l->picked_qty <= 0) {
                    continue;
                }
                // move the picked quantity PACK → STG-OUT per batch (batches come from the pick tasks)
                foreach (PickTask::where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->orderBy('seq')->get() as $t) {
                    $this->inventory->post($user, $txId, [
                        'type' => 'pack', 'productId' => $l->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty,
                        'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $pack->id], 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $out->id],
                        'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => 'تعبئة → Outbound Staging',
                    ]);
                }
                $l->update(['packed_qty' => $l->picked_qty]);
            }
            $pkg = Package::create([
                'number' => $this->numbering->next('PKG'), 'fo_id' => $fo->id, 'cartons' => $cartons, 'weight_kg' => $weightKg, 'volume_m3' => $volumeM3,
                'label_ref' => "LBL-{$fo->number}", 'packed_by_id' => $user->id, 'packed_by' => $user->username,
            ]);
            $fo->update(['status' => 'packed', 'cartons' => $cartons, 'weight_kg' => $weightKg, 'cbm' => $volumeM3 ?? $fo->cbm, 'packed_at' => now(), 'staged_at' => now()]);
            StagingEntry::create(['direction' => 'out', 'bin_id' => $out->id, 'reference_type' => 'FulfillmentOrder', 'reference_id' => $fo->id, 'reference_number' => $fo->number, 'qty' => (int) $fo->lines->sum('picked_qty')]);
            if ($fo->so_id) {
                SalesOrder::whereKey($fo->so_id)->update(['status' => 'packed']);
            }
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'picked', 'packed', $pkg->number, $txId);
            $this->notify->activity($user, 'FulfillmentOrder', $fo->id, $fo->number, "اكتملت تعبئة {$fo->number} — {$pkg->number} · طُبع Packing Slip وShipping Label، وجاهز للتحميل", "Packed {$fo->number}", ['disp']);
            $this->notify->event('OrderPacked', ['fo' => $fo->number, 'so' => $fo->so_id]);

            return ['package' => $pkg->number, 'status' => 'packed'];
        });
    }

    // ───────────── loading ─────────────

    /** Loading plan for a trip: orders in REVERSE delivery order (last stop loads first) with cumulative capacity. */
    public function loadingPlan(string $tripIdOrNumber): array
    {
        $trip = $this->findTrip($tripIdOrNumber, ['vehicle', 'driver', 'stops' => fn ($q) => $q->orderBy('seq'), 'stops.fo.packages']);
        $cbmPerCarton = (float) $this->settings->get('loading.cbmPerCarton');
        $rows = $trip->stops->filter(fn ($s) => $s->fo)->sortByDesc('seq')->values()->map(fn ($s) => [
            'stopSeq' => $s->seq, 'customer' => $s->customer_ar, 'fo' => $s->fo->number, 'status' => $s->fo->status, 'loaded' => $s->fo->loaded,
            'loadedAt' => $s->fo->loaded_at, 'cartons' => $s->fo->cartons, 'kg' => $s->fo->weight_kg, 'cbm' => self::cbmOf($s->fo, $cbmPerCarton),
            'packages' => $s->fo->packages->count(), 'ready' => $s->fo->status === 'packed',
        ]);
        $loaded = $rows->where('loaded', true);
        $loadedKg = $loaded->sum('kg');
        $loadedCbm = $loaded->sum('cbm');
        $v = $trip->vehicle;

        return [
            'trip' => [
                'number' => $trip->number, 'status' => $trip->status,
                'vehicle' => $v ? ['code' => $v->code, 'plateAr' => $v->plate_ar, 'plateEn' => $v->plate_en, 'state' => $v->state, 'maxKg' => $v->max_kg, 'maxCbm' => $v->max_cbm, 'pallets' => $v->pallets, 'kind' => $v->kind] : null,
                'driver' => $trip->driver ? ['code' => $trip->driver->code, 'nameAr' => $trip->driver->name_ar] : null, 'tempNeed' => $trip->temp_need,
            ],
            'rows' => $rows->all(),
            'totals' => [
                'kg' => $rows->sum('kg'), 'cbm' => round($rows->sum('cbm') * 10) / 10, 'loadedKg' => $loadedKg, 'loadedCbm' => round($loadedCbm * 10) / 10,
                'utilKg' => $v && $v->max_kg > 0 ? (int) round($loadedKg / $v->max_kg * 100) : null, 'utilCbm' => $v && $v->max_cbm > 0 ? (int) round($loadedCbm / $v->max_cbm * 100) : null,
            ],
            'nextToLoad' => $rows->first(fn ($r) => ! $r['loaded'] && $r['ready'])['fo'] ?? null,
        ];
    }

    /**
     * Load one order onto the assigned vehicle: vehicle / order scan, vehicle state, reverse stop order, cumulative
     * weight and volume, temperature match. STG-OUT → out of warehouse stock.
     *
     * @param  array{foNumber:string, scannedVehicle?:?string, scannedOrder?:?string}  $dto
     */
    public function load(AuthUser $user, string $tripIdOrNumber, array $dto): array
    {
        $trip = $this->findTrip($tripIdOrNumber, ['vehicle', 'stops.fo', 'orders.fo']);
        if (! in_array($trip->status, ['planned', 'vassigned', 'dassigned', 'loading', 'ready'], true)) {
            throw AppError::rule('TRIP_STATE', "لا يمكن التحميل — حالة الرحلة {$trip->status}", 'Trip not loadable');
        }
        $veh = $trip->vehicle ?? throw AppError::rule('NO_VEHICLE', 'لا مركبة مسندة للرحلة — أسند المركبة أولًا', 'No vehicle assigned');
        $foNumber = trim($dto['foNumber']);
        $to = $trip->orders->first(fn ($o) => $o->fo && mb_strtoupper($o->fo->number) === mb_strtoupper($foNumber))
            ?? throw AppError::rule('FO_NOT_ON_TRIP', "الطلب {$dto['foNumber']} غير تابع لهذه الرحلة", 'Order is not assigned to this trip');
        $fo = $to->fo;
        if ($to->loaded || $fo->loaded) {
            throw AppError::conflict('ALREADY_LOADED', "{$fo->number} محمَّل مسبقًا — لا تكرار", 'Order already loaded');
        }
        if ($fo->status !== 'packed') {
            throw AppError::rule('NOT_PACKED', 'لا يمكن التحميل قبل اكتمال التعبئة', 'Order is not packed');
        }
        $norm = fn (?string $s) => preg_replace('/\s+/u', '', mb_strtoupper((string) $s));
        $scannedVehicle = $dto['scannedVehicle'] ?? null;
        $scannedOrder = $dto['scannedOrder'] ?? null;
        $tripRef = ['kind' => 'wrongveh', 'severity' => 'c', 'ownerRole' => 'disp', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number];
        // Rejections below raise their exception first: there is no open transaction here, so the exception row survives the rejection.
        if ($scannedVehicle !== null && trim($scannedVehicle) !== '' && ! in_array($norm($scannedVehicle), array_map($norm, [$veh->code, $veh->plate_ar, $veh->plate_en]), true)) {
            $this->exceptions->raise($user, $tripRef + ['documentType' => 'FulfillmentOrder', 'documentId' => $fo->id, 'documentNumber' => $fo->number,
                'textAr' => "مسح شاحنة {$scannedVehicle} بدل {$veh->code} عند تحميل {$fo->number}", 'textEn' => "Wrong truck scan {$scannedVehicle} instead of {$veh->code}"]);
            $plate = $veh->plate_en ?: $veh->plate_ar;
            throw AppError::rule('WRONG_VEHICLE', "مركبة خاطئة — المسح «{$scannedVehicle}» لا يطابق المركبة المسندة {$veh->code} ({$plate}). التحميل مرفوض", "Wrong vehicle: {$scannedVehicle} ≠ {$veh->code}");
        }
        if ($scannedOrder !== null && trim($scannedOrder) !== '' && $norm($scannedOrder) !== $norm($fo->number)) {
            throw AppError::rule('WRONG_ORDER', "Scan طلب خاطئ: {$scannedOrder} ≠ {$fo->number}", 'Wrong order scan');
        }
        if (in_array($veh->state, ['maintenance', 'breakdown', 'oos', 'inactive', 'onroute', 'returning'], true)) {
            $this->exceptions->raise($user, $tripRef + ['textAr' => "محاولة تحميل {$fo->number} على {$veh->code} وهي بحالة {$veh->state}", 'textEn' => "Load attempt on {$veh->code} in state {$veh->state}"]);
            throw AppError::rule('VEHICLE_STATE', "مركبة خاطئة — {$veh->code} حالتها {$veh->state}؛ التحميل مرفوض", "Vehicle {$veh->code} is {$veh->state}");
        }
        // reverse order: the last stop in the route loads first
        $first = $trip->stops->filter(fn ($s) => $s->fo && $s->fo->status === 'packed' && ! $s->fo->loaded)->sortByDesc('seq')->first();
        if ($first && $first->fo->id !== $fo->id) {
            throw AppError::rule('LOAD_ORDER', "ترتيب التحميل عكسي — حمّل {$first->fo->number} ({$first->customer_ar}) أولًا: آخر عميل في المسار يُحمّل أولًا", "Reverse loading order — load {$first->fo->number} first");
        }
        $cbmPerCarton = (float) $this->settings->get('loading.cbmPerCarton');
        $loadedFos = $trip->orders->filter(fn ($o) => $o->loaded && $o->fo)->map(fn ($o) => $o->fo)->values();
        $kg = $loadedFos->sum('weight_kg') + $fo->weight_kg;
        $cbm = $loadedFos->sum(fn ($f) => self::cbmOf($f, $cbmPerCarton)) + self::cbmOf($fo, $cbmPerCarton);
        $capacity = ['kind' => 'capacity'] + $tripRef;
        if ($kg > $veh->max_kg) {
            $this->exceptions->raise($user, $capacity + ['textAr' => "حمولة {$veh->code} ".number_format(round($kg)).' كجم تتجاوز '.self::fmt($veh->max_kg), 'textEn' => "Over weight on {$veh->code}"]);
            throw AppError::rule('OVER_WEIGHT', 'تجاوز السعة — '.number_format(round($kg)).' كجم > '.self::fmt($veh->max_kg).' كجم. التحميل مرفوض', 'Over weight');
        }
        if ($veh->max_cbm && $cbm > $veh->max_cbm) {
            $this->exceptions->raise($user, $capacity + ['textAr' => "حجم {$veh->code} ".number_format($cbm, 1, '.', '')." م³ يتجاوز {$veh->max_cbm}", 'textEn' => "Over volume on {$veh->code}"]);
            throw AppError::rule('OVER_VOLUME', 'تجاوز الحجم — '.number_format($cbm, 1, '.', '')." م³ > {$veh->max_cbm} م³. التحميل مرفوض", 'Over volume');
        }
        // temperature: frozen/chilled goods on a dry vehicle
        $lines = FoLine::with('product')->where('fo_id', $fo->id)->orderBy('line_no')->get();
        $needs = $lines->map(fn ($l) => $l->product->storage_class);
        if ($needs->contains('frozen') && $veh->kind !== 'reefer') {
            throw AppError::rule('TEMP_MISMATCH', "{$fo->number} يحتوي أصنافًا مجمدة — المركبة {$veh->code} ليست مجمدة −18°. التحميل مرفوض", 'Frozen goods need a reefer vehicle');
        }
        if ($needs->contains('chilled') && $veh->kind === 'dry') {
            throw AppError::rule('TEMP_MISMATCH', "{$fo->number} يحتوي أصنافًا مبردة — المركبة {$veh->code} جافة. التحميل مرفوض", 'Chilled goods need a chilled vehicle');
        }
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $trip, $veh, $to, $fo, $lines, $loadedFos, $kg, $cbm, $cbmPerCarton, $scannedVehicle, $scannedOrder, $txId) {
            // a double scan must not take the goods out of STG-OUT twice
            if (TripOrder::whereKey($to->id)->lockForUpdate()->value('loaded')) {
                throw AppError::conflict('ALREADY_LOADED', "{$fo->number} محمَّل مسبقًا — لا تكرار", 'Order already loaded');
            }
            $out = $this->inventory->specialBin($fo->warehouse_id, 'STG-OUT');
            foreach ($lines as $l) {
                foreach (PickTask::where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->orderBy('seq')->get() as $t) {
                    $this->inventory->post($user, $txId, [
                        'type' => 'load', 'productId' => $l->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty, 'from' => ['warehouseId' => $fo->warehouse_id, 'binId' => $out->id],
                        'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => "STG-OUT → {$veh->code} · {$trip->number} · Loading scan",
                    ]);
                }
            }
            $plan = LoadingPlan::where('trip_id', $trip->id)->where('status', 'open')->first()
                ?? LoadingPlan::create(['number' => $this->numbering->next('LD'), 'trip_id' => $trip->id, 'vehicle_id' => $veh->id]);
            $seq = $loadedFos->count() + 1;
            $totalCbm = round($cbm * 10) / 10;
            LoadingLine::create([
                'loading_plan_id' => $plan->id, 'fo_id' => $fo->id, 'seq' => $seq, 'weight_kg' => $fo->weight_kg, 'cbm' => self::cbmOf($fo, $cbmPerCarton),
                'scanned_vehicle' => $scannedVehicle, 'scanned_order' => $scannedOrder, 'status' => 'loaded', 'loaded_by_id' => $user->id, 'loaded_by' => $user->username, 'loaded_at' => now(),
            ]);
            $plan->update(['total_kg' => $kg, 'total_cbm' => $totalCbm]);
            $to->update(['loaded' => true, 'loaded_at' => now(), 'load_seq' => $seq]);
            $fo->update(['status' => 'loaded', 'loaded' => true, 'loaded_at' => now()]);
            StagingEntry::where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('status', 'waiting')->update(['status' => 'cleared', 'cleared_at' => now()]);
            if ($fo->so_id) {
                SalesOrder::whereKey($fo->so_id)->update(['status' => 'loaded']);
            }
            if ($trip->status !== 'loading') {
                $from = $trip->status;
                $trip->update(['status' => 'loading']);
                $this->audit->status($user, 'Trip', $trip->id, $trip->number, $from, 'loading', null, $txId);
            }
            if ($veh->state !== 'loading') {
                $from = $veh->state;
                $veh->update(['state' => 'loading']);
                $this->audit->status($user, 'Vehicle', $veh->id, $veh->code, $from, 'loading', $trip->number, $txId);
            }
            TripEvent::create(['trip_id' => $trip->id, 'label' => now()->format('H:i'), 'text_ar' => "تحميل {$fo->number} — ترتيب عكسي ({$seq})", 'text_en' => "Loaded {$fo->number}"]);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'packed', 'loaded', "{$veh->code} · {$trip->number}", $txId);

            return ['fo' => $fo->number, 'vehicle' => $veh->code, 'seq' => $seq, 'loadedKg' => $kg, 'loadedCbm' => $totalCbm, 'message' => "حُمّل {$fo->number} في {$veh->code} — الموقع في الشاحنة حسب تسلسل التفريغ"];
        });
    }

    /**
     * Dispatch: vehicle + driver fit, everything packed is loaded, no open critical exception on the trip. Orders that
     * were never loaded leave the trip and their stops are skipped.
     */
    public function dispatch(AuthUser $user, string $tripIdOrNumber): array
    {
        $trip = $this->findTrip($tripIdOrNumber, ['vehicle', 'driver', 'orders.fo', 'stops']);
        if (in_array($trip->status, ['dispatched', 'onroute'], true)) {
            throw AppError::conflict('DISPATCH_DUPLICATE', 'الرحلة منطلقة مسبقًا — لا تكرار', 'Trip already dispatched');
        }
        if (! in_array($trip->status, ['loading', 'ready'], true)) {
            throw AppError::rule('TRIP_STATE', "لا يمكن الإرسال — حالة الرحلة {$trip->status}", 'Trip not ready for dispatch');
        }
        $vehicle = $trip->vehicle ?? throw AppError::rule('NO_VEHICLE', 'لا مركبة مسندة', 'No vehicle assigned');
        $driver = $trip->driver ?? throw AppError::rule('NO_DRIVER', 'لا سائق مسند — Dispatch مرفوض', 'No driver assigned');
        if (in_array($vehicle->state, ['maintenance', 'breakdown', 'oos', 'inactive'], true)) {
            throw AppError::rule('VEHICLE_STATE', "المركبة {$vehicle->code} في حالة {$vehicle->state} — Dispatch مرفوض", 'Vehicle unavailable');
        }
        if ($driver->blocked || $driver->state === 'off' || ($driver->license_expiry && $driver->license_expiry->lt(now()))) {
            throw AppError::rule('DRIVER_STATE', 'السائق غير متاح (وثائق/وردية) — Dispatch مرفوض', 'Driver unavailable');
        }
        $loaded = $trip->orders->filter(fn ($o) => $o->loaded)->values();
        if ($loaded->isEmpty()) {
            throw AppError::rule('NOTHING_LOADED', 'لا طلبات محمّلة', 'Nothing loaded');
        }
        $packedNotLoaded = $trip->orders->filter(fn ($o) => $o->fo->status === 'packed' && ! $o->loaded);
        if ($packedNotLoaded->isNotEmpty()) {
            throw AppError::rule('INCOMPLETE_LOADING', 'أكمل تحميل كل الطلبات الجاهزة قبل الإرسال ('.$packedNotLoaded->map(fn ($o) => $o->fo->number)->implode('، ').')', 'Load all packed orders first');
        }
        $blocking = OpsException::where('entity_type', 'Trip')->where('entity_id', $trip->id)->where('status', '!=', 'resolved')->where('severity', 'c')->whereIn('kind', ['wrongveh', 'capacity', 'temp'])->count();
        if ($blocking) {
            throw AppError::rule('BLOCKING_EXCEPTIONS', "يوجد {$blocking} استثناء حرج مفتوح على الرحلة — عالجه قبل الإرسال", 'Open critical exceptions block dispatch');
        }
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $trip, $vehicle, $driver, $loaded, $txId) {
            // a double click must not dispatch twice
            if (in_array(Trip::whereKey($trip->id)->lockForUpdate()->value('status'), ['dispatched', 'onroute'], true)) {
                throw AppError::conflict('DISPATCH_DUPLICATE', 'الرحلة منطلقة مسبقًا — لا تكرار', 'Trip already dispatched');
            }
            $now = now();
            // orders that were not loaded (still alloc/picking/picked) leave the trip: their stops are skipped so the driver only sees what is on the truck
            foreach ($trip->orders->filter(fn ($o) => ! $o->loaded) as $o) {
                $o->delete();
                FulfillmentOrder::whereKey($o->fo_id)->update(['trip_id' => null]);
                TripStop::where('trip_id', $trip->id)->where('fo_id', $o->fo_id)->update(['status' => 'skipped', 'note' => 'لم يُحمّل قبل الإرسال — أُزيل من الرحلة']);
                $this->audit->log($user, ['action' => 'TRIP.REMOVE_ORDER', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number, 'field' => 'order', 'oldValue' => $o->fo->number, 'newValue' => 'skipped (not loaded)', 'transactionId' => $txId]);
            }
            foreach ($loaded as $o) {
                FulfillmentOrder::whereKey($o->fo_id)->update(['status' => 'onroute', 'dispatched_at' => $now]);
                $this->audit->status($user, 'FulfillmentOrder', $o->fo_id, $o->fo->number, 'loaded', 'onroute', $trip->number, $txId);
                if ($o->fo->so_id) {
                    SalesOrder::whereKey($o->fo->so_id)->update(['status' => 'outfordel', 'trip_id' => $trip->id]);
                }
            }
            $from = $trip->status;
            $trip->update(['status' => 'onroute', 'actual_start' => $now, 'dispatched_at' => $now]);
            $this->audit->status($user, 'Trip', $trip->id, $trip->number, $from, 'onroute', 'Dispatch', $txId);
            $vehicleFrom = $vehicle->state;
            Vehicle::whereKey($vehicle->id)->update(['state' => 'onroute']);
            $this->audit->status($user, 'Vehicle', $vehicle->id, $vehicle->code, $vehicleFrom, 'onroute', $trip->number, $txId);
            Driver::whereKey($driver->id)->update(['state' => 'onroute']);
            LoadingPlan::where('trip_id', $trip->id)->where('status', 'open')->update(['status' => 'completed', 'completed_at' => $now]);
            DispatchRecord::create([
                'trip_id' => $trip->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'orders_count' => $loaded->count(),
                'total_kg' => $loaded->sum(fn ($o) => $o->fo->weight_kg), 'total_cbm' => $loaded->sum(fn ($o) => $o->fo->cbm),
                'dispatched_by_id' => $user->id, 'dispatched_by' => $user->username, 'dispatched_at' => $now,
            ]);
            TripEvent::create(['trip_id' => $trip->id, 'label' => $now->format('H:i'), 'text_ar' => 'خروج البوابة — Dispatch · ShipmentDispatched → B2B', 'text_en' => 'Dispatched']);
            $this->audit->log($user, ['action' => 'TRIP.DISPATCH', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number,
                'newValue' => ['orders' => $loaded->map(fn ($o) => $o->fo->number)->all(), 'vehicle' => $vehicle->code, 'driver' => $driver->code], 'transactionId' => $txId]);
            $this->notify->activity($user, 'Trip', $trip->id, $trip->number, "Dispatch مؤكد — {$loaded->count()} طلبات · {$vehicle->code} · {$driver->name_ar}", "Dispatched {$trip->number}", ['disp', 'sales']);
            foreach ($loaded as $o) {
                $this->notify->event('ShipmentDispatched', ['trip' => $trip->number, 'fo' => $o->fo->number, 'vehicle' => $vehicle->code, 'driver' => $driver->code, 'at' => $now->toJSON()]);
            }

            return ['trip' => $trip->number, 'status' => 'onroute', 'orders' => $loaded->count(), 'message' => "انطلقت {$trip->number} — أُشعرت منصة B2B: ShipmentDispatched لكل طلب"];
        });
    }

    // ───────────── internals ─────────────

    private function findTrip(string $idOrNumber, array $with): Trip
    {
        return Trip::with($with)->where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', 'الرحلة غير موجودة');
    }

    /** The active allocation a pick task was generated from (same order line, bin and batch). */
    private function activeAllocation(PickTask $t): ?InventoryAllocation
    {
        return InventoryAllocation::where('bin_id', $t->bin_id)->where('status', 'active')
            ->when($t->foLine->so_line_id, fn ($q, $soLineId) => $q->where('so_line_id', $soLineId))
            ->when($t->batch_id, fn ($q, $batchId) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();
    }

    /**
     * Gives the reservation of a short-picked task back to the balance row (reserved / allocated only — on-hand and the
     * ledger are untouched, so ledger = balance still holds and reserved = Σ open allocations again).
     *
     * Done by the inventory engine: balances are never written from here.
     */
    private function unreserve(PickTask $t, int $qty): void
    {
        $this->inventory->unreserve($t->product_id, $t->bin_id, $t->batch_id, $qty);
    }

    private static function cbmOf(FulfillmentOrder $fo, float $cbmPerCarton): float
    {
        return $fo->cbm ?: round(($fo->cartons ?: 0) * $cbmPerCarton * 10) / 10;
    }

    /** Eager loads of the fulfillment document (lines by line number, tasks by pick sequence, primary barcodes only). */
    private static function foWith(): array
    {
        return [
            'customer', 'warehouse', 'so', 'lines' => fn ($q) => $q->orderBy('line_no'), 'lines.product', 'lines.product.barcodes' => fn ($q) => $q->where('is_primary', true),
            'pickLists' => fn ($q) => $q->orderBy('created_at')->orderBy('id'), 'pickLists.tasks' => fn ($q) => $q->orderBy('seq'), 'pickLists.tasks.bin.zone', 'pickLists.tasks.product',
            'packages', 'trip.vehicle', 'trip.driver', 'pods', 'returns',
        ];
    }

    /** 3000.5 → "3,000.5" (JS toLocaleString('en-US')). */
    private static function fmt(float $n): string
    {
        $s = number_format($n, 3, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
