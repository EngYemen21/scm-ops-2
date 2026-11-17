<?php

namespace App\Services\Fulfillment;

use App\Models\Carton;
use App\Models\DispatchRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\InventoryMovement;
use App\Models\LoadingLine;
use App\Models\LoadingPlan;
use App\Models\OpsException;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\StagingEntry;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Exceptions\ExceptionsService;
use App\Services\Inventory\InventoryService;
use App\Services\Transport\TripsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/**
 * Loading and dispatch. The loading plan lists a trip's orders in REVERSE delivery order (the last stop goes in
 * first) with the door they load at; `load` moves an order STG-OUT → vehicle after checking the vehicle, the order
 * scan (FO, sales order, package, label or any carton), the sequence, the capacity and the temperature class;
 * `unload` is its exact reverse while the truck is still at the door; `dispatch` sends the trip — by ONE rule
 * (`dispatchState`), which the loading plan also publishes so that no screen promises what the endpoint refuses.
 */
class LoadingService
{
    /** Orders still being prepared: a later stop in one of these states is what an earlier stop waits for. */
    private const PREPARING = ['alloc', 'picking', 'picked'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly ExceptionsService $exceptions,
        private readonly InventoryService $inventory,
        private readonly FulfillmentSupport $support,
    ) {}

    // ───────────── loading plan ─────────────

    /** Loading plan for a trip: orders in REVERSE delivery order (last stop loads first) with cumulative capacity. */
    public function loadingPlan(string $tripIdOrNumber): array
    {
        $trip = $this->support->findTrip($tripIdOrNumber, ['vehicle', 'driver', 'warehouse', 'stops' => fn ($q) => $q->orderBy('seq'), 'stops.fo.packages', 'stops.fo.so', 'orders.fo']);
        $orders = $trip->orders->keyBy('fo_id');
        $enforce = (bool) $this->settings->get('loading.enforceSequence');
        // a stop whose order left the trip stays on it as "skipped" and is not part of the load
        $rows = $trip->stops->filter(fn ($s) => $s->fo && $s->status !== 'skipped' && $s->fo->status !== 'cancelled')->sortByDesc('seq')->values()->map(fn ($s) => [
            'stopSeq' => $s->seq, 'customer' => $s->customer_ar, 'fo' => $s->fo->number, 'status' => $s->fo->status, 'loaded' => $s->fo->loaded,
            'loadedAt' => $s->fo->loaded_at, 'cartons' => $s->fo->cartons, 'kg' => $s->fo->weight_kg, 'cbm' => $this->support->cbmOf($s->fo),
            'packages' => $s->fo->packages->where('status', '!=', 'void')->count(), 'ready' => $s->fo->status === 'packed',
            // the real position on the truck (trip_orders.load_seq), null until the order is loaded
            'loadSeq' => $orders->get($s->fo_id)?->load_seq, 'so' => $s->fo->so?->number,
        ])->all();
        // what each order still waits for: later stops that are not packed yet (policy) and later stops to load first
        $preparing = [];
        $packed = [];
        foreach ($rows as $i => $r) {
            $rows[$i]['waitFor'] = ! $r['loaded'] && $enforce ? $preparing : [];
            $rows[$i]['loadFirst'] = ! $r['loaded'] ? $packed : [];
            if (! $r['loaded'] && $r['ready']) {
                $packed[] = $r['fo'];
            } elseif (! $r['loaded'] && in_array($r['status'], self::PREPARING, true)) {
                $preparing[] = $r['fo'];
            }
        }
        $rows = collect($rows);
        $loaded = $rows->where('loaded', true);
        $loadedKg = $loaded->sum('kg');
        $loadedCbm = $loaded->sum('cbm');
        $v = $trip->vehicle;

        return [
            'trip' => [
                'number' => $trip->number, 'status' => $trip->status,
                'vehicle' => $v ? ['code' => $v->code, 'plateAr' => $v->plate_ar, 'plateEn' => $v->plate_en, 'state' => $v->state, 'maxKg' => $v->max_kg, 'maxCbm' => $v->max_cbm, 'pallets' => $v->pallets, 'kind' => $v->kind] : null,
                'driver' => $trip->driver ? ['code' => $trip->driver->code, 'nameAr' => $trip->driver->name_ar] : null, 'tempNeed' => $trip->temp_need,
                'warehouse' => $trip->warehouse?->code, 'dockCode' => $trip->dock_code,
            ],
            'rows' => $rows->all(),
            'totals' => [
                'kg' => $rows->sum('kg'), 'cbm' => round($rows->sum('cbm') * 10) / 10, 'loadedKg' => $loadedKg, 'loadedCbm' => round($loadedCbm * 10) / 10,
                'utilKg' => $v && $v->max_kg > 0 ? (int) round($loadedKg / $v->max_kg * 100) : null, 'utilCbm' => $v && $v->max_cbm > 0 ? (int) round($loadedCbm / $v->max_cbm * 100) : null,
            ],
            'nextToLoad' => $rows->first(fn ($r) => ! $r['loaded'] && $r['ready'] && ! $r['waitFor'] && ! $r['loadFirst'])['fo'] ?? null,
            'dock' => ['code' => $trip->dock_code, 'options' => $this->dockOptions($trip)],
            'loadGate' => $this->loadGate($trip),
            'dispatch' => array_diff_key($this->dispatchState($trip), ['kind' => 1]),
            'policy' => ['enforceSequence' => $enforce, 'requireOrderScan' => (bool) $this->settings->get('loading.requireOrderScan')],
        ];
    }

