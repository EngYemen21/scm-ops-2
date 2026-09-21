<?php

namespace App\Services\Transport;

use App\Models\Driver;
use App\Models\FleetAlert;
use App\Models\FulfillmentOrder;
use App\Models\MaintenanceOrder;
use App\Models\OpsRequest;
use App\Models\Route;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\TripEvent;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Services\Transport\TransportUtil as U;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Facades\DB;

/**
 * Trips (TMS) + the reusable fleet rules that loading / dispatch / delivery may call:
 *   vehicleCanAssign(vehicle) · assertCanDispatch(trip) · recommendVehicles(trip) · transitionVehicle(...) · isVehicleBusy / isDriverBusy
 * Rules and Arabic messages mirror the reference system.
 *
 * GPS / ETA / telematics are not connected: nothing here invents positions or arrival times; `eta`, `delayMin`,
 * `gpsOnline` … are returned exactly as stored.
 */
class TripsService
{
    private const BLOCKING_VEHICLE_STATES = ['maintenance', 'breakdown', 'oos', 'inactive'];

    private const COST_FIELDS = ['fuel', 'driver', 'ot', 'maint', 'dep', 'tolls', 'parking', 'third', 'other'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
    ) {}

    // ───────────── lookups ─────────────
    public function vehicleByCode(string $code): Vehicle
    {
        return Vehicle::with(['maintenance' => fn ($q) => $q->where('status', 'open')])
            ->where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
            ?? throw AppError::notFound('VEHICLE_NOT_FOUND', "المركبة {$code} غير موجودة", "Vehicle {$code} not found");
    }

    public function driverByCode(string $code): Driver
    {
        return Driver::where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
            ?? throw AppError::notFound('DRIVER_NOT_FOUND', "السائق {$code} غير موجود", "Driver {$code} not found");
    }

