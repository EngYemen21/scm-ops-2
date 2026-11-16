<?php

namespace App\Services\Transport;

use App\Models\Driver;
use App\Models\FleetAlert;
use App\Models\FuelRecord;
use App\Models\FulfillmentOrder;
use App\Models\MaintenanceOrder;
use App\Models\OpsException;
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
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\MapboxMaps;
use App\Services\Transport\TransportUtil as U;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trips (TMS) + the reusable fleet rules that loading / dispatch / delivery may call:
 *   vehicleCanAssign(vehicle) · driverCanAssign(driver) · dispatchGate(trip) / assertCanDispatch(trip) ·
 *   recommendVehicles(trip) · recomputeEtas(trip) · transitionVehicle(...) · isVehicleBusy / isDriverBusy
 * Rules and Arabic messages mirror the reference system.
 *
 * Planned arrival: every stop carries `eta` (HH:mm) — the PLAN, computed from the planned start, the drive time between
 * the stops (maps provider when it answers, a straight-line estimate otherwise; `eta_source` says which) and the service
 * time per stop. It is not live tracking: the trip's own `eta` / `delayMin` / `gpsOnline` are returned exactly as a
 * telematics provider stored them, and nothing here invents a position. The customer's `window` is a promise and is
 * never changed by planning — `window_ok` only says whether the planned arrival falls inside it.
 */
class TripsService
{
    private const BLOCKING_VEHICLE_STATES = ['maintenance', 'breakdown', 'oos', 'inactive'];

    private const COST_FIELDS = ['fuel', 'driver', 'ot', 'maint', 'dep', 'tolls', 'parking', 'third', 'other'];

    /** Loading has started: the vehicle is fixed (goods are on it) but the driver may still be named or replaced. */
    private const LOADING_TRIP_STATES = ['loading', 'ready'];

    private const CLOSED_STOP_STATES = ['delivered', 'partial', 'failed', 'rejected', 'skipped'];

    /** Same lifetime as the route line of the trip map (TransportMapService) — the two share one cache entry. */
    private const ROUTE_CACHE_MINUTES = 15;

    /** Road distance is longer than the straight line between two points; used only when no maps provider answers. */
    private const DETOUR_FACTOR = 1.3;

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

    /**
     * The trip, read again under its row lock. Every change of assignment / stops / orders / status starts here inside
     * its transaction: what was read before the lock may be stale (loading may have started, another dispatcher may
     * have changed the trip), so the rules are always checked on this copy.
     */
    private function lockTrip(string $id): Trip
    {
        Trip::whereKey($id)->lockForUpdate()->first();

        return $this->tripByNumber($id);
    }