    /** Why nothing can be loaded on this trip right now (null = loading is possible). */
    private function loadGate(Trip $trip): ?array
    {
        $gate = fn (string $code, string $ar, string $en) => ['code' => $code, 'reasonAr' => $ar, 'reasonEn' => $en];
        if (! in_array($trip->status, FulfillmentSupport::LOADABLE, true)) {
            return $gate('TRIP_STATE', "لا يمكن التحميل — حالة الرحلة {$trip->status}", "Trip is {$trip->status} — not loadable");
        }
        if (! $trip->vehicle) {
            return $gate('NO_VEHICLE', 'لا مركبة مسندة للرحلة — أسند المركبة أولًا من صفحة الرحلة', 'No vehicle assigned — assign one from the trip page first');
        }
        if (in_array($trip->vehicle->state, ['maintenance', 'breakdown', 'oos', 'inactive', 'onroute', 'returning'], true)) {
            return $gate('VEHICLE_STATE', "المركبة {$trip->vehicle->code} حالتها {$trip->vehicle->state} — لا تُحمَّل", "Vehicle {$trip->vehicle->code} is {$trip->vehicle->state}");
        }

        return null;
    }

    // ───────────── door / lane ─────────────

    /** The doors of the trip's warehouse (D1 … Dn, as the warehouse master lists them) and who is loading at each. */
    private function dockOptions(Trip $trip): array
    {
        $count = (int) (($trip->relationLoaded('warehouse') ? $trip->warehouse : Warehouse::find($trip->warehouse_id))?->docks ?? 0);
        if ($count <= 0) {
            return [];
        }
        $busy = Trip::where('warehouse_id', $trip->warehouse_id)->whereIn('status', ['loading', 'ready'])->whereNotNull('dock_code')->where('id', '!=', $trip->id)->pluck('number', 'dock_code');

        return array_map(fn (int $i) => ['code' => "D{$i}", 'busyBy' => $busy["D{$i}"] ?? null], range(1, $count));
    }

    /** Sets (or clears) the loading door of a trip that has not left. @param  ?string  $dockCode  null / '' clears */
    public function setDock(AuthUser $user, string $tripIdOrNumber, ?string $dockCode): array
    {
        $code = mb_strtoupper(trim((string) $dockCode));

        return DB::transaction(function () use ($user, $tripIdOrNumber, $code) {
            $trip = Trip::where(fn ($w) => $w->where('id', $tripIdOrNumber)->orWhere('number', $tripIdOrNumber))->lockForUpdate()->first()
                ?? throw AppError::notFound('TRIP_NOT_FOUND', 'الرحلة غير موجودة', 'Trip not found');
            if (! in_array($trip->status, FulfillmentSupport::LOADABLE, true)) {
                throw AppError::rule('TRIP_STATE', "لا يُغيَّر باب التحميل — حالة الرحلة {$trip->status}", "Trip is {$trip->status} — the door cannot change");
            }
            if ($code !== '') {
                $options = $this->dockOptions($trip);
                $dock = collect($options)->firstWhere('code', $code);
                if (! $dock) {
                    $known = $options ? implode('، ', array_column($options, 'code')) : 'لا أبواب معرّفة';
                    throw AppError::rule('DOCK_NOT_FOUND', "الباب {$code} ليس من أبواب المستودع ({$known})", "Dock {$code} is not a door of this warehouse");
                }
                if ($dock['busyBy']) {
                    throw AppError::rule('DOCK_BUSY', "الباب {$code} مشغول بتحميل الرحلة {$dock['busyBy']} — اختر بابًا آخر أو انتظر انطلاقها", "Dock {$code} is in use by trip {$dock['busyBy']}");
                }
            }
            $old = $trip->dock_code;
            $new = $code === '' ? null : $code;
            if ($old !== $new) {
                $trip->update(['dock_code' => $new]);
                TripEvent::create(['trip_id' => $trip->id, 'label' => now()->format('H:i'), 'text_ar' => $new ? "باب التحميل: {$new}" : 'أُلغي تحديد باب التحميل', 'text_en' => $new ? "Loading door: {$new}" : 'Loading door cleared']);
                $this->audit->log($user, ['action' => 'TRIP.DOCK', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number, 'field' => 'dockCode', 'oldValue' => $old, 'newValue' => $new]);
            }

            return ['trip' => $trip->number, 'dockCode' => $new, 'message' => $new ? "باب تحميل {$trip->number}: {$new}" : "أُلغي باب تحميل {$trip->number}"];
        });
    }

    // ───────────── load ─────────────