    public function tripByNumber(string $number): Trip
    {
        return Trip::with(['vehicle', 'driver', 'stops' => fn ($q) => $q->orderBy('seq'), 'orders'])
            ->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$number} غير موجودة", "Trip {$number} not found");
    }

    // ───────────── reusable rules ─────────────
    /** Any of registration / insurance / inspection / operating card expired. */
    public function vehicleDocsExpired(Vehicle $v): bool
    {
        foreach (U::docInfos($v, U::VEHICLE_DOCS) as $doc) {
            if ($doc['expired']) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok:bool, why?:string, whyEn?:string} */
    public function vehicleCanAssign(Vehicle $v): array
    {
        $s = $v->state;
        if (in_array($s, self::BLOCKING_VEHICLE_STATES, true) || ! $v->active) {
            return ['ok' => false, 'why' => 'حالة المركبة «'.U::vehicleLabel($s).'» تمنع الإسناد', 'whyEn' => 'Vehicle state "'.U::vehicleLabel($s, 'en').'" blocks assignment'];
        }
        if ($this->vehicleDocsExpired($v)) {
            return ['ok' => false, 'why' => 'وثائق المركبة منتهية', 'whyEn' => 'Vehicle documents expired'];
        }
        $openMaint = $v->relationLoaded('maintenance')
            ? $v->maintenance->where('status', 'open')
            : MaintenanceOrder::where('vehicle_id', $v->id)->where('status', 'open')->get();
        if ($openMaint->contains(fn ($m) => $m->kind === 'emergency')) {
            return ['ok' => false, 'why' => 'تنبيه صيانة حرج مفتوح', 'whyEn' => 'Open emergency maintenance order'];
        }
        if ($s !== 'available') {
            return ['ok' => false, 'why' => 'المركبة مشغولة برحلة', 'whyEn' => 'Vehicle is busy on a trip'];
        }

        return ['ok' => true];
    }

    /** @return array{ok:bool, why?:string, whyEn?:string} */
    public function driverCanAssign(Driver $d): array
    {
        if ($d->blocked) {
            return ['ok' => false, 'why' => 'وثائق السائق تنتهي قريبًا — الإسناد موقوف', 'whyEn' => 'Driver documents expiring — assignment blocked'];
        }
        $lic = U::daysTo($d->license_expiry);
        if ($lic !== null && $lic < 0) {
            return ['ok' => false, 'why' => 'رخصة منتهية', 'whyEn' => 'License expired'];
        }
        if (! $d->active || $d->state === 'inactive') {
            return ['ok' => false, 'why' => 'السائق غير نشط', 'whyEn' => 'Driver inactive'];
        }
        if ($d->state === 'off') {
            return ['ok' => false, 'why' => 'السائق خارج الوردية', 'whyEn' => 'Driver off shift'];
        }

        return ['ok' => true];
    }

    /** Vehicle committed to another trip currently loading / ready / dispatched / on route. */
    public function isVehicleBusy(string $vehicleId, ?string $exceptTripId = null): bool
    {
        return Trip::where('vehicle_id', $vehicleId)->whereIn('status', U::ACTIVE_TRIP_STATES)
            ->when($exceptTripId, fn ($q) => $q->where('id', '!=', $exceptTripId))->exists();
    }

    public function isDriverBusy(string $driverId, ?string $exceptTripId = null): bool
    {
        return Trip::where('driver_id', $driverId)->whereIn('status', U::ACTIVE_TRIP_STATES)
            ->when($exceptTripId, fn ($q) => $q->where('id', '!=', $exceptTripId))->exists();
    }

    /**
     * Capacity + temperature compatibility of a vehicle for a trip's load. Returns the Arabic rejection reason or null.
     *
     * @param  array{kg:float|int, cbm:float|int, pallets:int, tempNeed:string}  $t
     */
    public function vehicleFitsTrip(Vehicle $x, array $t): ?string
    {
        if ($x->max_kg < $t['kg']) {
            return 'تجاوز الوزن الأقصى ('.U::num($t['kg']).' > '.U::num($x->max_kg).' كجم)';
        }
        if ($x->max_cbm && $t['cbm'] > $x->max_cbm) {
            return 'تجاوز الحجم الأقصى ('.U::num($t['cbm']).' > '.U::num($x->max_cbm).' م³)';
        }
        if ($x->pallets && $t['pallets'] > $x->pallets) {
            return "تجاوز عدد المنصات ({$t['pallets']} > {$x->pallets})";
        }
        if (in_array($t['tempNeed'], ['chill', 'reefer'], true) && $x->kind === 'dry') {
            return 'متطلب التبريد/التجميد غير متوافق مع مركبة جافة';
        }
        if ($t['tempNeed'] === 'reefer' && $x->kind !== 'reefer') {
            return 'الرحلة تحتاج مركبة مجمدة −18°';
        }

        return null;
    }

    /** Ranked candidates with score + reasons; `fleet.maintenanceSoonKm` drives the maintenance penalty. */
    public function recommendVehicles(Trip|string $trip): array
    {
        $t = is_string($trip) ? $this->tripByNumber($trip) : $trip;
        $need = $t->temp_need ?: 'dry';
        $soonKm = (float) $this->settings->get('fleet.maintenanceSoonKm');
        $vehicles = Vehicle::with(['maintenance' => fn ($q) => $q->where('status', 'open'), 'warehouse'])->where('active', true)->orderBy('code')->get();
        $cands = [];
        foreach ($vehicles as $x) {
            $base = ['vehicle' => [
                'id' => $x->id, 'code' => $x->code, 'plateAr' => $x->plate_ar, 'kind' => $x->kind, 'typeAr' => $x->type_ar, 'typeEn' => $x->type_en,
                'maxKg' => $x->max_kg, 'maxCbm' => $x->max_cbm, 'pallets' => $x->pallets, 'warehouse' => $x->warehouse?->code, 'state' => $x->state,
                'avgKmL' => $x->avg_km_l, 'odometer' => $x->odometer, 'nextMaintKm' => $x->next_maint_km,
            ]];
            $reject = fn (?string $why) => $base + ['ok' => false, 'why' => $why, 'score' => -1, 'reasons' => []];
            $ca = $this->vehicleCanAssign($x);
            if (! $ca['ok']) {
                $cands[] = $reject($ca['why']);

                continue;
            }
            if ($need === 'reefer' && $x->kind !== 'reefer') {
                $cands[] = $reject('يحتاج مجمد −18°');

                continue;
            }
            if ($need === 'chill' && $x->kind === 'dry') {
                $cands[] = $reject('يحتاج تبريد +4°');

                continue;
            }
            if ($x->max_kg < $t->kg) {
                $cands[] = $reject('الوزن '.U::fmt0($t->kg).' يتجاوز '.U::fmt0($x->max_kg));

                continue;
            }
            if ($x->max_cbm < $t->cbm) {
                $cands[] = $reject('الحجم '.U::num($t->cbm).' يتجاوز '.U::num($x->max_cbm));

                continue;
            }
            if ($x->pallets < $t->pallets) {
                $cands[] = $reject("الطبليات {$t->pallets} تتجاوز {$x->pallets}");

                continue;
            }
            $reasons = [];
            $score = 0;
            $utilKg = $x->max_kg ? $t->kg / $x->max_kg : 0;
            $score += U::round((1 - abs($utilKg - 0.85)) * 40);
            $reasons[] = 'استخدام حمولة '.U::round($utilKg * 100).'%';
            if ($x->warehouse_id === $t->warehouse_id) {
                $score += 25;
                $reasons[] = 'في نفس المستودع';
            } else {
                $reasons[] = 'مستودع آخر ('.($x->warehouse?->code ?: '—').')';
            }
            $score += U::round($x->avg_km_l * 3);
            $reasons[] = U::num($x->avg_km_l).' كم/ل';
            if ($x->kind === $need) {
                $score += 10;
                $reasons[] = 'نوع مطابق تمامًا';
            }
            if ($x->next_maint_km !== null && $x->next_maint_km - $x->odometer < $soonKm) {
                $score -= 15;
                $reasons[] = 'صيانة قريبة';
            }
            $cands[] = $base + ['ok' => true, 'score' => $score, 'reasons' => $reasons];
        }
        usort($cands, fn ($a, $b) => ($b['ok'] ? $b['score'] : -1) <=> ($a['ok'] ? $a['score'] : -1));

        return $cands;
    }

    /**
     * Gate for the dispatch step: trip must be loading/ready with vehicle + driver assigned, every order loaded,
     * vehicle documents valid and driver still eligible. Throws a business-rule error with the reason.
     */
    public function assertCanDispatch(Trip|string $trip): Trip
    {
        $t = $this->tripByNumber(is_string($trip) ? $trip : $trip->id);
        if (! Sm::can('TRIP_TRANSITIONS', $t->status, 'onroute')) {
            throw AppError::rule('TRIP_NOT_READY', "لا يمكن الإرسال — حالة الرحلة «{$t->status}» لا تسمح بالخروج", "Trip status {$t->status} cannot be dispatched");
        }
        if (! $t->vehicle) {
            throw AppError::rule('TRIP_NO_VEHICLE', 'لا يمكن الإرسال — لم تُسند مركبة', 'No vehicle assigned');
        }
        if (! $t->driver) {
            throw AppError::rule('TRIP_NO_DRIVER', 'لا يمكن الإرسال — لم يُسند سائق', 'No driver assigned');
        }
        $unloaded = $t->orders->where('loaded', false)->count();
        if ($unloaded) {
            throw AppError::rule('TRIP_NOT_LOADED', "لا يمكن الإرسال — {$unloaded} طلب غير محمَّل", "{$unloaded} order(s) not loaded");
        }
        if (in_array($t->vehicle->state, self::BLOCKING_VEHICLE_STATES, true)) {
            throw AppError::rule('VEHICLE_STATE', 'حالة المركبة «'.U::vehicleLabel($t->vehicle->state).'» تمنع الخروج', 'Vehicle state blocks dispatch');
        }
        if ($this->vehicleDocsExpired($t->vehicle)) {
            throw AppError::rule('VEHICLE_DOCS', 'وثائق المركبة منتهية', 'Vehicle documents expired');
        }
        $dc = $this->driverCanAssign($t->driver);
        if (! $dc['ok']) {
            throw AppError::rule('DRIVER_BLOCKED', "مرفوض: {$dc['why']}", $dc['whyEn']);
        }
        if ($this->isDriverBusy($t->driver->id, $t->id)) {
            throw AppError::rule('DRIVER_BUSY', 'مرفوض: السائق برحلة متزامنة', 'Driver busy on another trip');
        }

        return $t;
    }

    /**
     * Vehicle state machine step. `force` skips the table for factual reports (breakdown). Writes audit + status history.
     * Must run inside the caller's transaction.
     */
    public function transitionVehicle(?AuthUser $user, Vehicle $vehicle, string $to, ?string $note = null, bool $force = false): Vehicle
    {
        $from = $vehicle->state;
        if ($from === $to) {
            return $vehicle;
        }
        if (! $force && ! Sm::can('VEHICLE_TRANSITIONS', $from, $to)) {
            throw AppError::rule('VEHICLE_TRANSITION', 'انتقال غير مسموح: '.U::vehicleLabel($from).' ← '.U::vehicleLabel($to), "Transition not allowed: {$from} → {$to}");
        }
        $vehicle->update(['state' => $to]);
        $this->audit->status($user, 'Vehicle', $vehicle->id, $vehicle->code, $from, $to, $note);

        return $vehicle;
    }

    /** Best-effort walk along allowed transitions (e.g. onroute → returning → atwh → available). Skips steps that are not allowed. */
    public function walkVehicle(?AuthUser $user, Vehicle $vehicle, array $path, ?string $note = null): Vehicle
    {
        foreach ($path as $step) {
            if ($vehicle->state === $step || ! Sm::can('VEHICLE_TRANSITIONS', $vehicle->state, $step)) {
                continue;
            }
            $this->transitionVehicle($user, $vehicle, $step, $note);
        }

        return $vehicle;
    }

    // ───────────── trips ─────────────
    /** @param  array{status?:?string, warehouse?:?string, date?:?string, vehicle?:?string, driver?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $query = Trip::query()->with(['warehouse', 'vehicle', 'driver'])->withCount(['stops', 'orders', 'pods']);
        if (! empty($f['status'])) {
            $query->whereIn('status', explode(',', $f['status']));
        }
        foreach (['warehouse', 'vehicle', 'driver'] as $rel) {
            if (! empty($f[$rel])) {
                $query->whereHas($rel, fn ($w) => $w->where('code', $f[$rel]));
            }
        }
        if (! empty($f['date']) && ($d = U::parseDate($f['date']))) {
            $query->where('date', '>=', $d)->where('date', '<', $d->copy()->addDay());
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('route_ar', 'like', $like)->orWhere('route_en', 'like', $like)
                ->orWhereHas('vehicle', fn ($v) => $v->where('code', 'like', $like))
                ->orWhereHas('driver', fn ($d) => $d->where('name_ar', 'like', $like)));
        }
        $column = $page->sort === 'date' ? 'date' : ($page->sort === 'status' ? 'status' : 'created_at');
        $query->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($query, function (Trip $t) {
            $row = U::shape($t->toArray(), [
                'warehouse' => ['code', 'nameAr'],
                'vehicle' => ['code', 'plateAr', 'kind', 'typeAr', 'maxKg', 'state'],
                'driver' => ['code', 'nameAr', 'nameEn', 'state'],
            ]);
            unset($row['stopsCount'], $row['ordersCount'], $row['podsCount']);

            return $row + ['_count' => ['stops' => $t->stops_count, 'orders' => $t->orders_count, 'pods' => $t->pods_count]];
        });
    }

    public function get(string $number): array
    {
        $t = Trip::with([
            'warehouse', 'vehicle', 'driver', 'dispatch', 'cost',
            'stops' => fn ($q) => $q->orderBy('seq'), 'stops.fo', 'stops.customer', 'stops.pod',
            'orders.fo.customer',
            'events' => fn ($q) => $q->orderBy('at')->orderBy('id'),
        ])->withCount(['pods', 'deliveries', 'loadingPlans'])
            ->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$number} غير موجودة", "Trip {$number} not found");

        $v = $t->vehicle;
        $pct = fn ($a, $b) => $b ? U::round(($a / $b) * 100) : null;
        $utilisation = $v ? [
            'kg' => $pct($t->kg, $v->max_kg), 'cbm' => $pct($t->cbm, $v->max_cbm), 'pallets' => $pct($t->pallets, $v->pallets),
            'overKg' => $v->max_kg > 0 && $t->kg > $v->max_kg, 'overCbm' => $v->max_cbm > 0 && $t->cbm > $v->max_cbm, 'overPallets' => $v->pallets > 0 && $t->pallets > $v->pallets,
        ] : null;
        $costTotal = 0;
        foreach (self::COST_FIELDS as $field) {
            $costTotal += (float) ($t->cost?->getAttribute($field) ?? 0);
        }

        $data = U::shape($t->toArray(), [
            'warehouse' => ['id', 'code', 'nameAr', 'nameEn'],
            'stops.*.fo' => ['id', 'number', 'status', 'cartons', 'weightKg', 'cbm', 'loaded'],
            'stops.*.customer' => ['id', 'code', 'nameAr', 'nameEn', 'address', 'contact'],
            'stops.*.pod' => ['id', 'number', 'result', 'at'],
            'orders.*.fo' => ['id', 'number', 'status', 'cartons', 'weightKg', 'cbm', 'customer'],
            'orders.*.fo.customer' => ['code', 'nameAr'],
        ]);
        unset($data['podsCount'], $data['deliveriesCount'], $data['loadingPlansCount']);

        return $data + [
            '_count' => ['pods' => $t->pods_count, 'deliveries' => $t->deliveries_count, 'loadingPlans' => $t->loading_plans_count],
            'utilisation' => $utilisation,
            'stopSummary' => U::map($t->stops->countBy('status')->all()),
            'loadedCount' => $t->orders->where('loaded', true)->count(),
            'podsCount' => $t->pods_count,
            'costTotal' => $costTotal,
            'canEdit' => in_array($t->status, U::EDITABLE_TRIP_STATES, true),
        ];
    }

    /**
     * Trip creation. Loads (kg / cbm / pallets) and the temperature need are derived from the fulfillment orders.
     *
     * @param  array{warehouseCode:string, date:string, plannedStart?:?string, routeAr:string, routeCode?:?string, km?:float|int|null,
     *               tempNeed?:?string, foNumbers:string[], vehicleCode?:?string, driverCode?:?string, notes?:?string}  $dto
     */
    public function create(AuthUser $user, array $dto): array
    {
        $wh = Warehouse::where('code', $dto['warehouseCode'])->first()
            ?? throw AppError::notFound('WH_NOT_FOUND', "المستودع {$dto['warehouseCode']} غير موجود", 'Warehouse not found');
        $date = U::parseDate($dto['date']) ?? throw AppError::validation('BAD_DATE', 'تاريخ غير صالح', 'Invalid date');
        $numbers = array_values(array_unique(array_filter(array_map(fn ($s) => trim((string) $s), $dto['foNumbers']), fn ($s) => $s !== '')));
        $fos = FulfillmentOrder::with(['customer', 'so', 'lines.product', 'trip'])->whereIn('number', $numbers)->get()->keyBy('number');
        $missing = array_values(array_filter($numbers, fn ($n) => ! $fos->has($n)));
        if ($missing) {
            throw AppError::notFound('FO_NOT_FOUND', 'الطلبات غير موجودة: '.implode('، ', $missing), 'Fulfillment orders not found: '.implode(', ', $missing));
        }
        foreach ($fos as $f) {
            if (! in_array($f->status, ['packed', 'picked'], true)) {
                throw AppError::rule('FO_NOT_READY', "الطلب {$f->number} غير جاهز للإرسال (حالته «{$f->status}») — يجب أن يكون مجهزًا أو مُلتقطًا", "{$f->number} is {$f->status}; must be packed or picked");
            }
            if ($f->warehouse_id !== $wh->id) {
                throw AppError::rule('FO_OTHER_WH', "الطلب {$f->number} من مستودع آخر", "{$f->number} belongs to another warehouse");
            }
            if ($f->trip_id && $f->trip && ! in_array($f->trip->status, U::ENDED_TRIP_STATES, true)) {
                throw AppError::rule('FO_ON_TRIP', "الطلب {$f->number} مرتبط برحلة نشطة {$f->trip->number}", "{$f->number} is already on active trip {$f->trip->number}");
            }
        }
        $ordered = array_map(fn ($n) => $fos[$n], $numbers);
        $kg = U::round1(array_sum(array_map(fn ($f) => (float) ($f->weight_kg ?: 0), $ordered)));
        $cbm = U::round1(array_sum(array_map(fn ($f) => (float) ($f->cbm ?: 0), $ordered)));
        $pallets = array_sum(array_map(fn ($f) => max(1, (int) ceil(($f->cartons ?: 0) / 8)), $ordered));
        $derived = 'dry';
        foreach ($ordered as $f) {
            foreach ($f->lines as $line) {
                $class = $line->product?->storage_class;
                $need = $class === 'frozen' ? 'reefer' : ($class === 'chilled' ? 'chill' : 'dry');
                if (U::TEMP_RANK[$need] > U::TEMP_RANK[$derived]) {
                    $derived = $need;
                }
            }
        }
        $asked = ($dto['tempNeed'] ?? null) ?: 'dry';
        $tempNeed = (U::TEMP_RANK[$asked] ?? 0) >= U::TEMP_RANK[$derived] ? $asked : $derived;
        $load = ['kg' => $kg, 'cbm' => $cbm, 'pallets' => $pallets, 'tempNeed' => $tempNeed];

        // optional vehicle / driver — validated with the same rules as /assign
        $vehicle = null;
        $driver = null;
        if (! empty($dto['vehicleCode'])) {
            $vehicle = $this->vehicleByCode($dto['vehicleCode']);
            $this->checkVehicleForTrip($vehicle, $load);
        }
        if (! empty($dto['driverCode'])) {
            $driver = $this->driverByCode($dto['driverCode']);
            $this->checkDriverForTrip($driver);
        }
        $route = ! empty($dto['routeCode']) ? Route::where('id', $dto['routeCode'])->orWhere('code', $dto['routeCode'])->first() : null;
        $status = $this->statusFor($vehicle?->id, $driver?->id);
        $notes = $dto['notes'] ?? null;

        $id = DB::transaction(function () use ($user, $dto, $wh, $date, $numbers, $ordered, $kg, $cbm, $pallets, $tempNeed, $vehicle, $driver, $route, $status, $notes) {
            $number = $this->numbering->next('TRP');
            $trip = Trip::create([
                'number' => $number, 'date' => $date, 'warehouse_id' => $wh->id, 'vehicle_id' => $vehicle?->id, 'driver_id' => $driver?->id,
                'route_ar' => $dto['routeAr'], 'route_en' => $route?->name ?: $dto['routeAr'], 'km' => (float) ($dto['km'] ?? 0), 'planned_start' => ($dto['plannedStart'] ?? null) ?: '08:00',
                'status' => $status, 'kg' => $kg, 'cbm' => $cbm, 'pallets' => $pallets, 'temp_need' => $tempNeed, 'created_by_id' => $user->id, 'created_by' => $user->nameAr,
            ]);
            TripCost::create(['trip_id' => $trip->id]);
            $this->event($trip->id, "أُنشئت الرحلة · {$user->nameAr}".($notes ? ' — '.$notes : ''), "Trip created · {$user->nameEn}");
            foreach ($ordered as $i => $f) {
                $stop = TripStop::create([
                    'trip_id' => $trip->id, 'seq' => $i + 1, 'customer_id' => $f->customer_id, 'customer_ar' => $f->customer->name_ar, 'customer_en' => $f->customer->name_en,
                    'so_id' => $f->so_id, 'fo_id' => $f->id, 'items' => (int) $f->lines->sum('qty'), 'kg' => $f->weight_kg, 'window' => $f->so?->window ?: $route?->window,
                    'contact' => $f->customer->contact, 'address' => $f->customer->address, 'planned_time' => $f->so?->window,
                    'lat' => $f->customer->lat, 'lng' => $f->customer->lng,
                ]);
                TripOrder::create(['trip_id' => $trip->id, 'fo_id' => $f->id, 'stop_id' => $stop->id]);
                $f->update(['trip_id' => $trip->id, 'seq' => $i + 1]);
            }
            if ($vehicle) {
                $this->transitionVehicle($user, $vehicle, 'assigned', "إسناد للرحلة {$number}");
                $this->event($trip->id, "إسناد {$vehicle->code} (".($vehicle->type_ar ?: $vehicle->kind).')', "Vehicle {$vehicle->code} assigned");
            }
            if ($driver) {
                $this->event($trip->id, "إسناد {$driver->name_ar}", 'Driver '.($driver->name_en ?: $driver->code).' assigned');
            }
            $this->audit->log($user, ['action' => 'TRIP.CREATE', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $number,
                'newValue' => ['warehouse' => $wh->code, 'fos' => $numbers, 'kg' => $kg, 'cbm' => $cbm, 'pallets' => $pallets, 'tempNeed' => $tempNeed, 'vehicle' => $vehicle?->code, 'driver' => $driver?->code]]);
            $this->audit->status($user, 'Trip', $trip->id, $number, null, $status);
            $count = count($ordered);
            $this->notify->activity($user, 'Trip', $trip->id, $number, "أُنشئت الرحلة {$number} ({$count} طلب · ".U::fmt0($kg).' كجم)', "Trip {$number} created ({$count} orders · ".U::fmt0($kg).' kg)');

            return $trip->id;
        });

        return $this->get($id);
    }

    /** @param  array{kg:float|int, cbm:float|int, pallets:int, tempNeed:string}  $load */
    private function checkVehicleForTrip(Vehicle $x, array $load, ?string $exceptTripId = null): void
    {
        $ca = $this->vehicleCanAssign($x);
        if (! $ca['ok']) {
            throw AppError::rule('VEHICLE_CANNOT_ASSIGN', "مرفوض: {$ca['why']}", $ca['whyEn']);
        }
        if ($this->isVehicleBusy($x->id, $exceptTripId)) {
            throw AppError::rule('VEHICLE_BUSY', 'مرفوض: المركبة برحلة متزامنة', 'Vehicle busy on a concurrent trip');
        }
        $why = $this->vehicleFitsTrip($x, $load);
        if ($why) {
            throw AppError::rule('VEHICLE_UNFIT', "مرفوض: {$why}", 'Vehicle does not fit the load');
        }
    }

    private function checkDriverForTrip(Driver $d, ?string $exceptTripId = null): void
    {
        $dc = $this->driverCanAssign($d);
        if (! $dc['ok']) {
            throw AppError::rule('DRIVER_CANNOT_ASSIGN', "مرفوض: {$dc['why']}", $dc['whyEn']);
        }
        if ($this->isDriverBusy($d->id, $exceptTripId)) {
            throw AppError::rule('DRIVER_BUSY', 'مرفوض: السائق برحلة متزامنة', 'Driver busy on a concurrent trip');
        }
    }

    private function statusFor(?string $vehicleId, ?string $driverId): string
    {
        return $vehicleId && $driverId ? 'dassigned' : ($vehicleId ? 'vassigned' : ($driverId ? 'dassigned' : 'planned'));
    }

    private function event(string $tripId, string $textAr, ?string $textEn = null, ?string $label = null): TripEvent
    {
        return TripEvent::create(['trip_id' => $tripId, 'at' => now(), 'label' => $label ?: U::hhmm(), 'text_ar' => $textAr, 'text_en' => $textEn]);
    }

    /** Vehicle and/or driver; audited; trip event; status → vassigned / dassigned. */
    public function assign(AuthUser $user, string $number, ?string $vehicleCode, ?string $driverCode): array
    {
        if (! $vehicleCode && ! $driverCode) {
            throw AppError::validation('ASSIGN_EMPTY', 'حدد مركبة أو سائقًا', 'Specify a vehicle or a driver');
        }
        $t = $this->tripByNumber($number);
        if (! in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
            throw AppError::rule('TRIP_LOCKED', "لا يمكن تعديل الإسناد — الرحلة «{$t->status}»", "Trip is {$t->status}; assignment locked");
        }
        $load = ['kg' => $t->kg, 'cbm' => $t->cbm, 'pallets' => $t->pallets, 'tempNeed' => $t->temp_need];
        $vehicle = $vehicleCode ? $this->vehicleByCode($vehicleCode) : null;
        $driver = $driverCode ? $this->driverByCode($driverCode) : null;
        if ($vehicle && $vehicle->id !== $t->vehicle_id) {
            $this->checkVehicleForTrip($vehicle, $load, $t->id);
        }
        if ($driver && $driver->id !== $t->driver_id) {
            $this->checkDriverForTrip($driver, $t->id);
        }

        DB::transaction(function () use ($user, $t, $vehicle, $driver) {
            $data = [];
            if ($vehicle && $vehicle->id !== $t->vehicle_id) {
                if ($t->vehicle) {
                    $this->walkVehicle($user, $t->vehicle, ['available'], "إلغاء إسناد {$t->number}");
                }
                $this->transitionVehicle($user, $vehicle, 'assigned', "إسناد للرحلة {$t->number}");
                $data['vehicle_id'] = $vehicle->id;
                $this->audit->log($user, ['action' => 'TRIP.ASSIGN_VEHICLE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'vehicle', 'oldValue' => $t->vehicle?->code ?: '—', 'newValue' => $vehicle->code]);
                $this->event($t->id, "إسناد {$vehicle->code} (".($vehicle->type_ar ?: $vehicle->kind).')'.($t->vehicle ? ' بدلًا من '.$t->vehicle->code : ''), "Vehicle {$vehicle->code} assigned");
            }
            if ($driver && $driver->id !== $t->driver_id) {
                $data['driver_id'] = $driver->id;
                $this->audit->log($user, ['action' => 'TRIP.ASSIGN_DRIVER', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'driver', 'oldValue' => $t->driver?->code ?: '—', 'newValue' => $driver->code]);
                $this->event($t->id, "إسناد {$driver->name_ar}".($t->driver ? ' بدلًا من '.$t->driver->name_ar : ''), 'Driver '.($driver->name_en ?: $driver->code).' assigned');
            }
            $status = $this->statusFor($vehicle?->id ?: $t->vehicle_id, $driver?->id ?: $t->driver_id);
            if ($status !== $t->status) {
                if (! Sm::can('TRIP_TRANSITIONS', $t->status, $status)) {
                    throw AppError::rule('TRIP_TRANSITION', "انتقال غير مسموح: {$t->status} ← {$status}", 'Invalid trip transition');
                }
                $data['status'] = $status;
                $this->audit->status($user, 'Trip', $t->id, $t->number, $t->status, $status);
            }
            if ($data) {
                Trip::where('id', $t->id)->update($data + ['updated_at' => now()]);
            }
            $text = ($vehicle ? 'أُسندت المركبة '.$vehicle->code : '').($vehicle && $driver ? ' و' : '').($driver ? 'أُسند السائق '.$driver->name_ar : '')." إلى {$t->number} — سُجل في Audit";
            $this->notify->activity($user, 'Trip', $t->id, $t->number, $text);
        });

        return $this->get($t->id);
    }

    public function unassign(AuthUser $user, string $number, ?bool $dropVehicle, ?bool $dropDriver): array
    {
        $t = $this->tripByNumber($number);
        if (! in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
            throw AppError::rule('TRIP_LOCKED', "لا يمكن تعديل الإسناد — الرحلة «{$t->status}»", "Trip is {$t->status}; assignment locked");
        }
        $dropVehicle ??= true;
        $dropDriver ??= true;

        DB::transaction(function () use ($user, $t, $dropVehicle, $dropDriver) {
            $data = [];
            if ($dropVehicle && $t->vehicle) {
                $this->walkVehicle($user, $t->vehicle, ['available'], "إلغاء إسناد {$t->number}");
                $data['vehicle_id'] = null;
                $this->audit->log($user, ['action' => 'TRIP.UNASSIGN_VEHICLE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'vehicle', 'oldValue' => $t->vehicle->code, 'newValue' => '—']);
                $this->event($t->id, "إلغاء إسناد المركبة {$t->vehicle->code}", "Vehicle {$t->vehicle->code} unassigned");
            }
            if ($dropDriver && $t->driver) {
                $data['driver_id'] = null;
                $this->audit->log($user, ['action' => 'TRIP.UNASSIGN_DRIVER', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'driver', 'oldValue' => $t->driver->code, 'newValue' => '—']);
                $this->event($t->id, "إلغاء إسناد السائق {$t->driver->name_ar}", "Driver {$t->driver->code} unassigned");
            }
            $status = $this->statusFor($dropVehicle ? null : $t->vehicle_id, $dropDriver ? null : $t->driver_id);
            if ($status !== $t->status) { // rollback of an assignment — not a forward transition
                $data['status'] = $status;
                $this->audit->status($user, 'Trip', $t->id, $t->number, $t->status, $status, 'unassign');
            }
            if ($data) {
                Trip::where('id', $t->id)->update($data + ['updated_at' => now()]);
            }
        });

        return $this->get($t->id);
    }

    /** @param  string[]  $stopIds */
    public function reorderStops(AuthUser $user, string $number, array $stopIds): array
    {
        $t = $this->tripByNumber($number);
        if (! in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
            throw AppError::rule('TRIP_LOCKED', 'لا يمكن إعادة ترتيب المحطات بعد بدء التحميل', 'Stops locked after loading starts');
        }
        $ids = $t->stops->pluck('id')->all();
        if (count($stopIds) !== count($ids) || array_diff($stopIds, $ids) || count(array_unique($stopIds)) !== count($stopIds)) {
            throw AppError::validation('STOPS_MISMATCH', 'قائمة المحطات لا تطابق محطات الرحلة', 'Stop list must contain every stop exactly once');
        }

        DB::transaction(function () use ($user, $t, $stopIds, $ids) {
            foreach ($stopIds as $i => $id) { // two passes: (trip_id, seq) is unique
                TripStop::where('id', $id)->update(['seq' => -($i + 1)]);
            }
            foreach ($stopIds as $i => $id) {
                TripStop::where('id', $id)->update(['seq' => $i + 1]);
                $stop = $t->stops->firstWhere('id', $id);
                if ($stop->fo_id) {
                    FulfillmentOrder::where('id', $stop->fo_id)->update(['seq' => $i + 1]);
                }
            }
            $this->audit->log($user, ['action' => 'TRIP.REORDER_STOPS', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'oldValue' => $ids, 'newValue' => array_values($stopIds)]);
            $this->event($t->id, "أُعيد ترتيب المحطات · {$user->nameAr}", 'Stops reordered');
        });

        return $this->get($t->id);
    }

    public function cancel(AuthUser $user, string $number, string $reason): array
    {
        $t = $this->tripByNumber($number);
        if (! Sm::can('TRIP_TRANSITIONS', $t->status, 'cancelled')) {
            throw AppError::rule('TRIP_TRANSITION', "لا يمكن إلغاء الرحلة — حالتها «{$t->status}»", "Trip {$t->status} cannot be cancelled");
        }
        if ($t->orders->contains(fn ($o) => $o->loaded)) {
            throw AppError::rule('TRIP_LOADED', 'لا يمكن الإلغاء — توجد طلبات محمَّلة، أفرغ المركبة أولًا', 'Loaded orders exist; unload first');
        }

        DB::transaction(function () use ($user, $t, $reason) {
            $from = $t->status;
            FulfillmentOrder::where('trip_id', $t->id)->update(['trip_id' => null]);
            if ($t->vehicle) {
                $this->walkVehicle($user, $t->vehicle, ['available'], "إلغاء الرحلة {$t->number}");
            }
            Trip::where('id', $t->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->event($t->id, "أُلغيت الرحلة — {$reason} · {$user->nameAr}", "Trip cancelled — {$reason}");
            $this->audit->status($user, 'Trip', $t->id, $t->number, $from, 'cancelled', $reason);
            $this->notify->activity($user, 'Trip', $t->id, $t->number, "أُلغيت الرحلة {$t->number} — {$reason}", "Trip {$t->number} cancelled — {$reason}", ['disp']);
        });

        return $this->get($t->id);
    }

    /** Every stop processed; KPIs, odometer, driver performance; vehicle back to available through the state machine. */
    public function close(AuthUser $user, string $number): array
    {
        $t = $this->tripByNumber($number);
        if ($t->status === 'closed') {
            throw AppError::rule('TRIP_CLOSED', 'الرحلة مقفلة مسبقًا', 'Trip already closed');
        }
        if (! in_array($t->status, ['onroute', 'partial', 'completed', 'returning', 'failed'], true)) {
            throw AppError::rule('TRIP_NOT_CLOSABLE', "لا يمكن الإقفال — حالة الرحلة «{$t->status}»", "Trip {$t->status} cannot be closed");
        }
        $open = $t->stops->filter(fn ($s) => ! in_array($s->status, U::PROCESSED_STOP_STATES, true))->count();
        if ($open) {
            throw AppError::rule('TRIP_OPEN_STOPS', "لا يمكن الإقفال — {$open} محطة غير معالجة", "{$open} stop(s) not processed");
        }
        $delivered = $t->stops->whereIn('status', ['delivered', 'partial'])->count();
        $failed = $t->stops->whereIn('status', ['failed', 'rejected'])->count();

        DB::transaction(function () use ($user, $t, $delivered, $failed) {
            $now = now();
            $from = $t->status;
            if ($from === 'onroute') {
                $mid = $failed ? 'partial' : 'completed';
                $this->audit->status($user, 'Trip', $t->id, $t->number, $from, $mid);
                $from = $mid;
            }
            Trip::where('id', $t->id)->update(['status' => 'closed', 'actual_end' => $now, 'closed_at' => $now, 'updated_at' => $now]);
            $this->event($t->id, "أُقفلت الرحلة — {$delivered} مسلّمة · {$failed} فاشلة · {$user->nameAr}", "Trip closed — {$delivered} delivered · {$failed} failed");
            $this->audit->status($user, 'Trip', $t->id, $t->number, $from, 'closed');
            $vehicleNote = null;
            if ($t->vehicle) {
                $oldOdo = $t->vehicle->odometer;
                $v = $this->walkVehicle($user, $t->vehicle, ['returning', 'atwh', 'available'], "إقفال الرحلة {$t->number}");
                $odo = $oldOdo + U::round($t->km ?: 0);
                $v->update(['odometer' => $odo]);
                $this->audit->log($user, ['action' => 'VEHICLE.ODOMETER', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $v->code, 'field' => 'odometer', 'oldValue' => $oldOdo, 'newValue' => $odo]);
                $vehicleNote = "عُدّل عداد {$v->code} إلى ".U::fmt0($odo).($v->state !== 'available' ? ' (حالة المركبة «'.U::vehicleLabel($v->state).'»)' : '');
            }
            if ($t->driver) {
                $d = $t->driver;
                $dels = $d->deliveries + $delivered;
                $fails = $d->fails + $failed;
                $okPct = $dels + $fails ? U::round(($dels / ($dels + $fails)) * 1000) / 10 : $d->ok_pct;
                $wasOnRoute = $d->state === 'onroute';
                $d->update(['trips' => $d->trips + 1, 'deliveries' => $dels, 'fails' => $fails, 'ok_pct' => $okPct, 'km' => $d->km + U::round($t->km ?: 0), 'state' => $wasOnRoute ? 'available' : $d->state]);
                if ($wasOnRoute) {
                    $this->audit->status($user, 'Driver', $d->id, $d->code, 'onroute', 'available', "إقفال الرحلة {$t->number}");
                }
            }
            $this->audit->log($user, ['action' => 'TRIP.CLOSE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['delivered' => $delivered, 'failed' => $failed, 'km' => $t->km, 'vehicle' => $t->vehicle?->code, 'driver' => $t->driver?->code]]);
            $this->notify->activity($user, 'Trip', $t->id, $t->number, "أُقفلت {$t->number} — حُسبت KPIs والتكلفة".($vehicleNote ? '، '.$vehicleNote : '').($t->driver ? '، حُدّث أداء السائق' : ''), "Trip {$t->number} closed");
        });

        return $this->get($t->id);
    }

    public function events(string $number): array
    {
        $t = $this->tripByNumber($number);

        return TripEvent::where('trip_id', $t->id)->orderBy('at')->orderBy('id')->get()->all();
    }

    /** @param  array{textAr:string, textEn?:?string, label?:?string}  $dto */
    public function addEvent(AuthUser $user, string $number, array $dto): TripEvent
    {
        $t = $this->tripByNumber($number);

        return DB::transaction(function () use ($user, $t, $dto) {
            $event = $this->event($t->id, "{$dto['textAr']} · {$user->nameAr}", ($dto['textEn'] ?? null) ?: $dto['textAr'], $dto['label'] ?? null);
            $this->audit->log($user, ['action' => 'TRIP.EVENT', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'newValue' => $dto['textAr']]);

            return $event->refresh();
        });
    }

    // ───────────── Transport Control Tower ─────────────
    /**
     * Counters only. Live GPS positions / ETA are not available (no telematics provider connected), so the tower
     * reports stored figures (`delayMin`, `eta` as entered) and never computes a position.
     */
    public function tower(?string $warehouseCode = null): array
    {
        $d0 = U::today();
        $d1 = $d0->copy()->addDay();
        $whId = $warehouseCode ? Warehouse::where('code', $warehouseCode)->value('id') : null;
        $trips = fn () => Trip::query()->when($whId, fn ($q) => $q->where('warehouse_id', $whId));

        $tripsToday = $trips()->where('date', '>=', $d0)->where('date', '<', $d1)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $vehicles = Vehicle::where('active', true)->when($whId, fn ($q) => $q->where('warehouse_id', $whId))->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n)->all();
        $drivers = Driver::where('active', true)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n) => (int) $n)->all();
        $alerts = FleetAlert::whereIn('status', ['open', 'assigned'])->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n) => (int) $n)->all();
        $lateTrips = $trips()->with(['vehicle', 'driver'])->where('delay_min', '>', 0)->whereIn('status', U::ACTIVE_TRIP_STATES)->orderByDesc('delay_min')->orderBy('id')->limit(20)->get()
            ->map(fn (Trip $t) => [
                'number' => $t->number, 'delayMin' => $t->delay_min, 'eta' => $t->eta, 'status' => $t->status, 'routeAr' => $t->route_ar,
                'vehicle' => $t->vehicle ? ['code' => $t->vehicle->code] : null,
                'driver' => $t->driver ? ['code' => $t->driver->code, 'nameAr' => $t->driver->name_ar] : null,
            ])->all();
        $openOpreq = OpsRequest::whereIn('status', ['submitted', 'review', 'approved'])->count();
        $activeTrips = $trips()->with('vehicle')->whereIn('status', [...U::ACTIVE_TRIP_STATES, 'vassigned', 'dassigned'])->whereNotNull('vehicle_id')->get();
        $util = $activeTrips->map(fn (Trip $t) => $t->vehicle?->max_kg ? $t->kg / $t->vehicle->max_kg : null)->filter(fn ($x) => $x !== null);

        return [
            'date' => $d0, 'tripsToday' => U::map($tripsToday), 'tripsTodayTotal' => array_sum($tripsToday), 'vehicles' => U::map($vehicles),
            'drivers' => ['available' => $drivers['available'] ?? 0, 'onroute' => $drivers['onroute'] ?? 0, 'off' => $drivers['off'] ?? 0, 'blocked' => Driver::where('blocked', true)->where('active', true)->count()],
            'openAlerts' => U::map($alerts), 'lateTrips' => $lateTrips, 'lateCount' => count($lateTrips), 'openOpsRequests' => $openOpreq,
            'capacityUtilisationPct' => $util->count() ? U::round(($util->sum() / $util->count()) * 100) : null, 'activeTrips' => $activeTrips->count(),
        ];
    }
}