    /**
     * lockTrip() for the changes that also write the trip's ORDERS (stop order, add / remove an order, cancel).
     * Loading, unloading and dispatch (fulfilment domain) lock the orders first and the trip after them; taking the
     * same order here means the two can wait for each other but never deadlock. Orders are locked by id.
     *
     * @param  string[]  $moreFoIds  orders that are not on the trip yet (the one being added)
     */
    private function lockTripWithOrders(string $id, array $moreFoIds = []): Trip
    {
        $foIds = array_values(array_unique([...TripOrder::where('trip_id', $id)->pluck('fo_id')->all(), ...$moreFoIds]));
        sort($foIds);
        foreach ($foIds as $foId) {
            FulfillmentOrder::whereKey($foId)->lockForUpdate()->first();
        }

        return $this->lockTrip($id);
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

    /**
     * A driver may be assigned when: not blocked, licence AND iqama / ID not expired, active, on shift. (The medical
     * certificate only warns — FleetService::refreshDocumentAlerts.)
     *
     * @return array{ok:bool, why?:string, whyEn?:string}
     */
    public function driverCanAssign(Driver $d): array
    {
        if ($d->blocked) {
            return ['ok' => false, 'why' => 'السائق موقوف عن الإسناد — راجع وثائقه أو سبب الإيقاف في بطاقته', 'whyEn' => 'Driver is blocked from assignment — check the documents or the block reason on the driver card'];
        }
        $lic = U::daysTo($d->license_expiry);
        if ($lic !== null && $lic < 0) {
            return ['ok' => false, 'why' => 'رخصة منتهية', 'whyEn' => 'License expired'];
        }
        $minDays = (int) ($this->settings->get('fleet.driverLicenseMinDays') ?? 0);
        if ($lic !== null && $minDays > 0 && $lic < $minDays) {
            return ['ok' => false, 'why' => "الرخصة تنتهي خلال {$lic} يوم — أقل من الحد الأدنى ({$minDays} يوم)", 'whyEn' => "License expires in {$lic} day(s) — under the minimum of {$minDays}"];
        }
        $iqama = U::daysTo($d->iqama_expiry);
        if ($iqama !== null && $iqama < 0) {
            return ['ok' => false, 'why' => 'الإقامة / الهوية منتهية', 'whyEn' => 'Iqama / ID expired'];
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
     * The dispatch rule, as data — exactly what the dispatch endpoint (FulfillmentService::dispatch) enforces, so the
     * Trip Room can say beforehand what it will answer:
     *   trip loading / ready · a vehicle that is not out of service · a driver who may drive (not blocked, on shift,
     *   licence and iqama valid) · something loaded · EVERY packed order loaded · no open critical loading exception
     *   (wrong vehicle / capacity / temperature) on the trip.
     * Orders that are not packed yet do not block: they leave the trip at dispatch (`leaving`).
     *
     * @return array{ok:bool, blockers:list<array{code:string, ar:string, en:string}>, loaded:string[], packedNotLoaded:string[], leaving:string[]}
     */
    public function dispatchGate(Trip $t): array
    {
        $t->loadMissing(['vehicle', 'driver', 'orders.fo']);
        $orders = $t->orders->filter(fn (TripOrder $o) => $o->fo !== null);
        $numbers = fn ($set) => $set->map(fn (TripOrder $o) => $o->fo->number)->values()->all();
        $loaded = $numbers($orders->filter(fn (TripOrder $o) => $o->loaded));
        $packed = $numbers($orders->filter(fn (TripOrder $o) => ! $o->loaded && $o->fo->status === 'packed'));
        $leaving = $numbers($orders->filter(fn (TripOrder $o) => ! $o->loaded && $o->fo->status !== 'packed'));

        $blockers = [];
        $block = function (string $code, string $ar, string $en) use (&$blockers) {
            $blockers[] = ['code' => $code, 'ar' => $ar, 'en' => $en];
        };
        if (! in_array($t->status, self::LOADING_TRIP_STATES, true)) {
            in_array($t->status, U::EDITABLE_TRIP_STATES, true)
                ? $block('TRIP_NOT_READY', 'لم يبدأ التحميل بعد — حمّل الطلبات من لوحة التحميل والإرسال', 'Loading has not started — load the orders from the load & dispatch board')
                : $block('TRIP_NOT_READY', "حالة الرحلة «{$t->status}» لا تسمح بالإرسال", "Trip status {$t->status} cannot be dispatched");
        }
        if (! $t->vehicle) {
            $block('TRIP_NO_VEHICLE', 'لم تُسند مركبة', 'No vehicle assigned');
        } elseif (in_array($t->vehicle->state, self::BLOCKING_VEHICLE_STATES, true)) {
            $block('VEHICLE_STATE', 'حالة المركبة «'.U::vehicleLabel($t->vehicle->state).'» تمنع الخروج', 'Vehicle state "'.U::vehicleLabel($t->vehicle->state, 'en').'" blocks dispatch');
        }
        if (! $t->driver) {
            $block('TRIP_NO_DRIVER', 'لم يُسند سائق — أسنده من تبويب الإسناد (متاح أثناء التحميل)', 'No driver assigned — assign one in the Assignment tab (possible while loading)');
        } elseif ($why = $this->driverDispatchBlock($t->driver)) {
            $block('DRIVER_BLOCKED', "السائق غير متاح: {$why[0]}", "Driver unavailable: {$why[1]}");
        }
        if ($packed) {
            $block('TRIP_NOT_LOADED', count($packed).' طلب مجهّز غير محمَّل: '.implode('، ', $packed).' — حمّله أو أزله من الرحلة', count($packed).' packed order(s) not loaded: '.implode(', ', $packed).' — load or remove them');
        }
        if (! $loaded) {
            $block('TRIP_NOTHING_LOADED', 'لا طلبات محمَّلة', 'Nothing is loaded');
        }
        $critical = OpsException::where('entity_type', 'Trip')->where('entity_id', $t->id)->where('status', '!=', 'resolved')->where('severity', 'c')->whereIn('kind', ['wrongveh', 'capacity', 'temp'])->count();
        if ($critical) {
            $block('TRIP_EXCEPTIONS', "{$critical} استثناء حرج مفتوح على الرحلة (مركبة خاطئة / سعة / حرارة) — عالجه أولًا", "{$critical} open critical exception(s) on the trip — resolve them first");
        }
        // The endpoint has the last word: should the fulfilment domain refuse for a reason this list does not know, the
        // gate must not say "ready" — its reason becomes the blocker.
        if (! $blockers && ($endpoint = $this->endpointDispatchState($t)) && ! ($endpoint['allowed'] ?? true)) {
            $block((string) ($endpoint['code'] ?? 'DISPATCH_BLOCKED'), (string) ($endpoint['reasonAr'] ?? 'الإرسال مرفوض'), (string) ($endpoint['reasonEn'] ?? 'Dispatch refused'));
        }

        return ['ok' => ! $blockers, 'blockers' => $blockers, 'loaded' => $loaded, 'packedNotLoaded' => $packed, 'leaving' => $leaving];
    }

    /**
     * What the dispatch endpoint itself would answer (LoadingService::dispatchState of the fulfilment domain), when
     * that service publishes it. Null when it does not — the gate then stands on its own list.
     */
    private function endpointDispatchState(Trip $t): ?array
    {
        $service = 'App\\Services\\Fulfillment\\LoadingService';
        try {
            if (! class_exists($service) || ! method_exists($service, 'dispatchState')) {
                return null;
            }
            $state = app($service)->dispatchState($t);

            return is_array($state) ? $state : null;
        } catch (Throwable $e) {
            Log::warning("dispatch state of trip {$t->number} could not be read from the fulfilment domain: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * What stops a driver from leaving the gate: [Arabic, English] or null. Narrower than assignment (he is already on
     * the trip, so "busy" and "inactive" are not asked again) and the same documents: licence AND iqama.
     * Public so the dispatch endpoint of the fulfilment domain can apply the very same check.
     */
    public function driverDispatchBlock(Driver $d): ?array
    {
        $expired = fn ($date) => ($d = U::parseDate($date)) !== null && $d->copy()->startOfDay()->lt(now()->startOfDay());

        return match (true) {
            (bool) $d->blocked => ['وثائقه موقوفة', 'documents blocked'],
            $d->state === 'off' => ['خارج الوردية', 'off shift'],
            $expired($d->license_expiry) => ['رخصة منتهية', 'license expired'],
            $expired($d->iqama_expiry) => ['الإقامة / الهوية منتهية', 'iqama / ID expired'],
            default => null,
        };
    }

    /** Throws the first blocker of dispatchGate() as a business-rule error; returns the trip when it may leave. */
    public function assertCanDispatch(Trip|string $trip): Trip
    {
        $t = $this->tripByNumber(is_string($trip) ? $trip : $trip->id);
        $gate = $this->dispatchGate($t);
        if (! $gate['ok']) {
            $first = $gate['blockers'][0];
            throw AppError::rule($first['code'], "لا يمكن الإرسال — {$first['ar']}", $first['en'], ['blockers' => $gate['blockers']]);
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
    /** @param  array{status?:?string, kind?:?string, warehouse?:?string, date?:?string, vehicle?:?string, driver?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $query = Trip::query()->with(['warehouse', 'vehicle', 'driver', 'stops' => fn ($q) => $q->select(['id', 'trip_id', 'seq', 'status', 'eta', 'eta_source', 'window_ok'])->orderBy('seq')])
            ->withCount(['stops', 'orders', 'pods']);
        if (! empty($f['status'])) {
            $query->whereIn('status', explode(',', $f['status']));
        }
        if (! empty($f['kind'])) {
            $query->where('kind', $f['kind']); // delivery | transfer
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
            unset($row['stopsCount'], $row['ordersCount'], $row['podsCount'], $row['stops']);
            $open = $t->stops->filter(fn (TripStop $s) => ! in_array($s->status, self::CLOSED_STOP_STATES, true));

            return $row + [
                '_count' => ['stops' => $t->stops_count, 'orders' => $t->orders_count, 'pods' => $t->pods_count],
                'progress' => ['done' => $t->stops->count() - $open->count(), 'total' => $t->stops->count()],
                // the plan, not tracking: planned arrival at the next open stop and how many open stops would miss their window
                'plannedEta' => ['next' => $open->first()?->eta, 'source' => $open->first()?->eta_source, 'late' => $open->filter(fn (TripStop $s) => $s->window_ok === false)->count()],
            ];
        });
    }

    public function get(string $number): array
    {
        $t = Trip::with([
            'warehouse', 'vehicle', 'driver', 'dispatch', 'cost',
            'stops' => fn ($q) => $q->orderBy('seq'), 'stops.fo', 'stops.customer', 'stops.pod.attachments', 'stops.transfer:id,number,status',
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
            'stops.*.transfer' => ['id', 'number', 'status'],
            'stops.*.pod' => ['id', 'number', 'result', 'at', 'receiverName', 'deliveredQty', 'returnedQty', 'scanOk', 'noScanReason', 'attachments'],
            'stops.*.pod.attachments.*' => ['id', 'kind', 'fileName', 'mime', 'size', 'storageKey', 'status'],
            'orders.*.fo' => ['id', 'number', 'status', 'cartons', 'weightKg', 'cbm', 'customer'],
            'orders.*.fo.customer' => ['code', 'nameAr'],
        ]);
        unset($data['podsCount'], $data['deliveriesCount'], $data['loadingPlansCount']);
        $editable = in_array($t->status, U::EDITABLE_TRIP_STATES, true);
        $loading = in_array($t->status, self::LOADING_TRIP_STATES, true);

        return $data + [
            '_count' => ['pods' => $t->pods_count, 'deliveries' => $t->deliveries_count, 'loadingPlans' => $t->loading_plans_count],
            'utilisation' => $utilisation,
            'stopSummary' => U::map($t->stops->countBy('status')->all()),
            'loadedCount' => $t->orders->where('loaded', true)->count(),
            'podsCount' => $t->pods_count,
            'costTotal' => $costTotal,
            // planning stage: vehicle, driver, stop order and the list of orders may all change
            'canEdit' => $editable,
            // loading started: the vehicle is fixed (unload first), the driver may still be named or replaced
            'canAssignDriver' => $editable || $loading,
            // an order that is not loaded may leave the trip while planning or loading (never the last one — cancel the trip instead)
            'canRemoveOrders' => ($editable || $loading) && $t->orders->count() > 1,
            'dispatchGate' => $editable || $loading ? $this->dispatchGate($t) : null,
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
            $this->assertPlannable($f, $wh->id);
        }
        $ordered = array_map(fn ($n) => $fos[$n], $numbers);
        ['kg' => $kg, 'cbm' => $cbm, 'pallets' => $pallets] = self::loadOf($ordered);
        $derived = self::tempNeedOf($ordered);
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
                $this->attachOrder($trip, $f, $i + 1, $route);
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
        $this->planArrivals($id);

        return $this->get($id);
    }

    /** An order can be planned on a trip when it is packed or picked, belongs to the trip's warehouse and is on no other live trip. */
    private function assertPlannable(FulfillmentOrder $f, string $warehouseId): void
    {
        if (! in_array($f->status, ['packed', 'picked'], true)) {
            throw AppError::rule('FO_NOT_READY', "الطلب {$f->number} غير جاهز للإرسال (حالته «{$f->status}») — يجب أن يكون مجهزًا أو مُلتقطًا", "{$f->number} is {$f->status}; must be packed or picked");
        }
        if ($f->warehouse_id !== $warehouseId) {
            throw AppError::rule('FO_OTHER_WH', "الطلب {$f->number} من مستودع آخر", "{$f->number} belongs to another warehouse");
        }
        if ($f->trip_id && $f->trip && ! in_array($f->trip->status, U::ENDED_TRIP_STATES, true)) {
            throw AppError::rule('FO_ON_TRIP', "الطلب {$f->number} مرتبط برحلة نشطة {$f->trip->number}", "{$f->number} is already on active trip {$f->trip->number}");
        }
    }

    /**
     * Weight / volume / pallets of a set of orders (a pallet per 8 cartons, at least one per order).
     *
     * @param  iterable<FulfillmentOrder>  $fos
     * @return array{kg:float, cbm:float, pallets:int}
     */
    private static function loadOf(iterable $fos): array
    {
        $kg = $cbm = 0.0;
        $pallets = 0;
        foreach ($fos as $f) {
            $kg += (float) ($f->weight_kg ?: 0);
            $cbm += (float) ($f->cbm ?: 0);
            $pallets += max(1, (int) ceil(($f->cartons ?: 0) / 8));
        }

        return ['kg' => U::round1($kg), 'cbm' => U::round1($cbm), 'pallets' => $pallets];
    }

    /** The coldest storage class among the goods decides the vehicle: frozen → reefer, chilled → chill, else dry. */
    private static function tempNeedOf(iterable $fos): string
    {
        $derived = 'dry';
        foreach ($fos as $f) {
            foreach ($f->lines as $line) {
                $class = $line->product?->storage_class;
                $need = $class === 'frozen' ? 'reefer' : ($class === 'chilled' ? 'chill' : 'dry');
                if (U::TEMP_RANK[$need] > U::TEMP_RANK[$derived]) {
                    $derived = $need;
                }
            }
        }

        return $derived;
    }

    /** The stop + trip-order rows of one order on a trip. `window` is the customer's promise, copied from the sales order. */
    private function attachOrder(Trip $trip, FulfillmentOrder $f, int $seq, ?Route $route): TripStop
    {
        $stop = TripStop::create([
            'trip_id' => $trip->id, 'seq' => $seq, 'customer_id' => $f->customer_id, 'customer_ar' => $f->customer->name_ar, 'customer_en' => $f->customer->name_en,
            'so_id' => $f->so_id, 'fo_id' => $f->id, 'items' => (int) $f->lines->sum('qty'), 'kg' => $f->weight_kg, 'window' => $f->so?->window ?: $route?->window,
            'contact' => $f->customer->contact, 'address' => $f->customer->address, 'planned_time' => $f->so?->window,
            'lat' => $f->customer->lat, 'lng' => $f->customer->lng,
        ]);
        TripOrder::create(['trip_id' => $trip->id, 'fo_id' => $f->id, 'stop_id' => $stop->id]);
        $f->update(['trip_id' => $trip->id, 'seq' => $seq]);

        return $stop;
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

    /**
     * When may the assignment change? Planning stage: everything. Loading / ready: the driver only — the goods are on
     * the vehicle, so it changes only after an unload (which makes the trip editable again). Later: nothing.
     */
    private function assertAssignmentOpen(Trip $t, bool $touchesVehicle): void
    {
        if (in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
            return;
        }
        if (! in_array($t->status, self::LOADING_TRIP_STATES, true)) {
            throw AppError::rule('TRIP_LOCKED', "لا يمكن تعديل الإسناد — الرحلة «{$t->status}»", "Trip is {$t->status}; assignment locked");
        }
        if ($touchesVehicle) {
            throw AppError::rule('TRIP_LOADED_VEHICLE', 'لا يمكن تغيير المركبة بعد بدء التحميل — أنزل الحمولة أولًا من لوحة التحميل، ثم غيّر المركبة', 'The vehicle cannot change once loading has started — unload first, then change the vehicle');
        }
    }

    /**
     * Vehicle and/or driver; audited; trip event; status → vassigned / dassigned while planning. While the trip is
     * loading / ready only the driver may be named or replaced (a trip that started loading with a vehicle and no
     * driver could otherwise never leave); the status then stays as it is.
     */
    public function assign(AuthUser $user, string $number, ?string $vehicleCode, ?string $driverCode): array
    {
        if (! $vehicleCode && ! $driverCode) {
            throw AppError::validation('ASSIGN_EMPTY', 'حدد مركبة أو سائقًا', 'Specify a vehicle or a driver');
        }
        $id = $this->tripByNumber($number)->id;

        DB::transaction(function () use ($user, $id, $vehicleCode, $driverCode) {
            $t = $this->lockTrip($id);
            $this->assertAssignmentOpen($t, false);
            $vehicle = $vehicleCode ? $this->vehicleByCode($vehicleCode) : null;
            $driver = $driverCode ? $this->driverByCode($driverCode) : null;
            $newVehicle = $vehicle && $vehicle->id !== $t->vehicle_id;
            $newDriver = $driver && $driver->id !== $t->driver_id;
            $this->assertAssignmentOpen($t, $newVehicle);
            if ($newVehicle) {
                // two trips must not take the same vehicle at the same moment
                Vehicle::whereKey($vehicle->id)->lockForUpdate()->first();
                $vehicle = $this->vehicleByCode($vehicle->id);
                $this->checkVehicleForTrip($vehicle, ['kg' => $t->kg, 'cbm' => $t->cbm, 'pallets' => $t->pallets, 'tempNeed' => $t->temp_need], $t->id);
            }
            if ($newDriver) {
                $this->checkDriverForTrip($driver, $t->id);
            }

            $data = [];
            if ($newVehicle) {
                if ($t->vehicle) {
                    $this->walkVehicle($user, $t->vehicle, ['available'], "إلغاء إسناد {$t->number}");
                }
                $this->transitionVehicle($user, $vehicle, 'assigned', "إسناد للرحلة {$t->number}");
                $data['vehicle_id'] = $vehicle->id;
                $this->audit->log($user, ['action' => 'TRIP.ASSIGN_VEHICLE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'vehicle', 'oldValue' => $t->vehicle?->code ?: '—', 'newValue' => $vehicle->code]);
                $this->event($t->id, "إسناد {$vehicle->code} (".($vehicle->type_ar ?: $vehicle->kind).')'.($t->vehicle ? ' بدلًا من '.$t->vehicle->code : ''), "Vehicle {$vehicle->code} assigned");
            }
            if ($newDriver) {
                $data['driver_id'] = $driver->id;
                $this->audit->log($user, ['action' => 'TRIP.ASSIGN_DRIVER', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'driver', 'oldValue' => $t->driver?->code ?: '—', 'newValue' => $driver->code]);
                $this->event($t->id, "إسناد {$driver->name_ar}".($t->driver ? ' بدلًا من '.$t->driver->name_ar : ''), 'Driver '.($driver->name_en ?: $driver->code).' assigned');
            }
            if (in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
                $status = $this->statusFor($vehicle?->id ?: $t->vehicle_id, $driver?->id ?: $t->driver_id);
                if ($status !== $t->status) {
                    if (! Sm::can('TRIP_TRANSITIONS', $t->status, $status)) {
                        throw AppError::rule('TRIP_TRANSITION', "انتقال غير مسموح: {$t->status} ← {$status}", 'Invalid trip transition');
                    }
                    $data['status'] = $status;
                    $this->audit->status($user, 'Trip', $t->id, $t->number, $t->status, $status);
                }
            }
            if ($data) {
                Trip::where('id', $t->id)->update($data + ['updated_at' => now()]);
            }
            $text = ($vehicle ? 'أُسندت المركبة '.$vehicle->code : '').($vehicle && $driver ? ' و' : '').($driver ? 'أُسند السائق '.$driver->name_ar : '')." إلى {$t->number} — سُجل في Audit";
            $this->notify->activity($user, 'Trip', $t->id, $t->number, $text);
        });
        $this->planArrivals($id);

        return $this->get($id);
    }

    /**
     * Drops the vehicle and / or the driver. No flag at all drops both; when one flag is given the other one is left
     * alone ("unassign the driver" must not also release the vehicle). While loading / ready only the driver may go.
     */
    public function unassign(AuthUser $user, string $number, ?bool $dropVehicle, ?bool $dropDriver): array
    {
        $id = $this->tripByNumber($number)->id;
        if ($dropVehicle === null && $dropDriver === null) {
            $dropVehicle = $dropDriver = true;
        }
        $dropVehicle = (bool) $dropVehicle;
        $dropDriver = (bool) $dropDriver;

        DB::transaction(function () use ($user, $id, $dropVehicle, $dropDriver) {
            $t = $this->lockTrip($id);
            $this->assertAssignmentOpen($t, $dropVehicle && $t->vehicle_id !== null);
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
            if (in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
                $status = $this->statusFor($dropVehicle ? null : $t->vehicle_id, $dropDriver ? null : $t->driver_id);
                if ($status !== $t->status) { // rollback of an assignment — not a forward transition
                    $data['status'] = $status;
                    $this->audit->status($user, 'Trip', $t->id, $t->number, $t->status, $status, 'unassign');
                }
            }
            if ($data) {
                Trip::where('id', $t->id)->update($data + ['updated_at' => now()]);
            }
        });

        return $this->get($id);
    }

    /**
     * New visiting order of the stops (planning stage only). Only the sequence and the planned arrivals change — the
     * customer's window stays exactly as promised.
     *
     * @param  string[]  $stopIds
     */
    public function reorderStops(AuthUser $user, string $number, array $stopIds): array
    {
        $id = $this->tripByNumber($number)->id;

        DB::transaction(function () use ($user, $id, $stopIds) {
            $t = $this->lockTripWithOrders($id);
            if (! in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
                throw AppError::rule('TRIP_LOCKED', 'لا يمكن إعادة ترتيب المحطات بعد بدء التحميل', 'Stops locked after loading starts');
            }
            $ids = $t->stops->pluck('id')->all();
            if (count($stopIds) !== count($ids) || array_diff($stopIds, $ids) || count(array_unique($stopIds)) !== count($stopIds)) {
                throw AppError::validation('STOPS_MISMATCH', 'قائمة المحطات لا تطابق محطات الرحلة', 'Stop list must contain every stop exactly once');
            }
            foreach ($stopIds as $i => $stopId) { // two passes: (trip_id, seq) is unique
                TripStop::where('id', $stopId)->update(['seq' => -($i + 1)]);
            }
            foreach ($stopIds as $i => $stopId) {
                TripStop::where('id', $stopId)->update(['seq' => $i + 1]);
                $stop = $t->stops->firstWhere('id', $stopId);
                if ($stop->fo_id) {
                    FulfillmentOrder::where('id', $stop->fo_id)->update(['seq' => $i + 1]);
                }
            }
            $this->audit->log($user, ['action' => 'TRIP.REORDER_STOPS', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'oldValue' => $ids, 'newValue' => array_values($stopIds)]);
            $this->event($t->id, "أُعيد ترتيب المحطات · {$user->nameAr}", 'Stops reordered');
        });
        $this->planArrivals($id);

        return $this->get($id);
    }

    /**
     * Adds a packed / picked order to a trip that is still being planned: same checks as creation (state, warehouse,
     * not on another live trip), and the assigned vehicle must still fit the new load. The order becomes the last stop.
     */
    public function addOrder(AuthUser $user, string $number, string $foNumber): array
    {
        $id = $this->tripByNumber($number)->id;
        $foNumber = trim($foNumber);

        $foId = FulfillmentOrder::where(fn ($w) => $w->where('number', $foNumber)->orWhere('id', $foNumber))->value('id')
            ?? throw AppError::notFound('FO_NOT_FOUND', "الطلب {$foNumber} غير موجود", "Fulfillment order {$foNumber} not found");

        DB::transaction(function () use ($user, $id, $foId) {
            $t = $this->lockTripWithOrders($id, [$foId]);
            if (! in_array($t->status, U::EDITABLE_TRIP_STATES, true)) {
                throw AppError::rule('TRIP_LOCKED', "لا تُضاف طلبات بعد بدء التحميل — الرحلة «{$t->status}»", "Orders cannot be added once loading has started (trip is {$t->status})");
            }
            $f = FulfillmentOrder::with(['customer', 'so', 'lines.product', 'trip'])->findOrFail($foId); // read under its lock
            $this->assertPlannable($f, $t->warehouse_id);
            $fos = FulfillmentOrder::with('lines.product')->whereIn('id', $t->orders->pluck('fo_id'))->get()->push($f);
            $load = self::loadOf($fos);
            $need = self::tempNeedOf([$f]);
            $load['tempNeed'] = U::TEMP_RANK[$need] > (U::TEMP_RANK[$t->temp_need] ?? 0) ? $need : $t->temp_need;
            if ($t->vehicle && ($why = $this->vehicleFitsTrip($t->vehicle, $load))) {
                throw AppError::rule('VEHICLE_UNFIT', "مرفوض: {$why} — المركبة المسندة {$t->vehicle->code} لا تتحمل إضافة {$f->number}", "The assigned vehicle {$t->vehicle->code} does not fit the load with {$f->number}");
            }
            $route = $t->route_en ? Route::where('name', $t->route_en)->first() : null;
            $seq = ((int) $t->stops->max('seq')) + 1;
            $this->attachOrder($t, $f, $seq, $route);
            Trip::where('id', $t->id)->update(['kg' => $load['kg'], 'cbm' => $load['cbm'], 'pallets' => $load['pallets'], 'temp_need' => $load['tempNeed'], 'updated_at' => now()]);
            $this->audit->log($user, ['action' => 'TRIP.ADD_ORDER', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'order', 'oldValue' => null,
                'newValue' => ['fo' => $f->number, 'stop' => $seq, 'kg' => $load['kg'], 'cbm' => $load['cbm'], 'pallets' => $load['pallets']]]);
            $this->event($t->id, "أُضيف الطلب {$f->number} ({$f->customer->name_ar}) كمحطة {$seq} · {$user->nameAr}", "Order {$f->number} added as stop {$seq}");
            $this->notify->activity($user, 'Trip', $t->id, $t->number, "أُضيف الطلب {$f->number} إلى الرحلة {$t->number} (المحطة {$seq})", "Order {$f->number} added to trip {$t->number} (stop {$seq})", ['disp', 'wm']);
        });
        $this->planArrivals($id);

        return $this->get($id);
    }

    /**
     * Takes an order off a trip while it is planned or loading — as long as the order itself is NOT loaded (a loaded
     * order is unloaded first). Its stop is deleted, the sequence is closed up, the trip's load is recomputed and the
     * order stays packed / picked, free for another trip. The last order cannot leave: cancel the trip instead.
     */
    public function removeOrder(AuthUser $user, string $number, string $foNumber, ?string $reason = null): array
    {
        $id = $this->tripByNumber($number)->id;
        $foNumber = trim($foNumber);

        DB::transaction(function () use ($user, $id, $foNumber, $reason) {
            $t = $this->lockTripWithOrders($id);
            if (! in_array($t->status, [...U::EDITABLE_TRIP_STATES, ...self::LOADING_TRIP_STATES], true)) {
                throw AppError::rule('TRIP_LOCKED', "لا تُزال الطلبات من رحلة «{$t->status}»", "Orders cannot be removed from a trip that is {$t->status}");
            }
            $t->load('orders.fo.customer');
            $to = $t->orders->first(fn (TripOrder $o) => $o->fo && (mb_strtoupper($o->fo->number) === mb_strtoupper($foNumber) || $o->fo_id === $foNumber))
                ?? throw AppError::rule('FO_NOT_ON_TRIP', "الطلب {$foNumber} غير تابع لهذه الرحلة", "Order {$foNumber} is not on this trip");
            $f = $to->fo;
            if (TripOrder::whereKey($to->id)->lockForUpdate()->value('loaded') || $f->loaded) {
                throw AppError::rule('ORDER_LOADED', "الطلب {$f->number} محمَّل على المركبة — أنزله أولًا من لوحة التحميل ثم أزله", "Order {$f->number} is loaded — unload it first, then remove it");
            }
            if ($t->orders->count() <= 1) {
                throw AppError::rule('TRIP_LAST_ORDER', "{$f->number} هو آخر طلب في الرحلة — ألغِ الرحلة بدلًا من إزالته", "{$f->number} is the last order of the trip — cancel the trip instead");
            }
            $stop = $t->stops->firstWhere('id', $to->stop_id) ?? $t->stops->firstWhere('fo_id', $f->id);
            $to->delete();
            FulfillmentOrder::whereKey($f->id)->update(['trip_id' => null, 'seq' => 0]);
            if ($stop) {
                TripStop::whereKey($stop->id)->delete();
                // close the gap: ascending order, so every new number is already free ((trip_id, seq) is unique)
                $seq = 0;
                foreach ($t->stops->where('id', '!=', $stop->id)->sortBy('seq') as $rest) {
                    $seq++;
                    if ($rest->seq !== $seq) {
                        TripStop::whereKey($rest->id)->update(['seq' => $seq]);
                        if ($rest->fo_id) {
                            FulfillmentOrder::whereKey($rest->fo_id)->update(['seq' => $seq]);
                        }
                    }
                }
            }
            $load = self::loadOf(FulfillmentOrder::whereIn('id', $t->orders->where('id', '!=', $to->id)->pluck('fo_id'))->get());
            Trip::where('id', $t->id)->update(['kg' => $load['kg'], 'cbm' => $load['cbm'], 'pallets' => $load['pallets'], 'updated_at' => now()]);
            $why = $reason ? " — {$reason}" : '';
            $this->audit->log($user, ['action' => 'TRIP.REMOVE_ORDER', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'order',
                'oldValue' => ['fo' => $f->number, 'stop' => $stop?->seq], 'newValue' => ['reason' => $reason, 'kg' => $load['kg'], 'cbm' => $load['cbm'], 'pallets' => $load['pallets']]]);
            $this->event($t->id, "أُزيل الطلب {$f->number} ({$f->customer?->name_ar}) من الرحلة{$why} · {$user->nameAr}", "Order {$f->number} removed from the trip");
            $this->notify->activity($user, 'Trip', $t->id, $t->number, "أُزيل الطلب {$f->number} من الرحلة {$t->number}{$why} — يبقى «{$f->status}» ومتاحًا لرحلة أخرى", "Order {$f->number} removed from trip {$t->number} — it stays {$f->status}, free for another trip", ['disp', 'wm']);
        });
        $this->planArrivals($id);

        return $this->get($id);
    }

    public function cancel(AuthUser $user, string $number, string $reason): array
    {
        $id = $this->tripByNumber($number)->id;

        DB::transaction(function () use ($user, $id, $reason) {
            $t = $this->lockTripWithOrders($id);
            if (! Sm::can('TRIP_TRANSITIONS', $t->status, 'cancelled')) {
                throw AppError::rule('TRIP_TRANSITION', "لا يمكن إلغاء الرحلة — حالتها «{$t->status}»", "Trip {$t->status} cannot be cancelled");
            }
            if ($t->orders->contains(fn ($o) => $o->loaded)) {
                throw AppError::rule('TRIP_LOADED', 'لا يمكن الإلغاء — توجد طلبات محمَّلة، أفرغ المركبة أولًا', 'Loaded orders exist; unload first');
            }
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

        return $this->get($id);
    }

    /**
     * Every stop processed → closed: odometer, driver performance, vehicle back to available through the state machine,
     * and the cost sheet (TripCostService). Costing never blocks the close: when it fails the trip is closed anyway, an
     * activity line says so, and the message only claims a computed cost when there is one.
     *
     * `$odometer`: the reading at the return, when the dispatcher has it (must not be below the vehicle's current
     * reading). Without it the trip's planned distance is added, as before.
     */
    public function close(AuthUser $user, string $number, ?int $odometer = null): array
    {
        $id = $this->tripByNumber($number)->id;
        $summary = [];

        DB::transaction(function () use ($user, $id, $odometer, &$summary) {
            $t = $this->lockTrip($id); // a double click must not add the distance and the driver's figures twice
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
            if ($odometer !== null && $t->vehicle && $odometer < $t->vehicle->odometer) {
                throw AppError::rule('ODOMETER_BACKWARDS', "قراءة العداد {$odometer} أقل من عداد المركبة الحالي {$t->vehicle->odometer} — راجع القراءة", "Odometer reading {$odometer} is below the vehicle's current {$t->vehicle->odometer}");
            }
            $delivered = $t->stops->whereIn('status', ['delivered', 'partial'])->count();
            $failed = $t->stops->whereIn('status', ['failed', 'rejected'])->count();

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
            $vehicleNote = $vehicleNoteEn = null;
            if ($t->vehicle) {
                $oldOdo = $t->vehicle->odometer;
                $v = $this->walkVehicle($user, $t->vehicle, ['returning', 'atwh', 'available'], "إقفال الرحلة {$t->number}");
                $readDuringTrip = $odometer === null && FuelRecord::where('vehicle_id', $t->vehicle->id)->whereNull('voided_at')->whereNotNull('odometer')
                    ->where('created_at', '>=', $t->actual_start ?? $t->dispatched_at ?? $t->created_at)->exists();
                $odo = $odometer ?? ($readDuringTrip ? $oldOdo : $oldOdo + U::round($t->km ?: 0));
                $v->update(['odometer' => $odo]);
                $this->audit->log($user, ['action' => 'VEHICLE.ODOMETER', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $v->code, 'field' => 'odometer', 'oldValue' => $oldOdo, 'newValue' => $odo]);
                $vehicleNote = "عُدّل عداد {$v->code} إلى ".U::fmt0($odo).($odometer !== null ? ' (قراءة فعلية)' : ($readDuringTrip ? ' (قراءة تعبئة الوقود أثناء الرحلة)' : ' (بإضافة مسافة الرحلة المخططة)')).($v->state !== 'available' ? ' · حالة المركبة «'.U::vehicleLabel($v->state).'»' : '');
                $vehicleNoteEn = "{$v->code} odometer set to ".U::fmt0($odo).($odometer !== null ? ' (reading)' : ($readDuringTrip ? ' (fuel reading taken during the trip)' : ' (planned distance added)'));
            }
            if ($t->driver) {
                $d = Driver::whereKey($t->driver->id)->lockForUpdate()->first();
                $dels = $d->deliveries + $delivered;
                $fails = $d->fails + $failed;
                $okPct = $dels + $fails ? U::round(($dels / ($dels + $fails)) * 1000) / 10 : $d->ok_pct;
                $wasOnRoute = $d->state === 'onroute';
                $d->update(['trips' => $d->trips + 1, 'deliveries' => $dels, 'fails' => $fails, 'ok_pct' => $okPct, 'km' => $d->km + U::round($t->km ?: 0), 'state' => $wasOnRoute ? 'available' : $d->state]);
                if ($wasOnRoute) {
                    $this->audit->status($user, 'Driver', $d->id, $d->code, 'onroute', 'available', "إقفال الرحلة {$t->number}");
                }
            }
            $cost = $this->costAtClose($user, $t);
            $this->audit->log($user, ['action' => 'TRIP.CLOSE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number,
                'newValue' => ['delivered' => $delivered, 'failed' => $failed, 'km' => $t->km, 'vehicle' => $t->vehicle?->code, 'driver' => $t->driver?->code, 'odometerReading' => $odometer, 'costComputed' => $cost['computed'], 'costTotal' => $cost['total']]]);
            [$costAr, $costEn] = match (true) {
                (bool) $cost['error'] => ['تعذّر حساب التكلفة — أعد حسابها من تبويب التكلفة', 'the cost could not be computed — recompute it from the Cost tab'],
                ! $cost['computed'] => ['لم تُحسب التكلفة بعد — احسبها من تبويب التكلفة', 'the cost is not computed yet — compute it from the Cost tab'],
                // a sheet of zeros is not "a cost": nothing was recorded for this trip to compute from
                (float) $cost['total'] <= 0 => ['لا تكلفة محسوبة: لم يُسجَّل لهذه الرحلة وقود أو ساعات أو سندات، ولا معدلات مُعدّة — راجع تبويب التكلفة', 'no cost: no fuel, hours or vouchers are recorded for this trip and no rates are configured — see the Cost tab'],
                default => ['حُسبت التكلفة: '.number_format((float) $cost['total'], 2).' ر.س'.($cost['missing'] ? " ({$cost['missing']} بنود بلا مصدر — راجع تبويب التكلفة)" : ''),
                    'cost computed: '.number_format((float) $cost['total'], 2).' SAR'.($cost['missing'] ? " ({$cost['missing']} component(s) have no source — see the Cost tab)" : '')],
            };
            $summary = [
                'message' => "أُقفلت {$t->number} — {$costAr}".($vehicleNote ? '، '.$vehicleNote : '').($t->driver ? '، حُدّث أداء السائق' : ''),
                'messageEn' => "Trip {$t->number} closed — {$costEn}".($vehicleNoteEn ? ', '.$vehicleNoteEn : '').($t->driver ? ', driver performance updated' : ''),
                'costComputed' => $cost['computed'],
            ];
            $this->notify->activity($user, 'Trip', $t->id, $t->number, $summary['message'], $summary['messageEn']);
        });

        return $this->get($id) + $summary;
    }

    /**
     * The cost sheet at close. Runs in its own savepoint inside the close transaction: a costing error is rolled back
     * alone, reported as an activity line, and the trip still closes. "Computed" is claimed only when the sheet carries
     * a computation stamp.
     *
     * @return array{computed:bool, total:?float, missing:int, error:?string} `missing` = components that have no data source
     */
    private function costAtClose(AuthUser $user, Trip $t): array
    {
        try {
            $sheet = DB::transaction(fn () => app(TripCostService::class)->compute(Trip::findOrFail($t->id)));
            $total = 0.0;
            foreach (self::COST_FIELDS as $field) {
                $total += (float) ($sheet[$field] ?? 0);
            }
            $missing = 0;
            foreach ((array) ($sheet['basis'] ?? []) as $component) {
                $missing += is_array($component) && ($component['source'] ?? null) === 'not_configured' ? 1 : 0;
            }

            return ['computed' => ! empty($sheet['computedAt'] ?? $sheet['computed_at'] ?? null), 'total' => round($total, 2), 'missing' => $missing, 'error' => null];
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
            Log::warning("trip cost at close failed for {$t->number}: {$error}");
            $this->notify->activity($user, 'Trip', $t->id, $t->number,
                "تعذّر حساب تكلفة الرحلة {$t->number} عند الإقفال — أُقفلت الرحلة، أعد حساب التكلفة من تبويب «التكلفة» ({$error})",
                "The cost of trip {$t->number} could not be computed at close — the trip is closed; recompute the cost from the Cost tab ({$error})", ['disp', 'finance']);

            return ['computed' => false, 'total' => null, 'missing' => 0, 'error' => $error];
        }
    }

    // ───────────── planned arrival per stop ─────────────

    /**
     * Plans the arrival at every stop: start = the trip's `planned_start` at the warehouse; the drive time of each leg
     * comes from the maps provider when it answers, otherwise straight-line distance × 1.3 ÷ `fleet.avgSpeedKph`;
     * `delivery.serviceMinutes` is spent at every stop. Writes `eta` (HH:mm), `eta_source` (maps | estimate) and
     * `window_ok` (planned arrival inside the customer's window; null when the stop has none) — and never the window.
     * A stop without coordinates gets no eta and the chain continues from the last known point; without a located
     * warehouse nothing can be planned and every eta is null. The trip's `planned_end` is the planned return.
     *
     * Called after create, reorder / optimise, assign and add / remove order. Safe to call any time.
     *
     * @return array{source:?string, planned:int, unlocated:int, late:int, plannedEnd:?string}
     */
    public function recomputeEtas(Trip|string $trip): array
    {
        $id = is_string($trip) ? $this->tripByNumber($trip)->id : $trip->id;
        $t = Trip::with(['warehouse', 'stops' => fn ($q) => $q->orderBy('seq'), 'stops.customer:id,lat,lng'])->findOrFail($id);
        $service = max(0, (int) $this->settings->get('delivery.serviceMinutes'));
        $speed = (float) $this->settings->get('fleet.avgSpeedKph');
        $origin = self::point($t->warehouse?->lat, $t->warehouse?->lng);
        $visited = $t->stops->filter(fn (TripStop $s) => $s->status !== 'skipped')->values();
        $points = [];
        foreach ($visited as $s) {
            if ($p = self::point($s->lat, $s->lng) ?? self::point($s->customer?->lat, $s->customer?->lng)) {
                $points[$s->id] = $p;
            }
        }

        $plan = [];
        $source = $plannedEnd = null;
        if ($origin && $points) {
            $chain = [$origin, ...array_values($points), $origin];
            $legs = $this->providerLegs($chain);
            $source = $legs ? 'maps' : 'estimate';
            $legs ??= self::estimatedLegs($chain, $speed > 0 ? $speed : 40.0);
            $clock = self::minutesOf($t->planned_start) ?? 8 * 60;
            $leg = 0;
            foreach ($visited as $s) {
                if (isset($points[$s->id])) {
                    $clock += $legs[$leg++];
                    $plan[$s->id] = [self::clock($clock), $source, self::insideWindow($clock, $s->window)];
                }
                $clock += $service; // the stop is visited even when the map does not know where it is
            }
            $plannedEnd = self::clock($clock + $legs[$leg]);
        }

        $late = 0;
        foreach ($t->stops as $s) {
            [$eta, $src, $ok] = $plan[$s->id] ?? [null, null, null];
            if ($s->eta !== $eta || $s->eta_source !== $src || $s->window_ok !== $ok) {
                TripStop::whereKey($s->id)->update(['eta' => $eta, 'eta_source' => $src, 'window_ok' => $ok]);
            }
            $late += $ok === false ? 1 : 0;
        }
        if ($t->planned_end !== $plannedEnd) {
            Trip::whereKey($t->id)->update(['planned_end' => $plannedEnd]);
        }

        return ['source' => $source, 'planned' => count($plan), 'unlocated' => $visited->count() - count($points), 'late' => $late, 'plannedEnd' => $plannedEnd];
    }

    /** recomputeEtas() for the write paths: planning the arrivals must never undo or fail the change that triggered it. */
    private function planArrivals(string $tripId): void
    {
        try {
            $this->recomputeEtas($tripId);
        } catch (Throwable $e) {
            Log::warning("planned arrivals of trip {$tripId} could not be computed: {$e->getMessage()}");
        }
    }

    /**
     * Drive minutes of every leg of the chain from the maps provider, or null when it has no answer (not configured,
     * error, too many points). Asks for exactly what the trip map draws (TransportMapService::routeFor — same point
     * list, same cache key), so planning the arrivals and drawing the route cost ONE provider call between them.
     *
     * @param  list<array{lat:float, lng:float}>  $chain
     * @return list<int>|null
     */
    private function providerLegs(array $chain): ?array
    {
        $adapter = AdapterFactory::maps();
        if (! $adapter->configured() || count($chain) > MapboxMaps::ROUTE_MAX) {
            return null;
        }
        $key = 'trip-route:'.sha1(json_encode(array_map(fn ($p) => [round($p['lat'], 5), round($p['lng'], 5)], $chain)));
        $route = Cache::get($key);
        if (! $route) {
            $route = $adapter->route($chain);
            if (($route['status'] ?? null) === 'ok') {
                Cache::put($key, $route, now()->addMinutes(self::ROUTE_CACHE_MINUTES));
            }
        }
        $legs = $route['legs'] ?? null;
        if (($route['status'] ?? null) !== 'ok' || ! is_array($legs) || count($legs) !== count($chain) - 1) {
            return null;
        }

        return array_map(fn ($l) => (int) ($l['minutes'] ?? 0), array_values($legs));
    }

    /**
     * @param  list<array{lat:float, lng:float}>  $chain
     * @return list<int> minutes per leg: straight-line km × detour factor ÷ average speed
     */
    private static function estimatedLegs(array $chain, float $speedKph): array
    {
        $legs = [];
        for ($i = 1; $i < count($chain); $i++) {
            $legs[] = (int) round(self::haversineKm($chain[$i - 1], $chain[$i]) * self::DETOUR_FACTOR / $speedKph * 60);
        }

        return $legs;
    }

    private static function haversineKm(array $a, array $b): float
    {
        $dLat = deg2rad($b['lat'] - $a['lat']);
        $dLng = deg2rad($b['lng'] - $a['lng']);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad($a['lat'])) * cos(deg2rad($b['lat'])) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($h)));
    }

    /** @return array{lat:float, lng:float}|null */
    private static function point(mixed $lat, mixed $lng): ?array
    {
        return is_numeric($lat) && is_numeric($lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
    }

    /** "08:30" → 510; null when it is not a time. */
    private static function minutesOf(?string $hhmm): ?int
    {
        return preg_match('/^\s*(\d{1,2})[:.](\d{2})/', (string) $hhmm, $m) && (int) $m[1] < 24 && (int) $m[2] < 60 ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /** Minutes since midnight of the trip day → "HH:mm"; an arrival after midnight says so ("01:20 +1"). */
    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60).($minutes >= 1440 ? ' +'.intdiv($minutes, 1440) : '');
    }

    /** Is the planned arrival inside a window written "HH:mm–HH:mm"? null when the stop has no (readable) window. */
    private static function insideWindow(int $minutes, ?string $window): ?bool
    {
        if (! $window || ! preg_match('/(\d{1,2})[:.](\d{2})\s*(?:[–—−~-]|to|إلى|الى)\s*(\d{1,2})[:.](\d{2})/u', $window, $m)) {
            return null;
        }
        $from = (int) $m[1] * 60 + (int) $m[2];
        $to = (int) $m[3] * 60 + (int) $m[4];
        if ($to < $from) {
            $to += 1440; // a window that runs over midnight
        }

        return $minutes >= $from && $minutes <= $to;
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
     * Counters only. Live GPS positions / live ETA are not available without a telematics provider, so the tower
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