    /**
     * Load one order onto the assigned vehicle: vehicle scan, order scan, vehicle state, loading sequence, cumulative
     * weight and volume, temperature match. STG-OUT → out of warehouse stock.
     *
     * `foNumber` and `scannedOrder` are anything a scanner reads off the order: its number, its sales order, its
     * package, its label or one of its cartons. With `loading.requireOrderScan` a scan of this order is required —
     * or a reason from a holder of `load.override`. `overrideSequence` (+ reason, `load.override`) loads out of turn.
     *
     * @param  array{foNumber:string, scannedVehicle?:?string, scannedOrder?:?string, overrideSequence?:mixed, reason?:?string}  $dto
     */
    public function load(AuthUser $user, string $tripIdOrNumber, array $dto): array
    {
        $trip = $this->support->findTrip($tripIdOrNumber, ['vehicle', 'stops.fo', 'orders.fo']);
        if (! in_array($trip->status, FulfillmentSupport::LOADABLE, true)) {
            throw AppError::rule('TRIP_STATE', "لا يمكن التحميل — حالة الرحلة {$trip->status}", 'Trip not loadable');
        }
        $veh = $trip->vehicle ?? throw AppError::rule('NO_VEHICLE', 'لا مركبة مسندة للرحلة — أسند المركبة أولًا', 'No vehicle assigned');
        $to = $this->tripOrder($trip, (string) $dto['foNumber']);
        $fo = $to->fo;
        if ($to->loaded || $fo->loaded) {
            throw AppError::conflict('ALREADY_LOADED', "{$fo->number} محمَّل مسبقًا — لا تكرار", 'Order already loaded');
        }
        if ($fo->status !== 'packed') {
            throw AppError::rule('NOT_PACKED', 'لا يمكن التحميل قبل اكتمال التعبئة', 'Order is not packed');
        }
        $norm = fn (?string $s) => preg_replace('/\s+/u', '', mb_strtoupper((string) $s));
        $scannedVehicle = $dto['scannedVehicle'] ?? null;
        $scannedOrder = trim((string) ($dto['scannedOrder'] ?? ''));
        $reason = trim((string) ($dto['reason'] ?? ''));
        $canOverride = $user->can('load.override');
        $overrides = [];
        $tripRef = ['kind' => 'wrongveh', 'severity' => 'c', 'ownerRole' => 'disp', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number];
        // Rejections below raise their exception first: there is no open transaction here, so the exception row survives the rejection.
        if ($scannedVehicle !== null && trim($scannedVehicle) !== '' && ! in_array($norm($scannedVehicle), array_map($norm, [$veh->code, $veh->plate_ar, $veh->plate_en]), true)) {
            $this->exceptions->raise($user, $tripRef + ['documentType' => 'FulfillmentOrder', 'documentId' => $fo->id, 'documentNumber' => $fo->number,
                'textAr' => "مسح شاحنة {$scannedVehicle} بدل {$veh->code} عند تحميل {$fo->number}", 'textEn' => "Wrong truck scan {$scannedVehicle} instead of {$veh->code}"]);
            $plate = $veh->plate_en ?: $veh->plate_ar;
            throw AppError::rule('WRONG_VEHICLE', "مركبة خاطئة — المسح «{$scannedVehicle}» لا يطابق المركبة المسندة {$veh->code} ({$plate}). التحميل مرفوض", "Wrong vehicle: {$scannedVehicle} ≠ {$veh->code}");
        }
        if ($scannedOrder !== '') {
            if (! ScanCodes::matches($scannedOrder, $fo)) {
                throw AppError::rule('WRONG_ORDER', "Scan طلب خاطئ: {$scannedOrder} لا يخص {$fo->number}", "Wrong order scan: {$scannedOrder} does not belong to {$fo->number}");
            }
        } elseif ((bool) $this->settings->get('loading.requireOrderScan')) {
            if (! $canOverride || $reason === '') {
                throw AppError::rule('SCAN_REQUIRED', "امسح ملصق {$fo->number} أو أحد كراتينه قبل التحميل".($canOverride ? ' — أو اكتب سبب التحميل بلا مسح' : ' — التحميل بلا مسح يحتاج مشرفًا (load.override) وسببًا'),
                    "Scan the label of {$fo->number} or one of its cartons before loading".($canOverride ? ' — or give a reason for loading without a scan' : ' — loading without a scan needs a supervisor and a reason'));
            }
            $overrides[] = 'scan';
        }
        if (in_array($veh->state, ['maintenance', 'breakdown', 'oos', 'inactive', 'onroute', 'returning'], true)) {
            $this->exceptions->raise($user, $tripRef + ['textAr' => "محاولة تحميل {$fo->number} على {$veh->code} وهي بحالة {$veh->state}", 'textEn' => "Load attempt on {$veh->code} in state {$veh->state}"]);
            throw AppError::rule('VEHICLE_STATE', "مركبة خاطئة — {$veh->code} حالتها {$veh->state}؛ التحميل مرفوض", "Vehicle {$veh->code} is {$veh->state}");
        }
        // sequence: the last stop of the route goes in first, so nothing of an earlier stop is loaded while a later one is still to come
        $stops = $trip->stops->filter(fn ($s) => $s->fo_id && $s->status !== 'skipped')->keyBy('fo_id');
        $seqOf = fn (string $foId) => $stops->get($foId)?->seq ?? PHP_INT_MIN;
        $later = $trip->orders->filter(fn ($o) => $o->fo && $o->fo_id !== $fo->id && ! $o->loaded && ! $o->fo->loaded && $seqOf($o->fo_id) > $seqOf($fo->id))
            ->sortByDesc(fn ($o) => $seqOf($o->fo_id))->values();
        $preparing = $later->filter(fn ($o) => in_array($o->fo->status, self::PREPARING, true))->map(fn ($o) => $o->fo->number)->values()->all();
        $packedLater = $later->filter(fn ($o) => $o->fo->status === 'packed')->values();
        if (! empty($dto['overrideSequence']) && filter_var($dto['overrideSequence'], FILTER_VALIDATE_BOOLEAN)) {
            if (! $canOverride) {
                throw AppError::forbidden('OVERRIDE_FORBIDDEN', 'تجاوز ترتيب التحميل يحتاج مشرفًا (load.override)', 'Overriding the loading sequence needs load.override');
            }
            if ($reason === '') {
                throw AppError::validation('OVERRIDE_REASON', 'اكتب سبب تجاوز ترتيب التحميل', 'A reason is required to override the loading sequence');
            }
            if ($preparing || $packedLater->isNotEmpty()) {
                $overrides[] = 'sequence';
            }
        } else {
            if ($preparing && (bool) $this->settings->get('loading.enforceSequence')) {
                $names = implode('، ', $preparing);
                $first = $packedLater->isNotEmpty() ? ' · وحمّل قبله: '.$packedLater->map(fn ($o) => $o->fo->number)->implode('، ') : '';
                throw AppError::rule('LOAD_WAIT', "لا يُحمَّل {$fo->number} الآن — طلبات محطات لاحقة لم تُعبَّأ بعد ({$names}) وهي تُحمَّل قبله. انتظر تعبئتها، أو أزِلها من الرحلة من صفحة الرحلة{$first}",
                    "{$fo->number} cannot be loaded yet — later stops are still being prepared ({$names}) and load before it. Wait for them, or remove them from the trip", ['waitFor' => $preparing, 'loadFirst' => $packedLater->map(fn ($o) => $o->fo->number)->all()]);
            }
            if ($first = $packedLater->first()) {
                $customer = $stops->get($first->fo_id)?->customer_ar;
                throw AppError::rule('LOAD_ORDER', "ترتيب التحميل عكسي — حمّل {$first->fo->number} ({$customer}) أولًا: آخر عميل في المسار يُحمّل أولًا", "Reverse loading order — load {$first->fo->number} first");
            }
        }
        $loadedFos = $trip->orders->filter(fn ($o) => $o->loaded && $o->fo)->map(fn ($o) => $o->fo)->values();
        $kg = $loadedFos->sum('weight_kg') + $fo->weight_kg;
        $cbm = $loadedFos->sum(fn ($f) => $this->support->cbmOf($f)) + $this->support->cbmOf($fo);
        $capacity = ['kind' => 'capacity'] + $tripRef;
        if ($kg > $veh->max_kg) {
            $this->exceptions->raise($user, $capacity + ['textAr' => "حمولة {$veh->code} ".number_format(round($kg)).' كجم تتجاوز '.FulfillmentSupport::fmt($veh->max_kg), 'textEn' => "Over weight on {$veh->code}"]);
            throw AppError::rule('OVER_WEIGHT', 'تجاوز السعة — '.number_format(round($kg)).' كجم > '.FulfillmentSupport::fmt($veh->max_kg).' كجم. التحميل مرفوض', 'Over weight');
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
        // the door: two trucks do not load at one door
        if ($trip->dock_code && ! in_array($trip->status, ['loading', 'ready'], true)) {
            $other = Trip::where('warehouse_id', $trip->warehouse_id)->whereIn('status', ['loading', 'ready'])->where('dock_code', $trip->dock_code)->where('id', '!=', $trip->id)->value('number');
            if ($other) {
                throw AppError::rule('DOCK_BUSY', "الباب {$trip->dock_code} مشغول بتحميل الرحلة {$other} — اختر بابًا آخر لهذه الرحلة أو انتظر انطلاقها", "Dock {$trip->dock_code} is in use by trip {$other}");
            }
        }
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $trip, $veh, $to, $fo, $lines, $scannedVehicle, $scannedOrder, $overrides, $reason, $txId) {
            [$so, $fo] = $this->support->lockOrder($fo->id);
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if (! in_array($trip->status, FulfillmentSupport::LOADABLE, true)) {
                throw AppError::rule('TRIP_STATE', "لا يمكن التحميل — حالة الرحلة {$trip->status}", 'Trip not loadable');
            }
            // a double scan must not take the goods out of STG-OUT twice
            $to = TripOrder::whereKey($to->id)->lockForUpdate()->first() ?? throw AppError::rule('FO_NOT_ON_TRIP', "الطلب {$fo->number} غير تابع لهذه الرحلة", 'Order is not assigned to this trip');
            if ($to->loaded) {
                throw AppError::conflict('ALREADY_LOADED', "{$fo->number} محمَّل مسبقًا — لا تكرار", 'Order already loaded');
            }
            if ($fo->status !== 'packed') {
                throw AppError::rule('NOT_PACKED', 'لا يمكن التحميل قبل اكتمال التعبئة', 'Order is not packed');
            }
            // what is on the truck, read under the trip lock (two loaders scanning at once)
            $loaded = TripOrder::with('fo')->where('trip_id', $trip->id)->where('loaded', true)->get();
            $kg = $loaded->sum(fn ($o) => (float) $o->fo?->weight_kg) + $fo->weight_kg;
            $cbm = $loaded->sum(fn ($o) => $o->fo ? $this->support->cbmOf($o->fo) : 0) + $this->support->cbmOf($fo);
            if ($kg > $veh->max_kg || ($veh->max_cbm && $cbm > $veh->max_cbm)) {
                throw AppError::rule('OVER_WEIGHT', 'تجاوز السعة — حُمّل طلب آخر للتو. التحميل مرفوض', 'Over capacity');
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
            $seq = (int) $loaded->max('load_seq') + 1;
            $totalCbm = round($cbm * 10) / 10;
            // one line per order and plan: an order that was unloaded and comes back re-uses its line
            LoadingLine::updateOrCreate(['loading_plan_id' => $plan->id, 'fo_id' => $fo->id], [
                'seq' => $seq, 'weight_kg' => $fo->weight_kg, 'cbm' => $this->support->cbmOf($fo), 'scanned_vehicle' => $scannedVehicle, 'scanned_order' => $scannedOrder !== '' ? $scannedOrder : null,
                'status' => 'loaded', 'loaded_by_id' => $user->id, 'loaded_by' => $user->username, 'loaded_at' => now(),
            ]);
            $plan->update(['total_kg' => $kg, 'total_cbm' => $totalCbm, 'vehicle_id' => $veh->id]);
            $to->update(['loaded' => true, 'loaded_at' => now(), 'load_seq' => $seq]);
            $fo->update(['status' => 'loaded', 'loaded' => true, 'loaded_at' => now()]);
            Carton::where('fo_id', $fo->id)->where('status', 'packed')->update(['status' => 'loaded', 'loaded_at' => now()]);
            StagingEntry::where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('status', 'waiting')->update(['status' => 'cleared', 'cleared_at' => now()]);
            if ($so) {
                $so->update(['status' => 'loaded']);
                $this->support->emit($so->id, 'order.loaded', ['fulfilmentOrder' => $fo->number, 'trip' => $trip->number]);
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
            TripEvent::create(['trip_id' => $trip->id, 'label' => now()->format('H:i'), 'text_ar' => "تحميل {$fo->number} — ترتيب عكسي ({$seq})".($overrides ? ' · بتجاوز المشرف' : ''), 'text_en' => "Loaded {$fo->number}"]);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'packed', 'loaded', "{$veh->code} · {$trip->number}", $txId);
            if ($overrides) {
                $this->audit->log($user, ['action' => 'LOAD.OVERRIDE', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number, 'field' => implode('+', $overrides),
                    'newValue' => ['trip' => $trip->number, 'overrides' => $overrides, 'reason' => $reason], 'transactionId' => $txId]);
                $what = implode(' و', array_map(fn ($o) => $o === 'scan' ? 'بلا مسح الطلب' : 'خارج ترتيب التحميل', $overrides));
                $this->notify->activity($user, 'Trip', $trip->id, $trip->number, "حُمّل {$fo->number} {$what} بقرار المشرف — {$reason}", "{$fo->number} loaded with a supervisor override — {$reason}", ['disp', 'wm']);
            }

            return ['fo' => $fo->number, 'vehicle' => $veh->code, 'seq' => $seq, 'loadedKg' => $kg, 'loadedCbm' => $totalCbm, 'overrides' => $overrides,
                'message' => "حُمّل {$fo->number} في {$veh->code} — الموقع في الشاحنة حسب تسلسل التفريغ"];
        });
    }

    // ───────────── unload ─────────────

    /**
     * Takes a loaded order off the truck while the trip is still at the door: the goods come back into STG-OUT at the
     * cost they left at (movement `unload`), the order is `packed` again and waits in staging. When nothing is left on
     * the truck the trip returns to its planning state and the vehicle to `assigned`.
     *
     * @param  array{foNumber:string, reason:string}  $dto
     */
    public function unload(AuthUser $user, string $tripIdOrNumber, array $dto): array
    {
        $trip = $this->support->findTrip($tripIdOrNumber, ['vehicle', 'orders.fo']);
        if (! in_array($trip->status, ['loading', 'ready'], true)) {
            throw AppError::rule('TRIP_STATE', "الإنزال متاح والرحلة تحت التحميل فقط — حالتها {$trip->status}", "Unloading needs a trip that is loading ({$trip->status})");
        }
        $reason = trim((string) ($dto['reason'] ?? ''));
        if ($reason === '') {
            throw AppError::validation('REASON_REQUIRED', 'اكتب سبب الإنزال', 'A reason is required');
        }
        $to = $this->tripOrder($trip, (string) $dto['foNumber']);
        $foId = $to->fo_id;
        $txId = $this->inventory->newTxId();

        return DB::transaction(function () use ($user, $trip, $to, $foId, $reason, $txId) {
            [$so, $fo] = $this->support->lockOrder($foId);
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if (! in_array($trip->status, ['loading', 'ready'], true)) {
                throw AppError::rule('TRIP_STATE', "الإنزال متاح والرحلة تحت التحميل فقط — حالتها {$trip->status}", "Unloading needs a trip that is loading ({$trip->status})");
            }
            $to = TripOrder::whereKey($to->id)->lockForUpdate()->first();
            if (! $to || ! $to->loaded || $fo->status !== 'loaded') {
                throw AppError::rule('NOT_LOADED', "{$fo->number} ليس محمَّلًا على هذه الرحلة", "{$fo->number} is not loaded on this trip");
            }
            Sm::assert('FO_TRANSITIONS', 'loaded', 'packed', 'FO_TRANSITION');
            $veh = $trip->vehicle_id ? Vehicle::whereKey($trip->vehicle_id)->lockForUpdate()->first() : null;
            $out = $this->inventory->specialBin($fo->warehouse_id, 'STG-OUT');
            $qty = 0;
            foreach (FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get() as $l) {
                foreach (PickTask::where('fo_line_id', $l->id)->where('picked_qty', '>', 0)->orderBy('seq')->get() as $t) {
                    // back at the cost of the load movement of this order / product / batch, so goods in transit closes to zero
                    $cost = InventoryMovement::where('type', 'load')->where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('product_id', $l->product_id)
                        ->when($t->batch_id, fn ($q, $batchId) => $q->where('batch_id', $batchId), fn ($q) => $q->whereNull('batch_id'))
                        ->orderByDesc('created_at')->orderByDesc('number')->value('unit_cost');
                    $this->inventory->post($user, $txId, [
                        'type' => 'unload', 'productId' => $l->product_id, 'batchId' => $t->batch_id, 'qty' => $t->picked_qty, 'to' => ['warehouseId' => $fo->warehouse_id, 'binId' => $out->id],
                        'unitCost' => $cost !== null ? (float) $cost : null,
                        'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number], 'note' => ($veh ? "{$veh->code} → " : '')."STG-OUT · {$trip->number} · إنزال — {$reason}",
                    ]);
                    $qty += $t->picked_qty;
                }
            }
            $to->update(['loaded' => false, 'loaded_at' => null, 'load_seq' => null]);
            $plan = LoadingPlan::where('trip_id', $trip->id)->where('status', 'open')->first();
            if ($plan) {
                LoadingLine::where('loading_plan_id', $plan->id)->where('fo_id', $fo->id)->update(['status' => 'unloaded']);
                $still = LoadingLine::where('loading_plan_id', $plan->id)->where('status', 'loaded')->get();
                $plan->update(['total_kg' => $still->sum('weight_kg'), 'total_cbm' => round($still->sum('cbm') * 10) / 10]);
            }
            $fo->update(['status' => 'packed', 'loaded' => false, 'loaded_at' => null]);
            $this->audit->status($user, 'FulfillmentOrder', $fo->id, $fo->number, 'loaded', 'packed', "إنزال من {$trip->number} — {$reason}", $txId);
            Carton::where('fo_id', $fo->id)->where('status', 'loaded')->update(['status' => 'packed', 'loaded_at' => null]);
            // the order waits in outbound staging again
            $entry = StagingEntry::where('reference_type', 'FulfillmentOrder')->where('reference_id', $fo->id)->where('direction', 'out')->orderByDesc('created_at')->orderByDesc('id')->first();
            $entry ? $entry->update(['status' => 'waiting', 'cleared_at' => null, 'qty' => $qty])
                : StagingEntry::create(['direction' => 'out', 'bin_id' => $out->id, 'reference_type' => 'FulfillmentOrder', 'reference_id' => $fo->id, 'reference_number' => $fo->number, 'qty' => $qty]);
            if ($so) {
                $so->update(['status' => 'packed']);
                $this->support->emit($so->id, 'order.unloaded', ['fulfilmentOrder' => $fo->number, 'trip' => $trip->number, 'reason' => $reason]);
            }
            $remaining = TripOrder::where('trip_id', $trip->id)->where('loaded', true)->count();
            $tripStatus = $trip->status;
            if ($remaining === 0) {
                // an empty truck: the trip is a plan again (and can be re-assigned or cancelled), the vehicle is no longer loading
                $tripStatus = FulfillmentSupport::tripStatusFor($trip->vehicle_id, $trip->driver_id);
                Sm::assert('TRIP_TRANSITIONS', $trip->status, $tripStatus, 'TRIP_TRANSITION');
                $from = $trip->status;
                $trip->update(['status' => $tripStatus]);
                $this->audit->status($user, 'Trip', $trip->id, $trip->number, $from, $tripStatus, 'أُنزلت كل الطلبات', $txId);
                if ($veh && $veh->state === 'loading' && Sm::can('VEHICLE_TRANSITIONS', 'loading', 'assigned')) {
                    $veh->update(['state' => 'assigned']);
                    $this->audit->status($user, 'Vehicle', $veh->id, $veh->code, 'loading', 'assigned', "إنزال {$trip->number}", $txId);
                }
            }
            TripEvent::create(['trip_id' => $trip->id, 'label' => now()->format('H:i'), 'text_ar' => "إنزال {$fo->number} — {$reason}", 'text_en' => "Unloaded {$fo->number}"]);
            $this->audit->log($user, ['action' => 'LOAD.UNLOAD', 'entityType' => 'FulfillmentOrder', 'entityId' => $fo->id, 'entityNumber' => $fo->number,
                'newValue' => ['trip' => $trip->number, 'vehicle' => $veh?->code, 'qty' => $qty, 'reason' => $reason, 'remainingLoaded' => $remaining, 'tripStatus' => $tripStatus], 'transactionId' => $txId]);
            $this->notify->activity($user, 'Trip', $trip->id, $trip->number, "أُنزل {$fo->number} من {$trip->number} وعاد إلى Staging الصادر — {$reason}", "{$fo->number} unloaded from {$trip->number} — {$reason}", ['disp', 'wm']);

            return ['fo' => $fo->number, 'status' => 'packed', 'trip' => $trip->number, 'tripStatus' => $tripStatus, 'remainingLoaded' => $remaining,
                'message' => "أُنزل {$fo->number} وعاد إلى Staging الصادر".($remaining === 0 ? ' — المركبة فارغة والرحلة عادت للتخطيط' : '')];
        });
    }

    // ───────────── dispatch ─────────────

    /**
     * THE dispatch rule, in the order the endpoint applies it. `allowed` is what `dispatch` will do; the lists say
     * what happens to each order: loaded orders leave with the truck, packed-but-not-loaded orders block the trip
     * (load them or take them off), orders that are not packed yet leave the trip when it is dispatched.
     *
     * @return array{allowed:bool, kind:?string, code:?string, reasonAr:?string, reasonEn:?string, loaded:string[], packedNotLoaded:string[], notPacked:string[]}
     */
    public function dispatchState(Trip $trip): array
    {
        $trip->loadMissing(['vehicle', 'driver', 'orders.fo']);
        $orders = $trip->orders->filter(fn ($o) => $o->fo);
        $numbers = fn ($set) => $set->map(fn ($o) => $o->fo->number)->values()->all();
        $state = [
            'allowed' => true, 'kind' => null, 'code' => null, 'reasonAr' => null, 'reasonEn' => null,
            'loaded' => $numbers($orders->filter(fn ($o) => $o->loaded)),
            'packedNotLoaded' => $numbers($orders->filter(fn ($o) => ! $o->loaded && $o->fo->status === 'packed')),
            'notPacked' => $numbers($orders->filter(fn ($o) => ! $o->loaded && $o->fo->status !== 'packed')),
        ];
        $block = fn (string $kind, string $code, string $ar, string $en) => ['allowed' => false, 'kind' => $kind, 'code' => $code, 'reasonAr' => $ar, 'reasonEn' => $en] + $state;
        if (in_array($trip->status, ['dispatched', 'onroute'], true)) {
            return $block('conflict', 'DISPATCH_DUPLICATE', 'الرحلة منطلقة مسبقًا — لا تكرار', 'Trip already dispatched');
        }
        if (! in_array($trip->status, ['loading', 'ready'], true)) {
            return in_array($trip->status, FulfillmentSupport::LOADABLE, true)
                ? $block('rule', 'TRIP_STATE', "لا يمكن الإرسال — لم يُحمَّل شيء بعد (حالة الرحلة {$trip->status})", "Nothing is loaded yet (trip is {$trip->status})")
                : $block('rule', 'TRIP_STATE', "لا يمكن الإرسال — حالة الرحلة {$trip->status}", 'Trip not ready for dispatch');
        }
        $vehicle = $trip->vehicle;
        $driver = $trip->driver;
        if (! $vehicle) {
            return $block('rule', 'NO_VEHICLE', 'لا مركبة مسندة', 'No vehicle assigned');
        }
        if (! $driver) {
            return $block('rule', 'NO_DRIVER', 'لا سائق مسند — Dispatch مرفوض', 'No driver assigned');
        }
        if (in_array($vehicle->state, ['maintenance', 'breakdown', 'oos', 'inactive'], true)) {
            return $block('rule', 'VEHICLE_STATE', "المركبة {$vehicle->code} في حالة {$vehicle->state} — Dispatch مرفوض", 'Vehicle unavailable');
        }
        // one rule for the trip room and the dock (blocked, off shift, licence or iqama expired) — and the reason is named
        if ($why = app(TripsService::class)->driverDispatchBlock($driver)) {
            return $block('rule', 'DRIVER_STATE', "السائق غير متاح ({$why[0]}) — Dispatch مرفوض", "Driver unavailable ({$why[1]})");
        }
        if (! $state['loaded']) {
            return $block('rule', 'NOTHING_LOADED', 'لا طلبات محمّلة', 'Nothing loaded');
        }
        if ($state['packedNotLoaded']) {
            return $block('rule', 'INCOMPLETE_LOADING', 'أكمل تحميل كل الطلبات الجاهزة قبل الإرسال ('.implode('، ', $state['packedNotLoaded']).')', 'Load all packed orders first ('.implode(', ', $state['packedNotLoaded']).')');
        }
        $blocking = OpsException::where('entity_type', 'Trip')->where('entity_id', $trip->id)->where('status', '!=', 'resolved')->where('severity', 'c')->whereIn('kind', ['wrongveh', 'capacity', 'temp'])->count();
        if ($blocking) {
            return $block('rule', 'BLOCKING_EXCEPTIONS', "يوجد {$blocking} استثناء حرج مفتوح على الرحلة — عالجه قبل الإرسال", 'Open critical exceptions block dispatch');
        }

        return $state;
    }

    /** Throws the reason a trip cannot be dispatched — the same answer `dispatchState` publishes. */
    public function assertCanDispatch(Trip $trip): array
    {
        $state = $this->dispatchState($trip);
        if (! $state['allowed']) {
            throw $state['kind'] === 'conflict' ? AppError::conflict($state['code'], $state['reasonAr'], $state['reasonEn']) : AppError::rule($state['code'], $state['reasonAr'], $state['reasonEn']);
        }

        return $state;
    }

    /**
     * Dispatch: vehicle + driver fit, everything packed is loaded, no open critical exception on the trip. Orders that
     * were never packed leave the trip and their stops are skipped.
     */
    public function dispatch(AuthUser $user, string $tripIdOrNumber): array
    {
        $tripId = $this->support->findTrip($tripIdOrNumber)->id;
        $this->assertCanDispatch($this->support->findTrip($tripId, ['vehicle', 'driver', 'orders.fo']));
        $txId = $this->inventory->newTxId();

        $out = DB::transaction(function () use ($user, $tripId, $txId) {
            // orders first, then the trip — the lock order of loading and unloading; a double click waits here and is refused below
            foreach (TripOrder::where('trip_id', $tripId)->orderBy('fo_id')->pluck('fo_id') as $foId) {
                $this->support->lockOrder($foId);
            }
            Trip::whereKey($tripId)->lockForUpdate()->firstOrFail();
            $trip = $this->support->findTrip($tripId, ['vehicle', 'driver', 'orders.fo', 'stops']);
            $this->assertCanDispatch($trip);
            $vehicle = $trip->vehicle;
            $driver = $trip->driver;
            $loaded = $trip->orders->filter(fn ($o) => $o->loaded && $o->fo)->values();
            $now = now();
            // orders that were not loaded (still alloc/picking/picked) leave the trip: their stops are skipped so the driver only sees what is on the truck
            foreach ($trip->orders->filter(fn ($o) => ! $o->loaded) as $o) {
                $o->delete();
                FulfillmentOrder::whereKey($o->fo_id)->update(['trip_id' => null]);
                TripStop::where('trip_id', $trip->id)->where('fo_id', $o->fo_id)->update(['status' => 'skipped', 'note' => 'لم يُحمّل قبل الإرسال — أُزيل من الرحلة']);
                $this->audit->log($user, ['action' => 'TRIP.REMOVE_ORDER', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number, 'field' => 'order', 'oldValue' => $o->fo?->number, 'newValue' => 'skipped (not loaded)', 'transactionId' => $txId]);
            }
            foreach ($loaded as $o) {
                FulfillmentOrder::whereKey($o->fo_id)->update(['status' => 'onroute', 'dispatched_at' => $now]);
                $this->audit->status($user, 'FulfillmentOrder', $o->fo_id, $o->fo->number, 'loaded', 'onroute', $trip->number, $txId);
                if ($o->fo->so_id) {
                    SalesOrder::whereKey($o->fo->so_id)->update(['status' => 'outfordel', 'trip_id' => $trip->id]);
                    $this->support->emit($o->fo->so_id, 'shipment.dispatched', [
                        'fulfilmentOrder' => $o->fo->number, 'trip' => $trip->number, 'vehicle' => $vehicle->plate_ar ?: $vehicle->code,
                        'driver' => $driver->name_ar, 'driverPhone' => $driver->mobile, 'plannedStart' => $trip->planned_start ?? null,
                    ]);
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
        // orders left behind became skipped stops: plan the arrivals of the ones that travel (never fails the dispatch)
        try {
            app(TripsService::class)->recomputeEtas($tripId);
        } catch (\Throwable $e) {
            report($e);
        }

        return $out;
    }

    // ───────────── outbound staging board ─────────────

    /**
     * Packed orders waiting in STG-OUT, grouped by the trip they leave on and the door that trip loads at — the one
     * outbound area read as lanes. Orders without a trip come last.
     *
     * @return array{groups: array<int, array{trip:?array, orders:array}>, total:int}
     */
    public function staging(?string $warehouseCode = null): array
    {
        $fos = FulfillmentOrder::with(['customer', 'so', 'trip.vehicle', 'trip.driver', 'lines'])->where('status', 'packed')->where('loaded', false)
            ->when($warehouseCode, fn ($q) => $q->whereHas('warehouse', fn ($w) => $w->where('code', $warehouseCode)))
            ->orderBy('staged_at')->orderBy('id')->get();
        $stops = TripStop::whereIn('fo_id', $fos->pluck('id')->all())->where('status', '!=', 'skipped')->get()->keyBy(fn ($s) => "{$s->trip_id}|{$s->fo_id}");
        $groups = [];
        foreach ($fos as $fo) {
            $trip = $fo->trip && in_array($fo->trip->status, FulfillmentSupport::LOADABLE, true) ? $fo->trip : null;
            $key = $trip?->id ?? '';
            $groups[$key] ??= [
                'trip' => $trip ? [
                    'number' => $trip->number, 'status' => $trip->status, 'date' => $trip->date, 'plannedStart' => $trip->planned_start, 'dockCode' => $trip->dock_code,
                    'vehicle' => $trip->vehicle?->code, 'driver' => $trip->driver?->name_ar,
                ] : null,
                'orders' => [],
            ];
            $groups[$key]['orders'][] = [
                'fo' => $fo->number, 'so' => $fo->so?->number, 'customer' => $fo->customer?->name_ar, 'customerEn' => $fo->customer?->name_en, 'zone' => $fo->customer?->zone,
                'cartons' => $fo->cartons, 'kg' => $fo->weight_kg, 'qty' => (int) $fo->lines->sum('packed_qty'), 'stagedAt' => $fo->staged_at,
                'stopSeq' => $trip ? $stops->get("{$trip->id}|{$fo->id}")?->seq : null,
            ];
        }
        // a lane reads in loading order: the last stop first
        foreach ($groups as $k => $g) {
            usort($groups[$k]['orders'], fn ($a, $b) => ($b['stopSeq'] ?? 0) <=> ($a['stopSeq'] ?? 0));
        }
        // trips with a door first (by door), then trips without one, then orders that have no trip yet
        $rank = fn (array $g) => $g['trip'] === null ? '2' : ($g['trip']['dockCode'] ? '0'.str_pad((string) (int) preg_replace('/\D/', '', $g['trip']['dockCode']), 4, '0', STR_PAD_LEFT) : '1');
        $list = array_values($groups);
        usort($list, fn ($a, $b) => [$rank($a), $a['trip']['number'] ?? ''] <=> [$rank($b), $b['trip']['number'] ?? '']);

        return ['groups' => $list, 'total' => $fos->count()];
    }

    // ───────────── internals ─────────────

    /** The trip order a scanned / typed code identifies (order number, sales order, package, label or carton). */
    private function tripOrder(Trip $trip, string $code): TripOrder
    {
        $target = ScanCodes::fo($code);
        $to = $target ? $trip->orders->first(fn ($o) => $o->fo_id === $target->id) : null;
        if (! $to || ! $to->fo) {
            $shown = trim($code);
            throw AppError::rule('FO_NOT_ON_TRIP', "الطلب {$shown} غير تابع لهذه الرحلة", 'Order is not assigned to this trip');
        }

        return $to;
    }
}
