<?php

namespace App\Services\Transport;

use App\Finance\Services\FleetPosting;
use App\Models\Driver;
use App\Models\FleetAlert;
use App\Models\FuelRecord;
use App\Models\Incident;
use App\Models\MaintenanceOrder;
use App\Models\Notification;
use App\Models\OpsRequest;
use App\Models\ProofOfDelivery;
use App\Models\Role;
use App\Models\Route;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\User;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fleet: vehicles, drivers, maintenance, fuel, alerts, driver ops requests, routes, KPIs.
 *
 * Telematics are not connected: `gpsOnline` is stored as false for new vehicles and no position is ever generated here.
 */
class FleetService
{
    private const ALERT_OPEN = ['open', 'assigned', 'snoozed'];

    private const REFRESH_CACHE_KEY = 'fleet.alerts.lastRefresh';

    private const VEHICLE_DOC_FIELDS = ['regExpiry', 'insuranceExpiry', 'inspectionExpiry', 'opCardExpiry'];

    private const DRIVER_DOC_FIELDS = ['licenseExpiry', 'iqamaExpiry', 'medicalExpiry'];

    /** Open orders first, then closed, then cancelled (portable: no engine-specific FIELD()). */
    private const MAINT_ORDER = "CASE status WHEN 'open' THEN 0 WHEN 'closed' THEN 1 ELSE 2 END";

    /** A reason typed by a person for a correction / void / cancellation: at least this many characters. */
    private const REASON_MIN = 3;

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
        private readonly SettingsService $settings,
        private readonly TripsService $trips,
        private readonly TripCostService $tripCost,
    ) {}

    // ═══════════ trip cost sheet ═══════════
    /** Recompute a trip's cost sheet on demand (it is also computed at close and whenever a linked fuel record or voucher changes). */
    public function recomputeTripCost(AuthUser $user, string $number): array
    {
        $t = $this->trips->tripByNumber($number);

        return DB::transaction(function () use ($user, $t) {
            $before = TripCost::find($t->id);
            $old = 0.0;
            foreach (TripCostService::FIELDS as $field) {
                $old += (float) ($before?->getAttribute($field) ?? 0);
            }
            $sheet = $this->tripCost->compute($t);
            $this->audit->log($user, ['action' => 'TRIP.COST_RECOMPUTE', 'entityType' => 'Trip', 'entityId' => $t->id, 'entityNumber' => $t->number, 'field' => 'cost',
                'oldValue' => round($old, 2), 'newValue' => ['total' => $sheet['total']] + array_intersect_key($sheet, array_flip(TripCostService::FIELDS))]);

            return $sheet + ['number' => $t->number, 'message' => "أُعيد احتساب تكلفة {$t->number} — ".number_format($sheet['total'], 2).' ر.س', 'messageEn' => "Cost of {$t->number} recomputed — ".number_format($sheet['total'], 2).' SAR'];
        });
    }

    // ═══════════ vehicles ═══════════
    /** @param  array{state?:?string, kind?:?string, warehouse?:?string}  $f */
    public function listVehicles(Paging $page, array $f): array
    {
        $query = Vehicle::query()->with(['warehouse', 'maintenance' => fn ($q) => $q->where('status', 'open')])->withCount('trips');
        if (! empty($f['state'])) {
            $query->whereIn('state', explode(',', $f['state']));
        }
        if (! empty($f['kind'])) {
            $query->where('kind', $f['kind']);
        }
        if (! empty($f['warehouse'])) {
            $query->whereHas('warehouse', fn ($w) => $w->where('code', $f['warehouse']));
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('plate_ar', 'like', $like)->orWhere('plate_en', 'like', $like)->orWhere('brand', 'like', $like)->orWhere('model', 'like', $like));
        }
        $page->sort === 'state' ? $query->orderBy('state', $page->order)->orderBy('code') : $query->orderBy('code');
        $alertMap = FleetAlert::where('entity_type', 'Vehicle')->whereIn('status', self::ALERT_OPEN)->selectRaw('entity_code, count(*) as n')->groupBy('entity_code')->pluck('n', 'entity_code');

        return $page->paginate($query, function (Vehicle $v) use ($alertMap) {
            $docs = U::docInfos($v, U::VEHICLE_DOCS);
            $row = U::shape($v->toArray(), ['warehouse' => ['code', 'nameAr']]);
            unset($row['tripsCount']);

            return $row + ['_count' => ['trips' => $v->trips_count], 'docs' => $docs, 'docDays' => U::minDays($docs), 'openAlerts' => (int) ($alertMap[$v->code] ?? 0), 'canAssign' => $this->trips->vehicleCanAssign($v)];
        });
    }

    public function getVehicle(string $code): array
    {
        $v = Vehicle::with([
            'warehouse',
            'maintenance' => fn ($q) => $q->orderByRaw(self::MAINT_ORDER)->orderByDesc('start_date')->orderByDesc('id'),
            'fuel' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id')->limit(20), // voided fills stay listed (struck in the client)
            'fuel.driver', 'drivers',
        ])->withCount(['trips', 'pods'])->where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
            ?? throw AppError::notFound('VEHICLE_NOT_FOUND', "المركبة {$code} غير موجودة", "Vehicle {$code} not found");

        $alerts = FleetAlert::where('entity_code', $v->code)->whereIn('status', self::ALERT_OPEN)->orderByDesc('created_at')->orderByDesc('id')->get();
        $activeTrip = Trip::where('vehicle_id', $v->id)->whereIn('status', [...U::ACTIVE_TRIP_STATES, 'vassigned', 'dassigned'])->first();
        $recentTrips = Trip::with('driver')->where('vehicle_id', $v->id)->orderByDesc('date')->orderByDesc('id')->limit(10)->get();
        $docs = U::docInfos($v, U::VEHICLE_DOCS);
        $openMaint = $v->maintenance->where('status', 'open')->values();

        $data = U::shape($v->toArray(), ['fuel.*.driver' => ['code', 'nameAr'], 'drivers.*' => ['code', 'nameAr']]);
        unset($data['tripsCount'], $data['podsCount']);

        return $data + [
            '_count' => ['trips' => $v->trips_count, 'pods' => $v->pods_count],
            'docs' => $docs, 'docDays' => U::minDays($docs),
            'openMaintenance' => $openMaint->all(), 'openMaintenanceCost' => (float) $openMaint->sum('cost'),
            'fuelHistory' => $data['fuel'], 'tripsCount' => $v->trips_count, 'alerts' => $alerts->all(),
            'activeTrip' => $activeTrip ? ['number' => $activeTrip->number, 'status' => $activeTrip->status, 'routeAr' => $activeTrip->route_ar, 'date' => $activeTrip->date] : null,
            'recentTrips' => $recentTrips->map(fn (Trip $t) => ['number' => $t->number, 'status' => $t->status, 'routeAr' => $t->route_ar, 'date' => $t->date, 'km' => $t->km, 'kg' => $t->kg, 'driver' => $t->driver ? ['nameAr' => $t->driver->name_ar] : null])->all(),
            'maintenanceDueKm' => $v->next_maint_km !== null ? $v->next_maint_km - $v->odometer : null,
            'canAssign' => $this->trips->vehicleCanAssign($v),
            'allowedTransitions' => config('scm.VEHICLE_TRANSITIONS.'.$v->state, []),
        ];
    }

    public function createVehicle(AuthUser $user, array $dto): array
    {
        $code = mb_strtoupper(trim($dto['code']));
        if (Vehicle::where('code', $code)->exists()) {
            throw AppError::conflict('VEHICLE_CODE_TAKEN', 'رقم المركبة مستخدم', 'Vehicle code already used');
        }
        if (! ((float) $dto['maxKg'] > 0)) {
            throw AppError::validation('VEHICLE_MAXKG', 'الحمولة يجب أن تكون أكبر من صفر', 'Max load must be > 0');
        }
        $whCode = $dto['warehouseCode'] ?? null;
        $wh = $whCode ? Warehouse::where('code', $whCode)->first() : null;
        if ($whCode && ! $wh) {
            throw AppError::notFound('WH_NOT_FOUND', "المستودع {$whCode} غير موجود", 'Warehouse not found');
        }
        $kind = $dto['kind'] ?? 'dry';
        [$typeAr, $typeEn] = U::VEHICLE_TYPE_LABEL[$kind] ?? U::VEHICLE_TYPE_LABEL['dry'];
        $odometer = (int) ($dto['odometer'] ?? 0);
        $purchase = isset($dto['purchaseCost']) ? round((float) $dto['purchaseCost'], 2) : null;
        $salvage = isset($dto['salvageValue']) ? round((float) $dto['salvageValue'], 2) : null;
        self::assertSalvage($purchase, $salvage);

        $id = DB::transaction(function () use ($user, $dto, $code, $wh, $whCode, $kind, $typeAr, $typeEn, $odometer, $purchase, $salvage) {
            $v = Vehicle::create([
                'code' => $code, 'plate_ar' => $dto['plateAr'], 'plate_en' => $dto['plateEn'] ?? null, 'vin' => $dto['vin'], 'brand' => $dto['brand'], 'model' => $dto['model'] ?? null,
                'year' => isset($dto['year']) ? (int) $dto['year'] : null, 'kind' => $kind, 'ownership' => $dto['ownership'] ?? 'owned', 'type_ar' => $typeAr, 'type_en' => $typeEn,
                'temp_range' => $kind === 'reefer' ? '−18° / +4°' : ($kind === 'chill' ? '+2° / +6°' : '—'),
                'max_kg' => (float) $dto['maxKg'], 'max_cbm' => (float) $dto['maxCbm'], 'pallets' => (int) ($dto['pallets'] ?? 8) ?: 8, 'warehouse_id' => $wh?->id, 'odometer' => $odometer,
                'fuel_type' => $dto['fuelType'] ?? null, 'avg_km_l' => 6,
                'reg_expiry' => U::parseDate($dto['regExpiry']), 'insurance_expiry' => U::parseDate($dto['insuranceExpiry']), 'inspection_expiry' => U::parseDate($dto['inspectionExpiry']),
                'op_card_expiry' => U::parseDate(($dto['opCardExpiry'] ?? null) ?: $dto['regExpiry']),
                'gps_device_id' => $dto['gpsDeviceId'] ?? null, 'gps_online' => false, 'next_maint_km' => $odometer + 10000, 'state' => 'available',
                'purchase_cost' => $purchase, 'salvage_value' => $salvage, 'life_km' => isset($dto['lifeKm']) ? (int) $dto['lifeKm'] : null, // depreciation per km of the trip cost sheet
            ]);
            $this->audit->log($user, ['action' => 'VEHICLE.CREATE', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $code,
                'newValue' => ['kind' => $kind, 'maxKg' => (float) $dto['maxKg'], 'maxCbm' => (float) $dto['maxCbm'], 'warehouse' => $whCode]]);
            $this->audit->status($user, 'Vehicle', $v->id, $code, null, 'available');
            $this->notify->activity($user, 'Vehicle', $v->id, $code, "أُضيفت المركبة {$code} بحالة «متاحة» — الوثائق تحت مراقبة التنبيهات", "Vehicle {$code} added (available)");

            return $v->id;
        });

        return $this->getVehicle($id);
    }

    public function updateVehicle(AuthUser $user, string $code, array $dto): array
    {
        $v = $this->trips->vehicleByCode($code);
        $data = [];
        $changes = [];
        $set = function (string $field, string $column, mixed $new, mixed $old) use (&$data, &$changes) {
            if ($new !== $old) {
                $data[$column] = $new;
                $changes[$field] = [$old, $new];
            }
        };
        $types = [
            'plateAr' => 'string', 'plateEn' => 'string', 'vin' => 'string', 'brand' => 'string', 'model' => 'string', 'year' => 'int', 'ownership' => 'string', 'maxKg' => 'float', 'maxCbm' => 'float',
            'pallets' => 'int', 'fuelType' => 'string', 'gpsDeviceId' => 'string', 'avgKmL' => 'float', 'nextMaintKm' => 'int', 'active' => 'bool', 'lifeKm' => 'int',
        ];
        foreach ($types as $field => $type) {
            if (array_key_exists($field, $dto)) {
                $column = Str::snake($field);
                $set($field, $column, self::cast($dto[$field], $type), $v->getAttribute($column));
            }
        }
        // odometer: a higher reading is an update; a LOWER one is a correction and needs its reason (audited on its own)
        $odometerFix = null;
        if (isset($dto['odometer'])) {
            $reading = (int) $dto['odometer'];
            if ($reading < $v->odometer) {
                $why = trim((string) ($dto['odometerReason'] ?? ''));
                if (mb_strlen($why) < self::REASON_MIN) {
                    throw AppError::rule('VEHICLE_ODOMETER_LOWER', 'قراءة العداد ('.U::fmt0($reading).') أقل من القراءة الحالية ('.U::fmt0($v->odometer).') — تصحيح العداد للأدنى يحتاج سببًا مكتوبًا',
                        "Odometer {$reading} is below the current reading {$v->odometer} — lowering it needs a written reason", ['current' => $v->odometer]);
                }
                $data['odometer'] = $reading;
                $odometerFix = [$v->odometer, $reading, $why];
            } else {
                $set('odometer', 'odometer', $reading, $v->odometer);
            }
        }
        // cost card (decimal columns come back as strings: compare as numbers)
        foreach (['purchaseCost' => 'purchase_cost', 'salvageValue' => 'salvage_value'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $new = $dto[$field] === null ? null : round((float) $dto[$field], 2);
                $old = $v->getAttribute($column) === null ? null : (float) $v->getAttribute($column);
                $set($field, $column, $new, $old);
            }
        }
        self::assertSalvage(
            array_key_exists('purchase_cost', $data) ? $data['purchase_cost'] : ($v->purchase_cost === null ? null : (float) $v->purchase_cost),
            array_key_exists('salvage_value', $data) ? $data['salvage_value'] : ($v->salvage_value === null ? null : (float) $v->salvage_value),
        );
        if (! empty($dto['kind']) && $dto['kind'] !== $v->kind) {
            [$typeAr, $typeEn] = U::VEHICLE_TYPE_LABEL[$dto['kind']];
            $set('kind', 'kind', $dto['kind'], $v->kind);
            $data['type_ar'] = $typeAr;
            $data['type_en'] = $typeEn;
        }
        if (array_key_exists('maxKg', $dto) && ! ((float) $dto['maxKg'] > 0)) {
            throw AppError::validation('VEHICLE_MAXKG', 'الحمولة يجب أن تكون أكبر من صفر', 'Max load must be > 0');
        }
        foreach (['regExpiry' => 'reg_expiry', 'insuranceExpiry' => 'insurance_expiry', 'inspectionExpiry' => 'inspection_expiry', 'opCardExpiry' => 'op_card_expiry', 'nextMaintDate' => 'next_maint_date'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $new = U::parseDate($dto[$field]);
                $old = $v->getAttribute($column);
                if ($new?->getTimestamp() !== $old?->getTimestamp()) {
                    $data[$column] = $new;
                    $changes[$field] = [$old, $new];
                }
            }
        }
        if (array_key_exists('warehouseCode', $dto)) {
            $wh = $dto['warehouseCode'] ? Warehouse::where('code', $dto['warehouseCode'])->first() : null;
            if ($dto['warehouseCode'] && ! $wh) {
                throw AppError::notFound('WH_NOT_FOUND', 'المستودع غير موجود');
            }
            if ($wh?->id !== $v->warehouse_id) {
                $data['warehouse_id'] = $wh?->id;
                $changes['warehouse'] = [$v->warehouse_id ? Warehouse::where('id', $v->warehouse_id)->value('code') : null, $wh?->code];
            }
        }
        if (! $changes && ! $odometerFix) {
            return $this->getVehicle($v->id);
        }
        DB::transaction(function () use ($user, $v, $data, $changes, $odometerFix) {
            $v->update($data);
            foreach ($changes as $field => [$old, $new]) {
                $this->audit->log($user, ['action' => 'VEHICLE.UPDATE', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $v->code, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
            }
            if ($odometerFix) {
                [$old, $new, $why] = $odometerFix;
                $this->audit->log($user, ['action' => 'VEHICLE.ODOMETER_CORRECTION', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $v->code, 'field' => 'odometer', 'oldValue' => $old, 'newValue' => ['odometer' => $new, 'reason' => $why]]);
                $this->notify->activity($user, 'Vehicle', $v->id, $v->code, "صُحّح عداد {$v->code} من ".U::fmt0($old).' إلى '.U::fmt0($new)." — {$why}", "Odometer of {$v->code} corrected {$old} → {$new} — {$why}", ['disp']);
            }
            $renewed = array_values(array_intersect(self::VEHICLE_DOC_FIELDS, array_keys($changes)));
            if ($renewed) {
                $labels = array_column(array_filter(U::docInfos($v, U::VEHICLE_DOCS), fn ($doc) => in_array($doc['key'], $renewed, true)), 'labelAr');
                $this->notify->activity($user, 'Vehicle', $v->id, $v->code, "حُدّثت وثائق {$v->code}: ".implode(' · ', $labels), "Documents of {$v->code} updated: ".implode(', ', $renewed));
            }
            // documents, the odometer and the maintenance plan drive the vehicle's alerts: re-evaluate them now, not at the next sweep
            if ($v->active && ($renewed || $odometerFix || array_intersect(['odometer', 'nextMaintKm', 'nextMaintDate', 'active'], array_keys($changes)))) {
                $this->refreshVehicleAlerts($v, $user, $this->alertPolicy());
            }
        });

        return $this->getVehicle($v->id);
    }

    private static function assertSalvage(?float $purchase, ?float $salvage): void
    {
        if ($purchase !== null && $salvage !== null && $salvage > $purchase) {
            throw AppError::validation('VEHICLE_SALVAGE', 'قيمة الخردة لا تتجاوز تكلفة الشراء', 'The salvage value cannot exceed the purchase cost');
        }
    }

    /** VEHICLE_TRANSITIONS enforced; breakdown → critical alert + alternate suggestion. */
    public function setVehicleState(AuthUser $user, string $code, string $to, ?string $reason = null): array
    {
        $v = $this->trips->vehicleByCode($code);

        return DB::transaction(function () use ($user, $v, $to, $reason) {
            $this->trips->transitionVehicle($user, $v, $to, $reason);
            $alert = null;
            $alternate = null;
            if ($to === 'breakdown') {
                [$alert, $alternate] = $this->breakdownAlert($user, $v, $reason ?: '');
            }
            $msg = $to === 'breakdown'
                ? 'سُجل العطل — المركبة غير متاحة، أُشعر الـ Dispatcher'.($alternate ? '، البديل المقترح '.$alternate->code : '')
                : "حالة {$v->code}: ".U::vehicleLabel($to).($reason ? ' — '.$reason : '');
            $this->notify->activity($user, 'Vehicle', $v->id, $v->code, $msg, "Vehicle {$v->code}: {$to}", $to === 'breakdown' ? ['disp'] : []);

            return [
                'vehicle' => Vehicle::findOrFail($v->id), 'alert' => $alert,
                'alternate' => $alternate ? ['code' => $alternate->code, 'typeAr' => $alternate->type_ar, 'kind' => $alternate->kind, 'plateAr' => $alternate->plate_ar] : null,
                'message' => $msg,
            ];
        });
    }

    /**
     * Breakdown report form: state → breakdown regardless of the current state (a fact, not a workflow choice); alert with location.
     *
     * @param  array{vehicleCode:string, type:string, location:string, desc:string, canMove:bool}  $dto
     */
    public function reportBreakdown(AuthUser $user, array $dto): array
    {
        $v = $this->trips->vehicleByCode($dto['vehicleCode']);

        return DB::transaction(function () use ($user, $v, $dto) {
            $this->trips->transitionVehicle($user, $v, 'breakdown', "{$dto['type']} — {$dto['location']}", true);
            [$alert, $alternate] = $this->breakdownAlert($user, $v, "{$dto['desc']} · {$dto['location']}".($dto['canMove'] ? '' : ' — تحتاج سطحة'));
            $msg = "سُجل العطل — {$v->code} الآن «معطلة» وغير متاحة".($alternate ? ' · البديل المقترح '.$alternate->code : '');
            $this->notify->activity($user, 'Vehicle', $v->id, $v->code, $msg, "Breakdown {$v->code}", ['disp']);

            return [
                'vehicle' => Vehicle::findOrFail($v->id), 'alert' => $alert,
                'alternate' => $alternate ? ['code' => $alternate->code, 'typeAr' => $alternate->type_ar, 'kind' => $alternate->kind] : null,
                'message' => $msg,
            ];
        });
    }

    /** @return array{0:?FleetAlert, 1:?Vehicle} */
    private function breakdownAlert(AuthUser $user, Vehicle $v, string $detail): array
    {
        $alternate = null;
        $sameKind = Vehicle::with(['maintenance' => fn ($q) => $q->where('status', 'open')])->where('id', '!=', $v->id)->where('kind', $v->kind)->where('active', true)->orderBy('code')->get();
        foreach ($sameKind as $candidate) {
            if ($this->trips->vehicleCanAssign($candidate)['ok']) {
                $alternate = $candidate;
                break;
            }
        }
        $alert = $this->upsertAlert([
            'category' => 'breakdown', 'severity' => 'c', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityCode' => $v->code, 'dueDate' => U::fmtDate(U::today()), 'owner' => 'Dispatcher',
            'textAr' => "عطل {$v->code} — ".($detail ? $detail.' · ' : '').($alternate ? "البديل المقترح {$alternate->code} (".($alternate->type_ar ?: $alternate->kind).')' : 'لا بديل متاح بنفس النوع'),
            'textEn' => "Breakdown {$v->code}",
            'recommendAr' => $alternate ? "إسناد {$alternate->code} وتحويل الحمولة" : 'استئجار طرف ثالث', 'recommendEn' => $alternate ? 'Reassign' : 'Rent third-party',
        ], $user, true);

        return [$alert, $alternate];
    }

    // ═══════════ drivers ═══════════
    /** @param  array{state?:?string, blocked?:?string, shift?:?string}  $f */
    public function listDrivers(Paging $page, array $f): array
    {
        $query = Driver::query()->with(['defaultVehicle', 'user']);
        if (! empty($f['state'])) {
            $query->whereIn('state', explode(',', $f['state']));
        }
        if (isset($f['blocked']) && $f['blocked'] !== '') {
            $query->where('blocked', $f['blocked'] === 'true');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('employee_no', 'like', $like)->orWhere('mobile', 'like', $like));
        }
        $page->sort === 'rating' ? $query->orderBy('rating', $page->order)->orderBy('code') : $query->orderBy('code');

        $minDaysLic = (int) $this->settings->get('fleet.driverLicenseMinDays');

        return $page->paginate($query, function (Driver $d) use ($minDaysLic) {
            $docs = self::driverDocs($d);

            return U::shape($d->toArray(), ['defaultVehicle' => ['code', 'plateAr'], 'user' => ['username', 'active']])
                + ['docs' => $docs, 'docDays' => U::minDays($docs), 'docBlock' => self::driverDocBlock($d, $minDaysLic), 'canAssign' => $this->trips->driverCanAssign($d)];
        });
    }

    /** Driver documents with what a lapse of each one does: the licence and the iqama stop assignment, the medical certificate only warns. */
    private static function driverDocs(Driver $d): array
    {
        return array_map(fn (array $doc) => $doc + ['effect' => $doc['key'] === 'medicalExpiry' ? 'warns' : 'blocks'], U::docInfos($d, U::DRIVER_DOCS));
    }

    /**
     * The document that stops this driver's assignment right now — a licence with fewer than `fleet.driverLicenseMinDays`
     * days left (or expired), or an expired iqama / ID. Null when none does. The medical certificate never blocks.
     *
     * @return ?array{doc: string, days: int, ar: string, en: string}
     */
    private static function driverDocBlock(Driver $d, int $minDaysLic): ?array
    {
        $lic = U::daysTo($d->license_expiry);
        if ($lic !== null && $lic < $minDaysLic) {
            return ['doc' => 'licenseExpiry', 'days' => $lic,
                'ar' => $lic < 0 ? 'الرخصة منتهية' : "الرخصة تنتهي خلال {$lic} يومًا (الحد الأدنى للإسناد {$minDaysLic} يومًا)",
                'en' => $lic < 0 ? 'License expired' : "License expires in {$lic} days (assignment needs at least {$minDaysLic})"];
        }
        $iqama = U::daysTo($d->iqama_expiry);
        if ($iqama !== null && $iqama < 0) {
            return ['doc' => 'iqamaExpiry', 'days' => $iqama, 'ar' => 'الإقامة / الهوية منتهية', 'en' => 'Iqama / ID expired'];
        }

        return null;
    }

    public function getDriver(string $code): array
    {
        $d = Driver::with([
            'defaultVehicle', 'user',
            'incidentsList' => fn ($q) => $q->orderByDesc('date')->orderByDesc('id'),
            'opsRequests' => fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id')->limit(20),
        ])->where(fn ($w) => $w->where('id', $code)->orWhere('code', $code))->first()
            ?? throw AppError::notFound('DRIVER_NOT_FOUND', "السائق {$code} غير موجود", "Driver {$code} not found");

        $trips = Trip::with('vehicle')->withCount(['stops', 'pods'])->where('driver_id', $d->id)->orderByDesc('date')->orderByDesc('id')->limit(20)->get();
        $activeTrip = Trip::with('vehicle')->where('driver_id', $d->id)->whereIn('status', [...U::ACTIVE_TRIP_STATES, 'dassigned'])->first();
        $alerts = FleetAlert::where('entity_code', $d->code)->whereIn('status', self::ALERT_OPEN)->orderByDesc('created_at')->orderByDesc('id')->get();
        $pods = ProofOfDelivery::where('driver_id', $d->id)->selectRaw('result, count(*) as n')->groupBy('result')->pluck('n', 'result')->map(fn ($n) => (int) $n)->all();
        $docs = self::driverDocs($d);

        return U::shape($d->toArray(), ['defaultVehicle' => ['code', 'plateAr', 'typeAr'], 'user' => ['id', 'username', 'active', 'lastLoginAt']]) + [
            'docs' => $docs, 'docDays' => U::minDays($docs), 'docBlock' => self::driverDocBlock($d, (int) $this->settings->get('fleet.driverLicenseMinDays')),
            'performance' => [
                'trips' => $d->trips, 'deliveries' => $d->deliveries, 'fails' => $d->fails, 'okPct' => $d->ok_pct, 'ontimePct' => $d->ontime_pct, 'avgDelay' => $d->avg_delay, 'km' => $d->km,
                'rating' => $d->rating, 'safety' => $d->safety, 'fuelScore' => $d->fuel_score, 'complaints' => $d->complaints, 'incidents' => $d->incidents, 'pods' => U::map($pods),
            ],
            'tripsList' => $trips->map(fn (Trip $t) => [
                'number' => $t->number, 'date' => $t->date, 'status' => $t->status, 'routeAr' => $t->route_ar, 'km' => $t->km, 'delayMin' => $t->delay_min,
                'vehicle' => $t->vehicle ? ['code' => $t->vehicle->code] : null, '_count' => ['stops' => $t->stops_count, 'pods' => $t->pods_count],
            ])->all(),
            'activeTrip' => $activeTrip ? ['number' => $activeTrip->number, 'status' => $activeTrip->status, 'routeAr' => $activeTrip->route_ar, 'vehicle' => $activeTrip->vehicle ? ['code' => $activeTrip->vehicle->code] : null] : null,
            'alerts' => $alerts->all(), 'canAssign' => $this->trips->driverCanAssign($d), 'stateLabel' => U::DRIVER_STATE_LABEL[$d->state] ?? $d->state,
        ];
    }

    /** With `username` + `password` a login user with the `driver` role is created too (must change the password on first login). */
    public function createDriver(AuthUser $user, array $dto): array
    {
        $code = mb_strtoupper(trim($dto['code']));
        $minDaysLic = (int) $this->settings->get('fleet.driverLicenseMinDays');
        $lic = U::daysTo($dto['licenseExpiry']);
        if ($lic === null || $lic < $minDaysLic) {
            throw AppError::rule('DRIVER_LICENSE_SOON', "رخصة تنتهي خلال أقل من {$minDaysLic} يومًا — لا يمكن تفعيله", "License expires within {$minDaysLic} days — cannot activate");
        }
        if (Driver::where('code', $code)->exists()) {
            throw AppError::conflict('DRIVER_CODE_TAKEN', 'رقم السائق مستخدم', 'Driver code already used');
        }
        $veh = ! empty($dto['defaultVehicleCode']) ? $this->trips->vehicleByCode($dto['defaultVehicleCode']) : null;
        [$shift, $shiftEn] = U::SHIFT_LABEL[$dto['shift'] ?? 'am'] ?? U::SHIFT_LABEL['am'];
        $username = $dto['username'] ?? null;
        $password = $dto['password'] ?? null;
        if (($username && ! $password) || (! $username && $password)) {
            throw AppError::validation('DRIVER_USER', 'اسم المستخدم وكلمة المرور مطلوبان معًا لإنشاء حساب', 'Username and password are both required');
        }
        $nameEn = ($dto['nameEn'] ?? null) ?: $dto['nameAr'];

        $id = DB::transaction(function () use ($user, $dto, $code, $veh, $shift, $shiftEn, $username, $password, $nameEn) {
            $userId = null;
            if ($username && $password) {
                $username = mb_strtolower(trim($username));
                if (User::where('username', $username)->exists()) {
                    throw AppError::conflict('USERNAME_TAKEN', 'اسم المستخدم مستخدم', 'Username already exists');
                }
                $role = Role::where('key', 'driver')->first() ?? throw AppError::rule('ROLE_MISSING', 'دور السائق غير معرّف', 'Driver role missing');
                $login = User::create(['username' => $username, 'password_hash' => Hash::make($password), 'name_ar' => $dto['nameAr'], 'name_en' => $nameEn, 'initials' => mb_substr($dto['nameAr'], 0, 1), 'must_change_password' => true]);
                DB::table('user_roles')->insert(['user_id' => $login->id, 'role_id' => $role->id]);
                $userId = $login->id;
                $this->audit->log($user, ['action' => 'USER.CREATE', 'entityType' => 'User', 'entityId' => $login->id, 'entityNumber' => $username, 'newValue' => ['roles' => ['driver'], 'driver' => $code]]);
            }
            $d = Driver::create([
                'code' => $code, 'user_id' => $userId, 'name_ar' => $dto['nameAr'], 'name_en' => $nameEn, 'employee_no' => $dto['employeeNo'], 'mobile' => $dto['mobile'], 'nationality' => $dto['nationality'] ?? null,
                'license_no' => $dto['licenseNo'], 'license_type' => $dto['licenseType'] ?? null, 'license_expiry' => U::parseDate($dto['licenseExpiry']), 'iqama_expiry' => U::parseDate($dto['iqamaExpiry']),
                'medical_expiry' => U::parseDate($dto['medicalExpiry'] ?? null), 'join_date' => U::parseDate($dto['joinDate'] ?? null) ?? U::today(), 'shift' => $shift, 'shift_en' => $shiftEn,
                'default_vehicle_id' => $veh?->id, 'state' => 'available',
                'hourly_cost' => isset($dto['hourlyCost']) ? round((float) $dto['hourlyCost'], 2) : null, // driver time of the trip cost sheet; null = fleet.driverHourlyCost
            ]);
            $this->audit->log($user, ['action' => 'DRIVER.CREATE', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityNumber' => $code,
                'newValue' => ['name' => $dto['nameAr'], 'employeeNo' => $dto['employeeNo'], 'licenseExpiry' => $dto['licenseExpiry'], 'user' => $username]]);
            $this->audit->status($user, 'Driver', $d->id, $code, null, 'available');
            $this->refreshDriverAlerts($d->refresh(), $user, $this->alertPolicy()); // an iqama that is already expired stops assignment from the first minute
            $this->notify->activity($user, 'Driver', $d->id, $code, "أُضيف السائق {$dto['nameAr']}".($d->blocked ? ' — موقوف عن الإسناد حتى تجديد الوثائق' : ' — متاح للإسناد'), "Driver {$nameEn} added");

            return $d->id;
        });

        return $this->getDriver($id);
    }

    public function updateDriver(AuthUser $user, string $code, array $dto): array
    {
        $d = $this->trips->driverByCode($code);
        $data = [];
        $changes = [];
        $set = function (string $field, string $column, mixed $new, mixed $old) use (&$data, &$changes) {
            if ($new !== $old) {
                $data[$column] = $new;
                $changes[$field] = [$old, $new];
            }
        };
        $types = ['nameAr' => 'string', 'nameEn' => 'string', 'employeeNo' => 'string', 'mobile' => 'string', 'nationality' => 'string', 'licenseNo' => 'string', 'licenseType' => 'string', 'blocked' => 'bool', 'active' => 'bool'];
        foreach ($types as $field => $type) {
            if (array_key_exists($field, $dto)) {
                $column = Str::snake($field);
                $set($field, $column, self::cast($dto[$field], $type), $d->getAttribute($column));
            }
        }
        foreach (['licenseExpiry' => 'license_expiry', 'iqamaExpiry' => 'iqama_expiry', 'medicalExpiry' => 'medical_expiry', 'joinDate' => 'join_date'] as $field => $column) {
            if (array_key_exists($field, $dto)) {
                $new = U::parseDate($dto[$field]);
                $old = $d->getAttribute($column);
                if ($new?->getTimestamp() !== $old?->getTimestamp()) {
                    $data[$column] = $new;
                    $changes[$field] = [$old, $new];
                }
            }
        }
        if (! empty($dto['shift'])) {
            [$shift, $shiftEn] = U::SHIFT_LABEL[$dto['shift']] ?? [$dto['shift'], $dto['shift']];
            $set('shift', 'shift', $shift, $d->shift);
            $data['shift_en'] = $shiftEn;
        }
        if (array_key_exists('defaultVehicleCode', $dto)) {
            $v = $dto['defaultVehicleCode'] ? $this->trips->vehicleByCode($dto['defaultVehicleCode']) : null;
            if ($v?->id !== $d->default_vehicle_id) {
                $data['default_vehicle_id'] = $v?->id;
                $changes['defaultVehicle'] = [$d->default_vehicle_id ? Vehicle::where('id', $d->default_vehicle_id)->value('code') : null, $v?->code];
            }
        }
        if (array_key_exists('hourlyCost', $dto)) { // decimal column: compare as numbers
            $new = $dto['hourlyCost'] === null ? null : round((float) $dto['hourlyCost'], 2);
            $set('hourlyCost', 'hourly_cost', $new, $d->hourly_cost === null ? null : (float) $d->hourly_cost);
        }
        // the block follows the documents: licence under the minimum days or an expired iqama stop assignment. Judge the
        // dates as they will be after this change.
        $minDaysLic = (int) $this->settings->get('fleet.driverLicenseMinDays');
        $after = (clone $d)->forceFill(array_intersect_key($data, array_flip(['license_expiry', 'iqama_expiry'])));
        $docBlock = self::driverDocBlock($after, $minDaysLic);
        if (array_key_exists('blocked', $dto)) {
            if ($d->blocked && ! filter_var($dto['blocked'], FILTER_VALIDATE_BOOLEAN) && $docBlock) {
                throw AppError::rule('DRIVER_DOCS_BLOCK', "لا يُرفع الإيقاف عن {$d->name_ar}: {$docBlock['ar']} — جدّد الوثيقة أولًا", "Cannot unblock {$d->code}: {$docBlock['en']} — renew the document first", ['doc' => $docBlock['doc']]);
            }
        } elseif ($d->blocked && ! $docBlock && array_intersect(['licenseExpiry', 'iqamaExpiry'], array_keys($changes))) {
            $data['blocked'] = false; // renewed: the document block is lifted with it
            $changes['blocked'] = [true, false];
        }
        if (! $changes) {
            return $this->getDriver($d->id);
        }
        DB::transaction(function () use ($user, $d, $data, $changes) {
            $d->update($data);
            foreach ($changes as $field => [$old, $new]) {
                $this->audit->log($user, ['action' => 'DRIVER.UPDATE', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityNumber' => $d->code, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
            }
            $renewed = array_values(array_intersect(self::DRIVER_DOC_FIELDS, array_keys($changes)));
            if ($renewed) {
                $labels = array_column(array_filter(U::docInfos($d, U::DRIVER_DOCS), fn ($doc) => in_array($doc['key'], $renewed, true)), 'labelAr');
                $this->notify->activity($user, 'Driver', $d->id, $d->code, "حُدّثت وثائق السائق {$d->name_ar}: ".implode(' · ', $labels).(($changes['blocked'][1] ?? null) === false ? ' — رُفع إيقاف الإسناد' : ''), "Documents of driver {$d->code} updated: ".implode(', ', $renewed));
                if ($d->active) {
                    $this->refreshDriverAlerts($d, $user, $this->alertPolicy()); // alert + block state now, not at the next sweep
                }
            }
        });

        return $this->getDriver($d->id);
    }

    public function setDriverState(AuthUser $user, string $code, string $to, ?string $reason = null): array
    {
        $d = $this->trips->driverByCode($code);
        if ($d->state === $to) {
            return $this->getDriver($d->id);
        }
        if ($d->state === 'onroute' || $this->trips->isDriverBusy($d->id)) {
            throw AppError::rule('DRIVER_ON_TRIP', 'السائق برحلة نشطة — لا يمكن تغيير حالته الآن', 'Driver is on an active trip');
        }
        DB::transaction(function () use ($user, $d, $to, $reason) {
            $from = $d->state;
            $d->update(['state' => $to, 'active' => $to !== 'inactive']);
            $this->audit->status($user, 'Driver', $d->id, $d->code, $from, $to, $reason);
            $this->notify->activity($user, 'Driver', $d->id, $d->code, "حالة السائق {$d->name_ar}: ".U::DRIVER_STATE_LABEL[$to].($reason ? ' — '.$reason : ''), "Driver {$d->code}: {$to}");
        });

        return $this->getDriver($d->id);
    }

    /**
     * Incident form: safety −8 / −4 / −2 by severity; complaints++ for type complaint.
     *
     * @param  array{type:string, date?:?string, desc:string, severity:string}  $dto
     */
    public function addIncident(AuthUser $user, string $code, array $dto): array
    {
        $d = $this->trips->driverByCode($code);
        $penalty = $dto['severity'] === 'high' ? 8 : ($dto['severity'] === 'med' ? 4 : 2);
        $before = $d->safety;
        $safety = max(0, $before - $penalty);

        return DB::transaction(function () use ($user, $d, $dto, $before, $safety) {
            $incident = Incident::create(['driver_id' => $d->id, 'type' => $dto['type'], 'date' => U::parseDate($dto['date'] ?? null) ?? U::today(), 'desc' => $dto['desc'], 'severity' => $dto['severity']]);
            $d->update(['incidents' => $d->incidents + 1, 'safety' => $safety, 'complaints' => $d->complaints + ($dto['type'] === 'complaint' ? 1 : 0)]);
            $this->audit->log($user, ['action' => 'DRIVER.INCIDENT', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityNumber' => $d->code, 'field' => 'safety', 'oldValue' => $before, 'newValue' => $safety, 'transactionId' => $incident->id]);
            $this->notify->activity($user, 'Driver', $d->id, $d->code, "سُجلت الحادثة — خُفض Safety Score لـ {$d->name_ar} (".U::num($before).' → '.U::num($safety).')', "Incident logged for {$d->code}");

            return ['incident' => $incident->refresh(), 'safety' => $safety, 'message' => "سُجلت الحادثة — خُفض Safety Score لـ {$d->name_ar}"];
        });
    }

    // ═══════════ maintenance ═══════════
    /** @param  array{vehicle?:?string, status?:?string, kind?:?string}  $f */
    public function listMaintenance(Paging $page, array $f): array
    {
        $query = MaintenanceOrder::query()->with('vehicle');
        if (! empty($f['vehicle'])) {
            $query->whereHas('vehicle', fn ($w) => $w->where('code', $f['vehicle']));
        }
        if (! empty($f['status'])) {
            $query->where('status', $f['status']);
        }
        if (! empty($f['kind'])) {
            $query->where('kind', $f['kind']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('desc_ar', 'like', $like)->orWhere('shop', 'like', $like)->orWhereHas('vehicle', fn ($v) => $v->where('code', 'like', $like)));
        }
        $query->orderByRaw(self::MAINT_ORDER)->orderByDesc('start_date')->orderByDesc('id');

        return $page->paginate($query, fn (MaintenanceOrder $m) => self::maintRow($m));
    }

    private static function maintRow(MaintenanceOrder $m): array
    {
        return U::shape($m->loadMissing('vehicle')->toArray(), ['vehicle' => ['code', 'plateAr', 'state']]);
    }

    private function lockMaintenance(string $number): MaintenanceOrder
    {
        return MaintenanceOrder::where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->lockForUpdate()->first()
            ?? throw AppError::notFound('MAINT_NOT_FOUND', "أمر الصيانة {$number} غير موجود", 'Maintenance order not found');
    }

    /**
     * The one path a maintenance order is opened through (the form, a processed driver request): number, labels, the
     * optional move of the vehicle to «maintenance» and the audit row. Must run inside the caller's transaction.
     *
     * @param  array{kind:string, descAr:string, descEn?:?string, shop?:?string, startDate?:mixed, odometer?:?int, cost?:float|int|string|null,
     *               downDays?:int|string|null, nextKm?:?int, block?:bool, from?:?string}  $in
     */
    private function openMaintenance(AuthUser $user, Vehicle $v, array $in): MaintenanceOrder
    {
        $kind = $in['kind'];
        [$typeAr, $typeEn] = U::MAINT_KIND_LABEL[$kind] ?? [$kind, $kind];
        $downDays = (int) ($in['downDays'] ?? 1);
        $cost = (float) ($in['cost'] ?? 0);
        $block = (bool) ($in['block'] ?? false);
        $number = $this->numbering->next('MNT');
        $mo = MaintenanceOrder::create([
            'number' => $number, 'vehicle_id' => $v->id, 'kind' => $kind, 'type_ar' => $typeAr, 'type_en' => $typeEn, 'desc_ar' => $in['descAr'], 'desc_en' => ($in['descEn'] ?? null) ?: $in['descAr'],
            'shop' => ($in['shop'] ?? null) ?: null, 'start_date' => $in['startDate'] ?? U::today(), 'odometer' => $in['odometer'] ?? null, 'cost' => $cost, 'down_days' => $downDays, 'down_label' => "{$downDays} يوم",
            'next_km' => $in['nextKm'] ?? null, 'status' => 'open',
        ]);
        if ($block) {
            $this->trips->transitionVehicle($user, $v, 'maintenance', "أمر صيانة {$number}");
        }
        $this->audit->log($user, ['action' => 'MAINT.CREATE', 'entityType' => 'MaintenanceOrder', 'entityId' => $mo->id, 'entityNumber' => $number,
            'newValue' => ['vehicle' => $v->code, 'kind' => $kind, 'cost' => $cost, 'block' => $block] + (! empty($in['from']) ? ['from' => $in['from']] : [])]);

        return $mo->refresh();
    }

    public function createMaintenance(AuthUser $user, array $dto): array
    {
        $v = $this->trips->vehicleByCode($dto['vehicleCode']);
        $block = (bool) ($dto['block'] ?? false);

        return DB::transaction(function () use ($user, $dto, $v, $block) {
            $stateBefore = $v->state;
            $mo = $this->openMaintenance($user, $v, [
                'kind' => $dto['kind'] ?? 'corrective', 'descAr' => $dto['desc'], 'shop' => $dto['shop'], 'startDate' => U::parseDate($dto['startDate']),
                'odometer' => isset($dto['odometer']) ? (int) $dto['odometer'] : null, 'cost' => $dto['cost'] ?? 0, 'downDays' => $dto['downDays'] ?? 1,
                'nextKm' => isset($dto['nextKm']) ? (int) $dto['nextKm'] : null, 'block' => $block,
            ]);
            $message = "أُنشئ {$mo->number} لـ {$v->code}".($block ? ' — المركبة الآن «صيانة» وغير قابلة للإسناد' : '');
            $this->notify->activity($user, 'MaintenanceOrder', $mo->id, $mo->number, $message, "Maintenance {$mo->number} for {$v->code}");

            return $mo->toArray() + ['vehicleState' => $block ? 'maintenance' : $stateBefore, 'message' => $message];
        });
    }

    /**
     * Edit an OPEN maintenance order: kind, description, workshop, start date, odometer and the plan figures (estimated
     * cost, expected downtime, next service km). A closed order has posted its cost and a cancelled one is final.
     *
     * @param  array{kind?:string, desc?:string, shop?:string, startDate?:string, odometer?:int|string|null, cost?:float|int|string|null, downDays?:int|string|null, nextKm?:int|string|null}  $dto
     */
    public function updateMaintenance(AuthUser $user, string $number, array $dto): array
    {
        return DB::transaction(function () use ($user, $number, $dto) {
            $mo = $this->lockMaintenance($number);
            if ($mo->status !== 'open') {
                throw AppError::rule('MAINT_NOT_OPEN', "أمر الصيانة {$mo->number} ".($mo->status === 'cancelled' ? 'ملغى' : 'مقفل').' — التعديل متاح للأوامر المفتوحة فقط', "Maintenance order {$mo->number} is {$mo->status} — only an open order can be edited");
            }
            $data = [];
            $changes = [];
            if (! empty($dto['kind']) && $dto['kind'] !== $mo->kind) {
                [$typeAr, $typeEn] = U::MAINT_KIND_LABEL[$dto['kind']] ?? [$dto['kind'], $dto['kind']];
                $data += ['kind' => $dto['kind'], 'type_ar' => $typeAr, 'type_en' => $typeEn];
                $changes['kind'] = [$mo->kind, $dto['kind']];
            }
            if (isset($dto['desc']) && trim($dto['desc']) !== '' && trim($dto['desc']) !== $mo->desc_ar) {
                $data += ['desc_ar' => trim($dto['desc']), 'desc_en' => trim($dto['desc'])];
                $changes['desc'] = [$mo->desc_ar, trim($dto['desc'])];
            }
            if (isset($dto['shop']) && trim($dto['shop']) !== '' && trim($dto['shop']) !== $mo->shop) {
                $data['shop'] = trim($dto['shop']);
                $changes['shop'] = [$mo->shop, $data['shop']];
            }
            if (isset($dto['startDate'])) {
                $start = U::parseDate($dto['startDate']) ?? throw AppError::validation('BAD_DATE', 'تاريخ غير صالح', 'Invalid date');
                if ($start->getTimestamp() !== $mo->start_date?->getTimestamp()) {
                    $data['start_date'] = $start;
                    $changes['startDate'] = [U::fmtDate($mo->start_date), U::fmtDate($start)];
                }
            }
            foreach (['odometer' => 'odometer', 'nextKm' => 'next_km'] as $field => $column) {
                if (array_key_exists($field, $dto)) {
                    $new = $dto[$field] === null ? null : (int) $dto[$field];
                    if ($new !== $mo->getAttribute($column)) {
                        $data[$column] = $new;
                        $changes[$field] = [$mo->getAttribute($column), $new];
                    }
                }
            }
            if (isset($dto['cost']) && (float) $dto['cost'] !== (float) $mo->cost) {
                $data['cost'] = (float) $dto['cost'];
                $changes['cost'] = [$mo->cost, $data['cost']];
            }
            if (isset($dto['downDays']) && (int) $dto['downDays'] !== $mo->down_days) {
                $data += ['down_days' => (int) $dto['downDays'], 'down_label' => ((int) $dto['downDays']).' يوم'];
                $changes['downDays'] = [$mo->down_days, (int) $dto['downDays']];
            }
            if (! $changes) {
                return self::maintRow($mo) + ['message' => 'لا تغيير — الأمر كما هو', 'messageEn' => 'Nothing changed'];
            }
            $mo->update($data);
            foreach ($changes as $field => [$old, $new]) {
                $this->audit->log($user, ['action' => 'MAINT.UPDATE', 'entityType' => 'MaintenanceOrder', 'entityId' => $mo->id, 'entityNumber' => $mo->number, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
            }
            $message = "عُدّل أمر الصيانة {$mo->number}";
            $this->notify->activity($user, 'MaintenanceOrder', $mo->id, $mo->number, $message.' ('.implode('، ', array_keys($changes)).')', "Maintenance {$mo->number} edited (".implode(', ', array_keys($changes)).')');

            return self::maintRow($mo->refresh()) + ['message' => $message, 'messageEn' => "Maintenance order {$mo->number} updated"];
        });
    }

    /**
     * Cancel an OPEN maintenance order (opened by mistake, or the work will not be done): status → cancelled, nothing is
     * posted (cost is posted at close only), and the vehicle leaves «maintenance» through the state machine when no
     * other order of it is open. The maintenance-due alert is NOT cleared: the service was not done.
     */
    public function cancelMaintenance(AuthUser $user, string $number, ?string $reasonIn): array
    {
        $reason = self::reason($reasonIn, 'سبب الإلغاء', 'cancellation');

        return DB::transaction(function () use ($user, $number, $reason) {
            $mo = $this->lockMaintenance($number);
            if ($mo->status !== 'open') {
                throw AppError::rule('MAINT_NOT_OPEN', "أمر الصيانة {$mo->number} ".($mo->status === 'cancelled' ? 'ملغى مسبقًا' : 'مقفل — تكلفته مرحَّلة').' — الإلغاء متاح للأوامر المفتوحة فقط', "Maintenance order {$mo->number} is {$mo->status} — only an open order can be cancelled");
            }
            $vehicle = Vehicle::whereKey($mo->vehicle_id)->lockForUpdate()->firstOrFail();
            $mo->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->username, 'cancel_reason' => $reason]);
            $released = false;
            if ($vehicle->state === 'maintenance' && ! MaintenanceOrder::where('vehicle_id', $vehicle->id)->where('status', 'open')->exists()) {
                $this->trips->transitionVehicle($user, $vehicle, 'available', "إلغاء {$mo->number}");
                $released = true;
            }
            $this->audit->status($user, 'MaintenanceOrder', $mo->id, $mo->number, 'open', 'cancelled', $reason);
            $this->audit->log($user, ['action' => 'MAINT.CANCEL', 'entityType' => 'MaintenanceOrder', 'entityId' => $mo->id, 'entityNumber' => $mo->number, 'newValue' => ['reason' => $reason, 'vehicle' => $vehicle->code, 'vehicleReleased' => $released]]);
            $message = "أُلغي أمر الصيانة {$mo->number} — {$reason}".($released ? " · المركبة {$vehicle->code} عادت «متاحة»" : '');
            $this->notify->activity($user, 'MaintenanceOrder', $mo->id, $mo->number, $message, "Maintenance {$mo->number} cancelled — {$reason}", ['disp']);

            return self::maintRow($mo->refresh()) + ['vehicleReleased' => $released, 'message' => $message, 'messageEn' => "Maintenance order {$mo->number} cancelled".($released ? " · {$vehicle->code} is available again" : '')];
        });
    }

    public function closeMaintenance(AuthUser $user, string $number, array $dto): array
    {
        $mo = MaintenanceOrder::with('vehicle')->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('MAINT_NOT_FOUND', "أمر الصيانة {$number} غير موجود", 'Maintenance order not found');
        if ($mo->status === 'closed') {
            throw AppError::rule('MAINT_CLOSED', 'أمر الصيانة مقفل مسبقًا', 'Already closed');
        }
        if ($mo->status === 'cancelled') {
            throw AppError::rule('MAINT_CANCELLED', 'أمر الصيانة ملغى — لا يُقفل؛ أنشئ أمرًا جديدًا إن نُفذت الصيانة', 'A cancelled maintenance order cannot be closed');
        }
        $end = U::parseDate($dto['endDate']) ?? throw AppError::validation('BAD_DATE', 'تاريخ غير صالح', 'Invalid date');
        $parts = (float) ($dto['parts'] ?? 0);
        $labor = (float) ($dto['labor'] ?? 0);
        $cost = isset($dto['cost']) ? (float) $dto['cost'] : (($parts + $labor) ?: $mo->cost);
        $note = $dto['note'] ?? null;

        return DB::transaction(function () use ($user, $mo, $dto, $end, $parts, $labor, $cost, $note) {
            $vehicle = $mo->vehicle;
            $mo->update([
                'status' => 'closed', 'end_date' => $end, 'cost' => $cost, 'parts' => $parts, 'labor' => $labor, 'next_km' => isset($dto['nextKm']) ? (int) $dto['nextKm'] : $mo->next_km,
                'down_days' => $mo->start_date ? max(0, U::round(($end->getTimestamp() - $mo->start_date->getTimestamp()) / 86400)) : $mo->down_days,
            ]);
            $vData = ['last_maint_date' => $end];
            if ($mo->next_km !== null) {
                $vData['next_maint_km'] = $mo->next_km;
            }
            if ($mo->odometer !== null && $mo->odometer > $vehicle->odometer) {
                $vData['odometer'] = $mo->odometer;
            }
            $vehicle->update($vData);
            app(FleetPosting::class)->maintenance($user, $mo->refresh()); // finance: maintenance expense at close (final cost)
            $stillOpen = MaintenanceOrder::where('vehicle_id', $mo->vehicle_id)->where('status', 'open')->count();
            $released = false;
            if ($vehicle->state === 'maintenance' && ! $stillOpen) {
                $this->trips->transitionVehicle($user, $vehicle, 'available', "إقفال {$mo->number}");
                $released = true;
            }
            $this->audit->status($user, 'MaintenanceOrder', $mo->id, $mo->number, 'open', 'closed', $note);
            $this->audit->log($user, ['action' => 'MAINT.CLOSE', 'entityType' => 'MaintenanceOrder', 'entityId' => $mo->id, 'entityNumber' => $mo->number, 'newValue' => ['cost' => $cost, 'parts' => $parts, 'labor' => $labor, 'endDate' => $dto['endDate']]]);
            $this->upsertAlert(null, $user, false, ['entityCode' => $vehicle->code, 'category' => 'maint']); // resolve the open maintenance alert
            $this->notify->activity($user, 'MaintenanceOrder', $mo->id, $mo->number, "أُقفل {$mo->number} — التكلفة ".U::fmt0($cost).' ر.س'.($released ? ' · المركبة عادت «متاحة»' : ''), "Maintenance {$mo->number} closed");

            return $mo->refresh()->toArray() + ['vehicleReleased' => $released];
        });
    }

    // ═══════════ fuel ═══════════
    /** @param  array{vehicle?:?string, driver?:?string, anomaly?:?string, status?:?string, trip?:?string}  $f */
    public function listFuel(Paging $page, array $f): array
    {
        $query = FuelRecord::query()->with(['vehicle', 'driver']);
        if (! empty($f['vehicle'])) {
            $query->whereHas('vehicle', fn ($w) => $w->where('code', $f['vehicle']));
        }
        if (! empty($f['driver'])) {
            $query->whereHas('driver', fn ($w) => $w->where('code', $f['driver']));
        }
        if (($f['anomaly'] ?? null) === 'true') {
            $query->where('anomaly', true)->whereNull('voided_at'); // a voided fill is not an anomaly any more
        }
        if (($f['status'] ?? null) === 'voided') {
            $query->whereNotNull('voided_at');
        } elseif (($f['status'] ?? null) === 'active') {
            $query->whereNull('voided_at');
        }
        if (! empty($f['trip'])) {
            $query->where('trip_id', Trip::where('number', $f['trip'])->value('id') ?: '—');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('station', 'like', $like)->orWhereHas('vehicle', fn ($v) => $v->where('code', 'like', $like)));
        }
        // the totals of the filter never count a voided fill (taken before ordering: an aggregate carries no ORDER BY)
        $live = (clone $query)->whereNull('voided_at');
        $totals = [
            'records' => (clone $live)->count(), 'liters' => round((float) (clone $live)->sum('liters'), 2), 'cost' => round((float) (clone $live)->sum('cost'), 2),
            'voided' => (clone $query)->whereNotNull('voided_at')->count(),
        ];
        $query->orderByDesc('date')->orderByDesc('id');
        $out = $page->paginate($query, fn (FuelRecord $r) => U::shape($r->toArray(), ['vehicle' => ['code', 'plateAr', 'avgKmL'], 'driver' => ['code', 'nameAr']]));
        $trips = Trip::whereIn('id', array_values(array_filter(array_column($out['items'], 'tripId'))))->pluck('number', 'id');
        foreach ($out['items'] as $i => $row) {
            $out['items'][$i]['tripNumber'] = ! empty($row['tripId']) ? ($trips[$row['tripId']] ?? null) : null;
        }

        return $out + ['totals' => $totals];
    }

    private function fuelRow(FuelRecord $rec): array
    {
        $rec->refresh()->load(['vehicle', 'driver']);

        return U::shape($rec->toArray(), ['vehicle' => ['code', 'plateAr', 'avgKmL'], 'driver' => ['code', 'nameAr']])
            + ['tripNumber' => $rec->trip_id ? Trip::where('id', $rec->trip_id)->value('number') : null];
    }

    private function findFuel(string $number): FuelRecord
    {
        return FuelRecord::where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('FUEL_NOT_FOUND', "سجل الوقود {$number} غير موجود", "Fuel record {$number} not found");
    }

    /** The trip a fuel record is charged to: it must exist and run on the same vehicle. Null when no trip is given. */
    private function fuelTrip(?string $number, Vehicle $v): ?Trip
    {
        $number = trim((string) $number);
        if ($number === '') {
            return null;
        }
        $trip = Trip::where('number', $number)->orWhere('id', $number)->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$number} غير موجودة", "Trip {$number} not found");
        if ($trip->vehicle_id !== $v->id) {
            throw AppError::rule('FUEL_TRIP_VEHICLE', "الرحلة {$trip->number} ليست على المركبة {$v->code} — لا تُحمَّل تعبئتها عليها", "Trip {$trip->number} does not run on vehicle {$v->code}");
        }

        return $trip;
    }

    private function assertFuelPrice(float $liters, float $cost): void
    {
        $maxPrice = (float) $this->settings->get('fleet.fuelMaxPricePerLiter');
        if ($liters > 0 && $cost / $liters > $maxPrice) {
            throw AppError::rule('FUEL_PRICE', 'سعر اللتر غير منطقي (> '.U::num($maxPrice).' ر.س) — راجع المبلغ', 'Price per liter above '.U::num($maxPrice).' SAR');
        }
    }

    /** A reason a person typed for a correction / void / cancellation. */
    private static function reason(mixed $text, string $labelAr, string $labelEn): string
    {
        $reason = trim((string) ($text ?? ''));
        if (mb_strlen($reason) < self::REASON_MIN) {
            throw AppError::validation('REASON_REQUIRED', "{$labelAr} مطلوب (".self::REASON_MIN.' أحرف على الأقل)', "A {$labelEn} reason is required (at least ".self::REASON_MIN.' characters)');
        }

        return $reason;
    }

    /**
     * The non-voided fills just before and just after $rec in its vehicle's chain. The chain is ordered by odometer
     * reading, then by entry time — the order the readings were taken in. $rec itself may be voided (its old place).
     *
     * @return array{0: ?FuelRecord, 1: ?FuelRecord}
     */
    private function fuelNeighbours(FuelRecord $rec): array
    {
        $key = fn (FuelRecord $r) => [$r->odometer, $r->created_at?->getPreciseTimestamp(3) ?? 0, $r->id];
        $mine = $key($rec);
        $prev = null;
        $next = null;
        $chain = FuelRecord::where('vehicle_id', $rec->vehicle_id)->whereNull('voided_at')->where('id', '!=', $rec->id)->orderBy('odometer')->orderBy('created_at')->orderBy('id')->get();
        foreach ($chain as $other) {
            if (($key($other) <=> $mine) < 0) {
                $prev = $other;
            } else {
                $next = $other;
                break;
            }
        }

        return [$prev, $next];
    }

    /** Distance a stored record measured, recovered from its own figures (records written before `prev_odometer` existed). */
    private static function impliedKm(FuelRecord $r): int
    {
        if ($r->km_per_l > 0 && $r->liters > 0) {
            return U::round($r->km_per_l * $r->liters);
        }

        return $r->cost_per_km > 0 && $r->cost > 0 ? U::round($r->cost / $r->cost_per_km) : 0;
    }

    /** The reading a record's distance starts from: the fill before it, else the reading the vehicle had before the record. */
    private static function fuelBase(FuelRecord $r, ?FuelRecord $prev): int
    {
        return $prev ? $prev->odometer : ($r->prev_odometer ?? $r->odometer - self::impliedKm($r));
    }

    /** km/L, cost/km and the anomaly flag of a record against the fill before it; saves the record when anything changed. */
    private function refigureFuel(FuelRecord $rec, ?FuelRecord $prev, Vehicle $v): void
    {
        $km = $rec->odometer - self::fuelBase($rec, $prev);
        $kmPerL = $km > 0 && $rec->liters > 0 ? U::round(($km / $rec->liters) * 10) / 10 : null;
        $costPerKm = $km > 0 ? U::round(($rec->cost / $km) * 100) / 100 : null;
        $ratio = (float) $this->settings->get('fleet.fuelAnomalyRatio');
        $rec->fill(['km_per_l' => $kmPerL, 'cost_per_km' => $costPerKm, 'anomaly' => $kmPerL !== null && $v->avg_km_l > 0 && $kmPerL < $v->avg_km_l * $ratio]);
        if ($rec->isDirty()) {
            $rec->save();
        }
    }

    /** Moves the vehicle's reading because of a fuel record (entry, correction, void) and audits it. */
    private function setOdometerFromFuel(AuthUser $user, Vehicle $v, int $to, FuelRecord $rec): void
    {
        if ($to === $v->odometer) {
            return;
        }
        $old = $v->odometer;
        $v->update(['odometer' => $to]);
        $this->audit->log($user, ['action' => 'VEHICLE.ODOMETER', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityNumber' => $v->code, 'field' => 'odometer', 'oldValue' => $old, 'newValue' => $to, 'transactionId' => $rec->id]);
    }

    private function fuelAnomalyAlert(AuthUser $user, Vehicle $v, FuelRecord $rec): ?FleetAlert
    {
        $driver = $rec->driver_id ? Driver::find($rec->driver_id) : null;

        return $this->upsertAlert([
            'category' => 'fuel', 'severity' => 'w', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityCode' => $v->code, 'dueDate' => U::fmtDate(U::today()), 'owner' => 'مدير الأسطول',
            'textAr' => "انحراف استهلاك الوقود {$v->code}: ".U::num($rec->km_per_l).' كم/ل مقابل متوسط '.U::num($v->avg_km_l)." ({$rec->number}".($driver ? ' · '.$driver->name_ar : '').')',
            'textEn' => "Fuel anomaly {$v->code}: ".U::num($rec->km_per_l).' km/L vs avg '.U::num($v->avg_km_l), 'recommendAr' => 'مراجعة الإيصال وأسلوب القيادة', 'recommendEn' => 'Review receipt and driving',
        ], $user, true);
    }

    /**
     * After a correction / void: raise the alert for a fill that became anomalous, and clear the vehicle's open fuel
     * alert when none of its live fills is anomalous any more.
     *
     * @param  FuelRecord[]  $newlyAnomalous
     */
    private function syncFuelAlert(AuthUser $user, Vehicle $v, array $newlyAnomalous): ?FleetAlert
    {
        $alert = null;
        foreach ($newlyAnomalous as $rec) {
            $alert = $this->fuelAnomalyAlert($user, $v, $rec);
        }
        if (! FuelRecord::where('vehicle_id', $v->id)->whereNull('voided_at')->where('anomaly', true)->exists()) {
            $this->upsertAlert(null, $user, false, ['entityCode' => $v->code, 'category' => 'fuel']);
        }

        return $alert;
    }

    /**
     * The one path every fuel record takes (the form, a processed driver request): reading and price rules, the record,
     * km/L against the fill before it, the vehicle odometer, the fuel expense in the ledger (FleetPosting::fuel, which
     * also rebuilds the trip's cost sheet), audit and the anomaly alert. Must run inside the caller's transaction.
     *
     * @param  array{date?:mixed, odometer?:?int, liters:float, cost:float, station?:?string, full?:bool, receipt?:?string, from?:?string}  $in
     * @return array{0: FuelRecord, 1: ?FleetAlert}
     */
    private function recordFuel(AuthUser $user, Vehicle $vehicle, ?Driver $d, array $in, ?Trip $trip): array
    {
        $v = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail(); // one fill at a time per vehicle
        $odometer = isset($in['odometer']) ? (int) $in['odometer'] : $v->odometer; // no reading taken (driver request): the vehicle's reading stands
        $liters = (float) $in['liters'];
        $cost = (float) $in['cost'];
        if ($odometer < $v->odometer) {
            throw AppError::rule('FUEL_ODOMETER', 'قراءة العداد أقل من آخر قراءة مسجلة ('.U::fmt0($v->odometer).')', "Odometer below last reading ({$v->odometer})");
        }
        $this->assertFuelPrice($liters, $cost);
        $number = $this->numbering->next('FL');
        $rec = FuelRecord::create([
            'number' => $number, 'vehicle_id' => $v->id, 'driver_id' => $d?->id, 'trip_id' => $trip?->id, 'date' => $in['date'] ?? U::today(), 'odometer' => $odometer, 'prev_odometer' => $v->odometer,
            'liters' => $liters, 'cost' => $cost, 'station' => ($in['station'] ?? null) ?: null, 'full' => (bool) ($in['full'] ?? true), 'anomaly' => false, 'receipt' => ($in['receipt'] ?? null) ?: null,
        ])->refresh();
        [$prev, $next] = $this->fuelNeighbours($rec);
        $this->refigureFuel($rec, $prev, $v);
        if ($next) { // only after the vehicle's reading was lowered by hand: the new fill lands before an older, higher one
            $this->refigureFuel($next, $rec, $v);
        }
        $this->setOdometerFromFuel($user, $v, $odometer, $rec);
        app(FleetPosting::class)->fuel($user, $rec); // finance: fuel expense on the vehicle's cost center (+ the trip's cost sheet)
        $this->audit->log($user, ['action' => 'FUEL.CREATE', 'entityType' => 'FuelRecord', 'entityId' => $rec->id, 'entityNumber' => $number,
            'newValue' => ['vehicle' => $v->code, 'liters' => $liters, 'cost' => $cost, 'odometer' => $odometer, 'kmPerL' => $rec->km_per_l, 'anomaly' => $rec->anomaly, 'trip' => $trip?->number] + (! empty($in['from']) ? ['from' => $in['from']] : [])]);

        return [$rec, $rec->anomaly ? $this->fuelAnomalyAlert($user, $v, $rec) : null];
    }

    /** Odometer ≥ last, price/L ≤ fleet.fuelMaxPricePerLiter, km/L below avg × fleet.fuelAnomalyRatio → alert; vehicle odometer updated; fuel expense posted. */
    public function createFuel(AuthUser $user, array $dto): array
    {
        $v = $this->trips->vehicleByCode($dto['vehicleCode']);
        $d = ! empty($dto['driverCode']) ? $this->trips->driverByCode($dto['driverCode']) : null;
        $trip = $this->fuelTrip($dto['tripNumber'] ?? null, $v);

        return DB::transaction(function () use ($user, $dto, $v, $d, $trip) {
            [$rec, $alert] = $this->recordFuel($user, $v, $d, [
                'date' => U::parseDate($dto['date']) ?? U::today(), 'odometer' => (int) $dto['odometer'], 'liters' => (float) $dto['liters'], 'cost' => (float) $dto['cost'],
                'station' => $dto['station'] ?? null, 'full' => (bool) ($dto['full'] ?? true), 'receipt' => $dto['receipt'] ?? null,
            ], $trip);
            $message = 'سُجلت التعبئة — '.U::num($rec->km_per_l).' كم/ل'.($rec->anomaly ? ' ⚠ انحراف عن المتوسط — أُنشئ تنبيه' : '');
            $this->notify->activity($user, 'FuelRecord', $rec->id, $rec->number, $message, "Fuel {$rec->number} recorded");

            return $this->fuelRow($rec) + ['alert' => $alert, 'message' => $message];
        });
    }

    /**
     * Correct a posted fuel record — nothing is deleted and a reason is mandatory. km/L, cost/km and the anomaly flag
     * are recomputed for this record and for the next fill of the vehicle; when the amount, the day or the trip changes
     * the journal is reversed and posted again; the vehicle odometer follows when this record carried its reading; the
     * cost sheets of the old and the new trip are rebuilt.
     * Rule: the reading may not go below the fill before it nor above the fill after it.
     *
     * @param  array{liters?:float|int|string, cost?:float|int|string, odometer?:int|string, station?:?string, date?:string, tripNumber?:?string, reason?:?string}  $dto
     */
    public function updateFuel(AuthUser $user, string $number, array $dto): array
    {
        $reason = self::reason($dto['reason'] ?? null, 'سبب التصحيح', 'correction');
        $found = $this->findFuel($number);

        return DB::transaction(function () use ($user, $found, $dto, $reason) {
            $v = Vehicle::whereKey($found->vehicle_id)->lockForUpdate()->firstOrFail();
            $rec = FuelRecord::whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($rec->voided_at) {
                throw AppError::rule('FUEL_VOIDED', "سجل الوقود {$rec->number} ملغى — لا يُصحَّح", "Fuel record {$rec->number} is void and cannot be corrected");
            }
            [$prev, $next] = $this->fuelNeighbours($rec);
            $before = $rec->prev_odometer ?? self::fuelBase($rec, $prev); // the vehicle's reading before this record
            $data = [];
            $changes = [];
            if (isset($dto['odometer']) && (int) $dto['odometer'] !== $rec->odometer) {
                $reading = (int) $dto['odometer'];
                if ($prev && $reading < $prev->odometer) {
                    throw AppError::rule('FUEL_ODOMETER_BELOW_PREVIOUS', 'قراءة العداد ('.U::fmt0($reading).") أقل من قراءة التعبئة السابقة {$prev->number} (".U::fmt0($prev->odometer).')', "Odometer {$reading} is below the previous fill {$prev->number} ({$prev->odometer})", ['min' => $prev->odometer]);
                }
                if ($next && $reading > $next->odometer) {
                    throw AppError::rule('FUEL_ODOMETER_ABOVE_NEXT', 'قراءة العداد ('.U::fmt0($reading).") أعلى من قراءة التعبئة التالية {$next->number} (".U::fmt0($next->odometer).')', "Odometer {$reading} is above the next fill {$next->number} ({$next->odometer})", ['max' => $next->odometer]);
                }
                $data['odometer'] = $reading;
                $changes['odometer'] = [$rec->odometer, $reading];
            }
            foreach (['liters', 'cost'] as $field) {
                if (isset($dto[$field]) && (float) $dto[$field] !== (float) $rec->getAttribute($field)) {
                    $data[$field] = (float) $dto[$field];
                    $changes[$field] = [$rec->getAttribute($field), $data[$field]];
                }
            }
            if (isset($data['liters']) || isset($data['cost'])) {
                $this->assertFuelPrice($data['liters'] ?? $rec->liters, $data['cost'] ?? $rec->cost);
            }
            if (array_key_exists('station', $dto)) {
                $station = trim((string) ($dto['station'] ?? '')) ?: null;
                if ($station !== $rec->station) {
                    $data['station'] = $station;
                    $changes['station'] = [$rec->station, $station];
                }
            }
            $oldDay = $rec->date?->format('Y-m-d');
            if (isset($dto['date'])) {
                $date = U::parseDate($dto['date']) ?? throw AppError::validation('BAD_DATE', 'تاريخ غير صالح', 'Invalid date');
                if ($date->getTimestamp() !== $rec->date?->getTimestamp()) {
                    $data['date'] = $date;
                    $changes['date'] = [U::fmtDate($rec->date), U::fmtDate($date)];
                }
            }
            $oldTripId = $rec->trip_id;
            if (array_key_exists('tripNumber', $dto)) {
                $given = trim((string) ($dto['tripNumber'] ?? ''));
                $current = $oldTripId ? Trip::find($oldTripId) : null;
                if (! ($current && in_array($given, [$current->number, $current->id], true))) { // the same trip is not re-validated
                    $trip = $this->fuelTrip($given, $v);
                    if ($trip?->id !== $oldTripId) {
                        $data['trip_id'] = $trip?->id;
                        $changes['trip'] = [$current?->number, $trip?->number];
                    }
                }
            }
            if (! $changes) {
                return $this->fuelRow($rec) + ['alert' => null, 'reposted' => null, 'message' => 'لا تغيير — السجل كما هو', 'messageEn' => 'Nothing changed'];
            }

            $oldOdometer = $rec->odometer;
            $wasAnomalous = [$rec->id => $rec->anomaly] + ($next ? [$next->id => $next->anomaly] : []);
            if (! $prev && $rec->prev_odometer === null) {
                $data['prev_odometer'] = $before; // written before the column existed: keep the distance it measured
            }
            $rec->update($data + ['corrected_at' => now(), 'corrected_by' => $user->username, 'correction_note' => $reason]);
            $this->refigureFuel($rec, $prev, $v);
            if (isset($data['odometer'])) {
                if ($next) {
                    if ($next->prev_odometer === $oldOdometer) {
                        $next->prev_odometer = max($rec->odometer, $before);
                    }
                    $this->refigureFuel($next, $rec, $v);
                }
                if ($v->odometer === $oldOdometer) { // this record carried the vehicle's reading: back to the highest remaining one
                    $others = FuelRecord::where('vehicle_id', $v->id)->whereNull('voided_at')->where('id', '!=', $rec->id)->max('odometer');
                    $this->setOdometerFromFuel($user, $v, max($rec->odometer, $before, (int) ($others ?? 0)), $rec);
                } elseif ($rec->odometer > $v->odometer) {
                    $this->setOdometerFromFuel($user, $v, $rec->odometer, $rec);
                }
            }
            $posting = app(FleetPosting::class);
            $financial = isset($changes['cost']) || isset($changes['trip']) || (isset($data['date']) && $data['date']->format('Y-m-d') !== $oldDay);
            $journal = $financial ? $posting->repostFuel($user, $rec, "تصحيح {$rec->number}: {$reason}") : null;
            foreach (array_unique(array_filter([$oldTripId, $rec->trip_id])) as $tripId) {
                $posting->rebuildTripCosts($tripId);
            }
            $this->audit->log($user, ['action' => 'FUEL.CORRECT', 'entityType' => 'FuelRecord', 'entityId' => $rec->id, 'entityNumber' => $rec->number,
                'oldValue' => array_map(fn ($c) => $c[0], $changes), 'newValue' => array_map(fn ($c) => $c[1], $changes) + ['reason' => $reason, 'journal' => $journal?->number]]);
            $message = "صُحّح سجل الوقود {$rec->number} (".implode('، ', array_keys($changes)).") — {$reason}".($journal ? " · أُعيد القيد {$journal->number}" : '');
            $this->notify->activity($user, 'FuelRecord', $rec->id, $rec->number, $message, "Fuel {$rec->number} corrected (".implode(', ', array_keys($changes)).") — {$reason}", $financial ? ['finance'] : []);
            $alert = $this->syncFuelAlert($user, $v, array_values(array_filter([$rec, $next], fn ($r) => $r && $r->anomaly && ! $wasAnomalous[$r->id])));

            return $this->fuelRow($rec) + ['alert' => $alert, 'reposted' => $journal?->number, 'vehicleOdometer' => $v->odometer, 'message' => $message, 'messageEn' => "Fuel record {$rec->number} corrected"];
        });
    }

    /**
     * Void a fuel record (entered twice, wrong vehicle …): it is stamped, never deleted. The journal is reversed, the
     * next fill is re-measured from the fill before this one, the vehicle odometer goes back to the highest remaining
     * reading when this record carried it, and the trip's cost sheet is rebuilt. A voided record counts in no total,
     * average or cost.
     */
    public function voidFuel(AuthUser $user, string $number, ?string $reasonIn): array
    {
        $reason = self::reason($reasonIn, 'سبب الإلغاء', 'void');
        $found = $this->findFuel($number);

        return DB::transaction(function () use ($user, $found, $reason) {
            $v = Vehicle::whereKey($found->vehicle_id)->lockForUpdate()->firstOrFail();
            $rec = FuelRecord::whereKey($found->id)->lockForUpdate()->firstOrFail();
            if ($rec->voided_at) {
                throw AppError::rule('FUEL_VOIDED', "سجل الوقود {$rec->number} ملغى مسبقًا", "Fuel record {$rec->number} is already void");
            }
            [$prev, $next] = $this->fuelNeighbours($rec);
            $before = $rec->prev_odometer ?? self::fuelBase($rec, $prev); // the vehicle's reading before this record
            $rec->update(['voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => $reason]);
            $posting = app(FleetPosting::class);
            $reversal = $posting->reverseFuel($user, $rec, "إلغاء {$rec->number}: {$reason}");
            $nextWasAnomalous = (bool) $next?->anomaly;
            if ($next) {
                if ($next->prev_odometer === $rec->odometer || ($next->prev_odometer === null && ! $prev)) {
                    $next->prev_odometer = $before;
                }
                $this->refigureFuel($next, $prev, $v);
            }
            $odometerBefore = $v->odometer;
            if ($v->odometer === $rec->odometer) { // this record carried the vehicle's reading: back to the highest remaining one
                $others = FuelRecord::where('vehicle_id', $v->id)->whereNull('voided_at')->max('odometer');
                $this->setOdometerFromFuel($user, $v, max($before, (int) ($others ?? 0)), $rec);
            }
            if ($rec->trip_id) {
                $posting->rebuildTripCosts($rec->trip_id);
            }
            $this->audit->log($user, ['action' => 'FUEL.VOID', 'entityType' => 'FuelRecord', 'entityId' => $rec->id, 'entityNumber' => $rec->number,
                'oldValue' => ['cost' => $rec->cost, 'liters' => $rec->liters, 'odometer' => $rec->odometer], 'newValue' => ['reason' => $reason, 'reversal' => $reversal?->number, 'vehicleOdometer' => $v->odometer]]);
            $message = "أُلغي سجل الوقود {$rec->number} — {$reason}".($reversal ? " · عُكس القيد ({$reversal->number})" : '').($v->odometer !== $odometerBefore ? " · عداد {$v->code} عاد إلى ".U::fmt0($v->odometer) : '');
            $this->notify->activity($user, 'FuelRecord', $rec->id, $rec->number, $message, "Fuel {$rec->number} voided — {$reason}", ['finance']);
            $this->syncFuelAlert($user, $v, $next && $next->anomaly && ! $nextWasAnomalous ? [$next] : []);

            return $this->fuelRow($rec) + ['reversal' => $reversal?->number, 'vehicleOdometer' => $v->odometer, 'message' => $message, 'messageEn' => "Fuel record {$rec->number} voided"];
        });
    }

    // ═══════════ alerts ═══════════
    /**
     * One open alert per (entityCode, category): update when open, else create (code via numbering AL).
     * With `$data = null` and `$resolveKey` the open alert for that key is auto-resolved (condition no longer holds).
     *
     * @param  ?array{category:string, severity:string, entityType:string, entityId:string, entityCode:string, textAr:string, textEn?:?string, dueDate?:?string, owner?:?string, recommendAr?:?string, recommendEn?:?string}  $data
     * @param  ?array{entityCode:string, category:string}  $resolveKey
     */
    private function upsertAlert(?array $data, ?AuthUser $user = null, bool $notify = false, ?array $resolveKey = null): ?FleetAlert
    {
        if ($data === null) {
            if (! $resolveKey) {
                return null;
            }
            $stale = FleetAlert::where('entity_code', $resolveKey['entityCode'])->where('category', $resolveKey['category'])->whereIn('status', self::ALERT_OPEN)->get();
            foreach ($stale as $a) {
                $from = $a->status;
                $a->update(['status' => 'resolved', 'resolved_at' => now()]);
                $this->audit->status($user, 'FleetAlert', $a->id, $a->code, $from, 'resolved', 'auto: condition cleared');
            }

            return null;
        }
        $existing = FleetAlert::where('entity_code', $data['entityCode'])->where('category', $data['category'])->whereIn('status', self::ALERT_OPEN)->first();
        if ($existing) {
            $update = ['severity' => $data['severity'], 'text_ar' => $data['textAr']];
            foreach (['textEn' => 'text_en', 'dueDate' => 'due_date', 'recommendAr' => 'recommend_ar', 'recommendEn' => 'recommend_en'] as $key => $column) {
                if (isset($data[$key])) {
                    $update[$column] = $data[$key];
                }
            }
            if ($existing->status === 'snoozed' && $existing->snoozed_until && $existing->snoozed_until->isPast()) {
                $update += ['status' => 'open', 'snoozed_until' => null];
            }
            $existing->update($update);

            return $existing->refresh();
        }
        $code = $this->numbering->next('AL');
        $a = FleetAlert::create([
            'code' => $code, 'category' => $data['category'], 'severity' => $data['severity'], 'entity_type' => $data['entityType'], 'entity_id' => $data['entityId'], 'entity_code' => $data['entityCode'],
            'text_ar' => $data['textAr'], 'text_en' => $data['textEn'] ?? null, 'due_date' => $data['dueDate'] ?? null, 'owner' => $data['owner'] ?? null,
            'recommend_ar' => $data['recommendAr'] ?? null, 'recommend_en' => $data['recommendEn'] ?? null,
        ]);
        if ($notify) {
            $this->notify->activity($user, 'FleetAlert', $a->id, $code, "تنبيه {$code} ({$data['category']}): {$data['textAr']}", "Alert {$code}: ".($data['textEn'] ?? $data['textAr']), ['disp']);
        }

        return $a->refresh();
    }

    /** Document / maintenance alerts for every active vehicle and driver. Idempotent; auto-resolves cleared ones. */
    public function refreshDocumentAlerts(?AuthUser $user = null): array
    {
        $policy = $this->alertPolicy();
        $vehicles = Vehicle::where('active', true)->get();
        $drivers = Driver::where('active', true)->get();
        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0];

        DB::transaction(function () use ($user, $vehicles, $drivers, $policy, &$stats) {
            $before = FleetAlert::count();
            foreach ($vehicles as $v) {
                $this->refreshVehicleAlerts($v, $user, $policy);
            }
            foreach ($drivers as $d) {
                $this->refreshDriverAlerts($d, $user, $policy);
            }
            $stats['created'] = FleetAlert::count() - $before;
        });
        $this->markRefreshed();

        return $stats;
    }

    /** @return array{warnDays: int, soonKm: float, minDaysLic: int} */
    private function alertPolicy(): array
    {
        return [
            'warnDays' => (int) $this->settings->get('fleet.docExpiryWarnDays'), 'soonKm' => (float) $this->settings->get('fleet.maintenanceSoonKm'),
            'minDaysLic' => (int) $this->settings->get('fleet.driverLicenseMinDays'),
        ];
    }

    /** Documents with a date inside the warning window, the most urgent first. */
    private static function dueDocs(array $docs, int $warnDays): array
    {
        $docs = array_values(array_filter($docs, fn ($d) => $d['days'] !== null && $d['days'] <= $warnDays));
        usort($docs, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $docs;
    }

    private static function expiryLabel(int $days): string
    {
        return $days < 0 ? 'منتهية منذ '.abs($days).' يومًا' : ($days === 0 ? 'تنتهي اليوم' : "تنتهي خلال {$days} يومًا");
    }

    /**
     * One vehicle: its documents alert (any of the four expired stops assignment) and its maintenance-due alert.
     * Must run inside the caller's transaction.
     *
     * @param  array{warnDays: int, soonKm: float, minDaysLic: int}  $p
     */
    private function refreshVehicleAlerts(Vehicle $v, ?AuthUser $user, array $p): void
    {
        $docs = self::dueDocs(U::docInfos($v, U::VEHICLE_DOCS), $p['warnDays']);
        if ($docs) {
            $min = $docs[0]['days'];
            $this->upsertAlert([
                'category' => 'vehdoc', 'severity' => $min <= 7 ? 'c' : 'w', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityCode' => $v->code, 'dueDate' => U::fmtDate($docs[0]['date']), 'owner' => 'مدير الأسطول',
                'textAr' => "وثائق {$v->code}: ".implode(' · ', array_map(fn ($d) => "{$d['labelAr']} ".self::expiryLabel($d['days']).' ('.U::fmtDate($d['date']).')', $docs)),
                'textEn' => "{$v->code} documents: ".implode(', ', array_map(fn ($d) => "{$d['labelEn']} ".U::fmtDate($d['date']), $docs)),
                'recommendAr' => $min < 0 ? 'المركبة غير قابلة للإسناد حتى التجديد' : 'تجديد الوثائق قبل الإسناد', 'recommendEn' => $min < 0 ? 'Not assignable until renewed' : 'Renew before assignment',
            ]);
        } else {
            $this->upsertAlert(null, $user, false, ['entityCode' => $v->code, 'category' => 'vehdoc']);
        }
        $dueKm = $v->next_maint_km !== null ? $v->next_maint_km - $v->odometer : null;
        $dueDays = U::daysTo($v->next_maint_date);
        $maintDue = $v->state !== 'maintenance' && (($dueKm !== null && $dueKm <= $p['soonKm']) || ($dueDays !== null && $dueDays <= $p['warnDays']));
        if ($maintDue) {
            $overdue = ($dueKm !== null && $dueKm <= 0) || ($dueDays !== null && $dueDays < 0);
            $this->upsertAlert([
                'category' => 'maint', 'severity' => $overdue ? 'c' : 'w', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityCode' => $v->code,
                'dueDate' => $v->next_maint_date ? U::fmtDate($v->next_maint_date) : ($dueKm !== null ? U::fmt0($v->next_maint_km).' كم' : null), 'owner' => 'مدير الأسطول',
                'textAr' => "صيانة {$v->code} ".($overdue ? 'متأخرة' : 'قريبة')
                    .($dueKm !== null ? ' — '.($dueKm <= 0 ? 'تجاوزت' : 'بعد').' '.U::fmt0(abs($dueKm)).' كم' : '')
                    .($dueDays !== null ? ' — '.($dueDays < 0 ? 'منذ' : 'خلال').' '.abs($dueDays).' يومًا' : ''),
                'textEn' => 'Maintenance '.($overdue ? 'overdue' : 'due')." {$v->code}", 'recommendAr' => 'جدولة أمر صيانة وقائية', 'recommendEn' => 'Schedule preventive maintenance',
            ]);
        } else {
            $this->upsertAlert(null, $user, false, ['entityCode' => $v->code, 'category' => 'maint']);
        }
    }

    /**
     * One driver: the documents alert, worded as what really happens — a licence with fewer than
     * `fleet.driverLicenseMinDays` days left and an expired iqama / ID stop assignment (the driver is blocked here), the
     * medical certificate only warns. Must run inside the caller's transaction.
     *
     * @param  array{warnDays: int, soonKm: float, minDaysLic: int}  $p
     */
    private function refreshDriverAlerts(Driver $d, ?AuthUser $user, array $p): void
    {
        $min = $p['minDaysLic'];
        $block = self::driverDocBlock($d, $min);
        // the licence is due as soon as it is under the minimum days, even when that is earlier than the warning window
        $docs = array_merge(
            self::dueDocs(array_filter(U::docInfos($d, U::DRIVER_DOCS), fn ($x) => $x['key'] === 'licenseExpiry'), max($p['warnDays'], $min - 1)),
            self::dueDocs(array_filter(U::docInfos($d, U::DRIVER_DOCS), fn ($x) => $x['key'] !== 'licenseExpiry'), $p['warnDays']),
        );
        usort($docs, fn ($a, $b) => $a['days'] <=> $b['days']);
        if ($docs) {
            $advice = array_map(fn ($x) => match ($x['key']) {
                'licenseExpiry' => $x['days'] < $min
                    ? ["الرخصة: الإسناد موقوف حتى التجديد (أقل من {$min} يومًا)", "License: assignment blocked until renewed (under {$min} days)"]
                    : ["الرخصة: يتوقف الإسناد عند بقاء أقل من {$min} يومًا", "License: assignment stops under {$min} days"],
                'iqamaExpiry' => $x['days'] < 0
                    ? ['الإقامة / الهوية منتهية: الإسناد موقوف حتى التجديد', 'Iqama / ID expired: assignment blocked until renewed']
                    : ['الإقامة / الهوية: يتوقف الإسناد عند انتهائها', 'Iqama / ID: assignment stops when it expires'],
                default => ['الشهادة الصحية: تنبيه فقط — لا توقف الإسناد', 'Medical certificate: warning only — assignment is not blocked'],
            }, $docs);
            // critical only for the documents that stop assignment; the medical certificate never raises above a warning
            $stopping = array_filter($docs, fn ($x) => $x['key'] !== 'medicalExpiry');
            $critical = $block !== null || ($stopping && min(array_column($stopping, 'days')) <= 7);
            $this->upsertAlert([
                'category' => 'driverdoc', 'severity' => $critical ? 'c' : 'w', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityCode' => $d->code, 'dueDate' => U::fmtDate($docs[0]['date']), 'owner' => 'مدير الأسطول',
                'textAr' => implode(' · ', array_map(fn ($x) => ($x['labelAr'] === 'الرخصة' ? 'رخصة' : $x['labelAr'])." {$d->name_ar} ".self::expiryLabel($x['days']).' ('.U::fmtDate($x['date']).')', $docs)),
                'textEn' => ($d->name_en ?: $d->code).': '.implode(', ', array_map(fn ($x) => "{$x['labelEn']} ".U::fmtDate($x['date']), $docs)),
                'recommendAr' => implode(' · ', array_column($advice, 0)), 'recommendEn' => implode(' · ', array_column($advice, 1)),
            ]);
        } else {
            $this->upsertAlert(null, $user, false, ['entityCode' => $d->code, 'category' => 'driverdoc']);
        }
        if ($block && ! $d->blocked) {
            $d->update(['blocked' => true]);
            $this->audit->log($user, ['action' => 'DRIVER.BLOCK', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityNumber' => $d->code, 'field' => 'blocked', 'oldValue' => false, 'newValue' => true]);
            $this->notify->activity($user, 'Driver', $d->id, $d->code, "أُوقف إسناد السائق {$d->name_ar}: {$block['ar']}", "Driver {$d->code} blocked from assignment: {$block['en']}", ['disp']);
        }
    }

    /** @param  array{status?:?string, category?:?string, severity?:?string, entity?:?string}  $f */
    public function listAlerts(Paging $page, array $f): array
    {
        if ($this->refreshIsDue()) {
            try {
                $this->refreshDocumentAlerts();
            } catch (Throwable $e) {
                Log::warning('alert refresh failed: '.$e->getMessage());
            }
        }
        $query = FleetAlert::query()->whereIn('status', ! empty($f['status']) ? explode(',', $f['status']) : self::ALERT_OPEN);
        if (! empty($f['category'])) {
            $query->whereIn('category', explode(',', $f['category']));
        }
        if (! empty($f['severity'])) {
            $query->whereIn('severity', explode(',', $f['severity']));
        }
        if (! empty($f['entity'])) {
            $query->where('entity_code', $f['entity']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('entity_code', 'like', $like)->orWhere('text_ar', 'like', $like)->orWhere('text_en', 'like', $like));
        }
        $query->orderBy('severity')->orderByDesc('created_at')->orderByDesc('id');
        $bySeverity = FleetAlert::whereIn('status', self::ALERT_OPEN)->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity')->map(fn ($n) => (int) $n)->all();

        return $page->paginate($query) + ['openBySeverity' => U::map($bySeverity)];
    }

    /**
     * resolve / snooze (24h default) / assign.
     *
     * @param  array{note?:?string, owner?:?string, hours?:int|float|string|null}  $dto
     */
    public function alertAction(AuthUser $user, string $code, string $act, array $dto): array
    {
        $a = FleetAlert::where('id', $code)->orWhere('code', $code)->first()
            ?? throw AppError::notFound('ALERT_NOT_FOUND', "التنبيه {$code} غير موجود", 'Alert not found');
        if ($a->status === 'resolved') {
            throw AppError::rule('ALERT_RESOLVED', 'التنبيه مُغلق مسبقًا', 'Alert already resolved');
        }
        $to = $act === 'resolve' ? 'resolved' : ($act === 'snooze' ? 'snoozed' : 'assigned');
        $hours = (float) (($dto['hours'] ?? null) ?: 24);
        $hoursText = U::num($hours);

        return DB::transaction(function () use ($user, $a, $act, $to, $dto, $hours, $hoursText) {
            $from = $a->status;
            $a->update([
                'status' => $to, 'resolved_at' => $to === 'resolved' ? now() : null,
                'snoozed_until' => $to === 'snoozed' ? now()->addSeconds((int) round($hours * 3600)) : null,
                'owner' => $act === 'assign' ? (($dto['owner'] ?? null) ?: $user->nameAr) : $a->owner,
            ]);
            $this->audit->status($user, 'FleetAlert', $a->id, $a->code, $from, $to, $dto['note'] ?? null);
            $text = $act === 'resolve' ? "أُغلق التنبيه {$a->code} — سُجل في Audit" : ($act === 'snooze' ? "أُجّل التنبيه {$a->code} {$hoursText} ساعة" : "أُسند التنبيه {$a->code} إلى {$a->owner}");
            $this->notify->activity($user, 'FleetAlert', $a->id, $a->code, $text, "Alert {$a->code} {$to}");
            $message = $act === 'resolve' ? "أُغلق التنبيه {$a->code} — سُجل في Audit" : ($act === 'snooze' ? "أُجّل التنبيه {$hoursText} ساعة" : "أُسند التنبيه إلى {$a->owner}");

            return $a->refresh()->toArray() + ['message' => $message];
        });
    }

    private function refreshIsDue(): bool
    {
        try {
            return time() - (int) Cache::get(self::REFRESH_CACHE_KEY, 0) > 300;
        } catch (Throwable) {
            return true;
        }
    }

    private function markRefreshed(): void
    {
        try {
            Cache::put(self::REFRESH_CACHE_KEY, time(), 3600);
        } catch (Throwable) {
            // the throttle is an optimisation only
        }
    }

    // ═══════════ driver ops requests ═══════════
    /** @param  array{status?:?string, type?:?string, driver?:?string}  $f */
    public function listOpsRequests(Paging $page, array $f, ?AuthUser $user = null): array
    {
        $query = OpsRequest::query()->with('driver');
        if (! empty($f['status'])) {
            $query->whereIn('status', explode(',', $f['status']));
        }
        if (! empty($f['type'])) {
            $query->where('type', $f['type']);
        }
        if (! empty($f['driver'])) {
            $query->whereHas('driver', fn ($w) => $w->where('code', $f['driver']));
        }
        if ($user?->driverId && ! $user->can('opreq.manage')) { // drivers see their own
            $query->where('driver_id', $user->driverId);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('desc', 'like', $like)->orWhere('driver_name', 'like', $like)->orWhere('vehicle_code', 'like', $like));
        }
        $query->orderByDesc('created_at')->orderByDesc('id');

        return $page->paginate($query, fn (OpsRequest $r) => U::shape($r->toArray(), ['driver' => ['code', 'nameAr']]) + $this->opreqLabels($r));
    }

    public function getOpsRequest(string $number): array
    {
        $r = OpsRequest::with('driver')->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('OPREQ_NOT_FOUND', "الطلب {$number} غير موجود", 'Request not found');
        $history = StatusHistory::where('entity_type', 'OpsRequest')->where('entity_id', $r->id)->orderBy('at')->orderBy('id')->get();

        return U::shape($r->toArray(), ['driver' => ['code', 'nameAr', 'mobile']]) + $this->opreqLabels($r) + ['history' => $history->all()];
    }

    private function opreqLabels(OpsRequest $r): array
    {
        return ['typeLabel' => U::OPREQ_TYPE_LABEL[$r->type] ?? null, 'statusLabel' => U::OPREQ_STATE_LABEL[$r->status] ?? null, 'allowed' => U::OPREQ_TRANSITIONS[$r->status] ?? []];
    }

    /** fuel/toll/parking need an amount; an amount needs an attachment; emergency/vehicle → breakdown alert; emergency starts in review. */
    public function createOpsRequest(AuthUser $user, array $dto): array
    {
        $type = $dto['type'];
        $amount = (float) ($dto['amount'] ?? 0);
        if (in_array($type, ['fuel', 'toll', 'parking'], true) && ! ($amount > 0)) {
            throw AppError::validation('OPREQ_AMOUNT', 'هذا النوع يحتاج مبلغًا', 'This type needs an amount');
        }
        if ($amount > 0 && empty($dto['attachment'])) {
            throw AppError::validation('OPREQ_ATTACHMENT', 'المبلغ يحتاج إيصالًا مرفقًا', 'Amount needs a receipt attachment');
        }
        $driver = $user->driverId ? Driver::find($user->driverId) : (! empty($dto['driverCode']) ? $this->trips->driverByCode($dto['driverCode']) : null);
        $activeTrip = $driver ? Trip::with('vehicle')->where('driver_id', $driver->id)->whereIn('status', [...U::ACTIVE_TRIP_STATES, 'dassigned'])->orderByDesc('date')->orderByDesc('id')->first() : null;
        $trip = ! empty($dto['tripNumber']) ? $this->trips->tripByNumber($dto['tripNumber']) : $activeTrip;
        $vehicleCode = ($dto['vehicleCode'] ?? null) ?: ($trip?->vehicle?->code ?: ($driver?->default_vehicle_id ? Vehicle::where('id', $driver->default_vehicle_id)->value('code') : null));
        $typeLabel = U::OPREQ_TYPE_LABEL[$type] ?? $type;
        $status = $type === 'emergency' ? 'review' : 'submitted';
        $who = $driver?->name_ar ?: $user->nameAr;
        $location = $dto['location'] ?? null;

        return DB::transaction(function () use ($user, $dto, $type, $amount, $driver, $trip, $vehicleCode, $typeLabel, $status, $who, $location) {
            $number = $this->numbering->next('OPR');
            $r = OpsRequest::create([
                'number' => $number, 'type' => $type, 'driver_id' => $driver?->id, 'driver_name' => $who, 'vehicle_code' => $vehicleCode, 'trip_number' => $trip?->number, 'amount' => $amount,
                'desc' => $dto['desc'], 'location' => $location ?: '—', 'attachment' => ($dto['attachment'] ?? null) ?: null, 'status' => $status,
            ]);
            $this->audit->log($user, ['action' => 'OPREQ.CREATE', 'entityType' => 'OpsRequest', 'entityId' => $r->id, 'entityNumber' => $number, 'newValue' => ['type' => $type, 'amount' => $amount, 'vehicle' => $vehicleCode, 'trip' => $trip?->number]]);
            $this->audit->status($user, 'OpsRequest', $r->id, $number, null, $status);
            $alert = null;
            if (in_array($type, ['emergency', 'vehicle'], true) && $vehicleCode) {
                $v = Vehicle::where('code', $vehicleCode)->first();
                if ($v) {
                    $alert = $this->upsertAlert([
                        'category' => 'breakdown', 'severity' => $type === 'emergency' ? 'c' : 'w', 'entityType' => 'Vehicle', 'entityId' => $v->id, 'entityCode' => $v->code, 'dueDate' => 'الآن', 'owner' => 'Dispatcher',
                        'textAr' => "طلب {$typeLabel} من السائق {$who}: {$dto['desc']}".($location ? ' · '.$location : ''), 'textEn' => 'Driver request', 'recommendAr' => 'مراجعة الطلب', 'recommendEn' => 'Review request',
                    ], $user, true);
                }
            }
            $this->notify->activity($user, 'OpsRequest', $r->id, $number, "طلب تشغيلي {$number} ({$typeLabel}) من {$who}".($amount ? ' — '.U::fmt0($amount).' ر.س' : '').": {$dto['desc']}", "Ops request {$number} ({$type})", ['disp']);

            return $r->refresh()->toArray() + ['alert' => $alert, 'typeLabel' => $typeLabel, 'statusLabel' => U::OPREQ_STATE_LABEL[$status]];
        });
    }

    /**
     * Audited status change; the driver is notified. Processing a fuel request records the fill through the one fuel
     * path (rules, ledger entry, trip cost sheet): the amount is the cost, and litres / the odometer reading only when
     * the person processing typed them from the receipt — nothing is derived from a price. Processing a maint / tire /
     * vehicle request opens a maintenance order through the one maintenance path.
     *
     * @param  array{liters?:float|int|string|null, odometer?:int|string|null, shop?:?string}  $extra  receipt details given while processing
     */
    public function setOpsRequestStatus(AuthUser $user, string $number, string $to, ?string $note = null, array $extra = []): array
    {
        $r = OpsRequest::with('driver')->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('OPREQ_NOT_FOUND', "الطلب {$number} غير موجود", 'Request not found');
        if (! in_array($to, U::OPREQ_TRANSITIONS[$r->status] ?? [], true)) {
            throw AppError::rule('OPREQ_TRANSITION', 'انتقال غير مسموح: '.(U::OPREQ_STATE_LABEL[$r->status] ?? $r->status).' ← '.(U::OPREQ_STATE_LABEL[$to] ?? $to), "Invalid transition {$r->status} → {$to}");
        }
        if ($to === 'rejected' && ! $note) {
            throw AppError::validation('OPREQ_REJECT_NOTE', 'سبب الرفض مطلوب', 'Rejection note required');
        }
        $vehicle = $r->vehicle_code ? Vehicle::where('code', $r->vehicle_code)->first() : null;

        return DB::transaction(function () use ($user, $r, $to, $note, $vehicle, $extra) {
            $from = $r->status;
            $r->update(['status' => $to]);
            $this->audit->status($user, 'OpsRequest', $r->id, $r->number, $from, $to, $note);
            $fuelRecord = null;
            $maintenanceOrder = null;
            $suffix = '';
            if ($to === 'processed' && $r->type === 'fuel' && $r->amount > 0) {
                if (! $vehicle) {
                    throw AppError::rule('OPREQ_NO_VEHICLE', 'لا يمكن تسجيل الوقود — الطلب بلا مركبة محددة', 'No vehicle on the request');
                }
                // the request's trip is charged only when it runs on this vehicle (a driver may name another vehicle)
                $trip = $r->trip_number ? Trip::where('number', $r->trip_number)->first() : null;
                $trip = $trip && $trip->vehicle_id === $vehicle->id ? $trip : null;
                $liters = isset($extra['liters']) ? (float) $extra['liters'] : 0.0;
                [$fuel] = $this->recordFuel($user, $vehicle, $r->driver, [
                    'date' => U::today(), 'odometer' => isset($extra['odometer']) ? (int) $extra['odometer'] : null, 'liters' => $liters, 'cost' => (float) $r->amount,
                    'station' => $r->location === '—' ? null : $r->location, 'full' => false, 'receipt' => $r->attachment, 'from' => $r->number,
                ], $trip);
                $fuelRecord = $this->fuelRow($fuel);
                $suffix = ' · سُجل في سجل الوقود وقُيّد مصروفه'.($liters > 0 ? '' : ' (بلا لترات — لا يدخل في متوسط الاستهلاك)');
            }
            if ($to === 'processed' && in_array($r->type, ['maint', 'tire', 'vehicle'], true)) {
                if (! $vehicle) {
                    throw AppError::rule('OPREQ_NO_VEHICLE', 'لا يمكن إنشاء أمر صيانة — الطلب بلا مركبة محددة', 'No vehicle on the request');
                }
                $shop = trim((string) ($extra['shop'] ?? ''));
                $maintenanceOrder = $this->openMaintenance($user, $vehicle, [
                    'kind' => $r->type === 'tire' ? 'tire' : 'corrective', 'descAr' => "من طلب السائق {$r->number}: {$r->desc}", 'descEn' => "From driver request {$r->number}: {$r->desc}",
                    'shop' => $shop ?: null, 'startDate' => U::today(), 'cost' => $r->amount ?: 0, 'downDays' => 1, 'from' => $r->number,
                ]);
                $suffix = ' · أُنشئ أمر الصيانة '.$maintenanceOrder->number.($shop ? '' : ' (حدّد الورشة من «تعديل» الأمر)');
            }
            $label = U::OPREQ_STATE_LABEL[$to];
            $text = "الطلب التشغيلي {$r->number} → {$label}".($note ? ' — '.$note : '');
            $this->notify->activity($user, 'OpsRequest', $r->id, $r->number, $text, "Ops request {$r->number} → {$to}", ['driver']);
            if ($r->driver?->user_id) {
                Notification::create(['user_id' => $r->driver->user_id, 'text_ar' => $text, 'text_en' => "Request {$r->number} → {$to}", 'entity_type' => 'OpsRequest', 'entity_id' => $r->id, 'entity_number' => $r->number, 'at' => now()]);
            }
            $row = $r->refresh()->toArray();
            unset($row['driver']);

            return $row + ['fuelRecord' => $fuelRecord, 'maintenanceOrder' => $maintenanceOrder, 'statusLabel' => $label, 'message' => "{$label} — {$r->number}{$suffix}"];
        });
    }

    // ═══════════ routes ═══════════
    /** @param  array{warehouse?:?string, tempNeed?:?string}  $f */
    public function listRoutes(Paging $page, array $f): array
    {
        $query = Route::query();
        if (! empty($f['warehouse'])) {
            $query->where('warehouse_id', Warehouse::where('code', $f['warehouse'])->value('id') ?: '—');
        }
        if (! empty($f['tempNeed'])) {
            $query->where('temp_need', $f['tempNeed']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('zones', 'like', $like));
        }
        $query->orderBy('code');
        $warehouses = Warehouse::all()->keyBy('id');

        return $page->paginate($query, function (Route $r) use ($warehouses) {
            $wh = $r->warehouse_id ? $warehouses->get($r->warehouse_id) : null;

            return $r->toArray() + ['warehouse' => $wh ? ['id' => $wh->id, 'code' => $wh->code, 'nameAr' => $wh->name_ar] : null];
        });
    }

    /** @param  array{name:string, warehouseCode?:?string, zones:string, days?:?string, window?:?string, tempNeed?:?string}  $dto */
    public function createRoute(AuthUser $user, array $dto): Route
    {
        $whCode = $dto['warehouseCode'] ?? null;
        $wh = $whCode ? Warehouse::where('code', $whCode)->first() : null;
        if ($whCode && ! $wh) {
            throw AppError::notFound('WH_NOT_FOUND', "المستودع {$whCode} غير موجود", 'Warehouse not found');
        }

        return DB::transaction(function () use ($user, $dto, $wh) {
            $code = $this->numbering->next('RT');
            $r = Route::create(['code' => $code, 'name' => $dto['name'], 'warehouse_id' => $wh?->id, 'zones' => $dto['zones'], 'days' => $dto['days'], 'window' => $dto['window'], 'temp_need' => $dto['tempNeed']]);
            $this->audit->log($user, ['action' => 'ROUTE.CREATE', 'entityType' => 'Route', 'entityId' => $r->id, 'entityNumber' => $code, 'newValue' => $dto]);
            $this->notify->activity($user, 'Route', $r->id, $code, "أُنشئ المسار «{$dto['name']}» — متاح عند إنشاء الرحلات", "Route {$dto['name']} created");

            return $r->refresh();
        });
    }

    public function updateRoute(AuthUser $user, string $code, array $dto): Route
    {
        $r = Route::where('id', $code)->orWhere('code', $code)->first()
            ?? throw AppError::notFound('ROUTE_NOT_FOUND', "المسار {$code} غير موجود", "Route {$code} not found");
        $data = [];
        $changes = [];
        foreach (['name' => 'name', 'zones' => 'zones', 'days' => 'days', 'window' => 'window', 'tempNeed' => 'temp_need'] as $field => $column) {
            if (isset($dto[$field]) && $dto[$field] !== $r->getAttribute($column)) {
                $data[$column] = $dto[$field];
                $changes[$field] = [$r->getAttribute($column), $dto[$field]];
            }
        }
        if (array_key_exists('warehouseCode', $dto)) {
            $wh = $dto['warehouseCode'] ? Warehouse::where('code', $dto['warehouseCode'])->first() : null;
            if ($dto['warehouseCode'] && ! $wh) {
                throw AppError::notFound('WH_NOT_FOUND', "المستودع {$dto['warehouseCode']} غير موجود", 'Warehouse not found');
            }
            if ($wh?->id !== $r->warehouse_id) {
                $data['warehouse_id'] = $wh?->id;
                $changes['warehouse'] = [$r->warehouse_id, $wh?->code];
            }
        }
        if (! $changes) {
            return $r;
        }

        return DB::transaction(function () use ($user, $r, $data, $changes) {
            $r->update($data);
            foreach ($changes as $field => [$old, $new]) {
                $this->audit->log($user, ['action' => 'ROUTE.UPDATE', 'entityType' => 'Route', 'entityId' => $r->id, 'entityNumber' => $r->code, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
            }

            return $r->refresh();
        });
    }

    // ═══════════ fleet KPIs ═══════════
    public function kpis(): array
    {
        $warnDays = (int) $this->settings->get('fleet.docExpiryWarnDays');
        $vehicles = Vehicle::where('active', true)->get();
        $drivers = Driver::where('active', true)->get();
        $byState = $vehicles->countBy('state')->all();
        $expiring = fn ($entity, array $docs) => ($m = U::minDays(U::docInfos($entity, $docs))) !== null && $m <= $warnDays;
        $vehDocs = $vehicles->filter(fn ($v) => $expiring($v, U::VEHICLE_DOCS))->values();
        $drvDocs = $drivers->filter(fn ($d) => $expiring($d, U::DRIVER_DOCS))->values();
        $ranking = $drivers->map(fn (Driver $d) => [
            'code' => $d->code, 'nameAr' => $d->name_ar, 'nameEn' => $d->name_en, 'rating' => $d->rating, 'ontimePct' => $d->ontime_pct, 'safety' => $d->safety, 'okPct' => $d->ok_pct, 'fuelScore' => $d->fuel_score,
            'trips' => $d->trips, 'deliveries' => $d->deliveries, 'fails' => $d->fails, 'state' => $d->state, 'blocked' => $d->blocked,
            'score' => U::round(($d->rating / 5) * 40 + $d->ontime_pct * 0.3 + $d->safety * 0.3),
        ])->sortByDesc('score')->values()->all();
        $fuel30 = FuelRecord::whereNull('voided_at')->where('date', '>=', now()->subDays(30)); // a voided fill counts nowhere

        return [
            'vehicles' => [
                'total' => $vehicles->count(), 'available' => $byState['available'] ?? 0, 'onroute' => $byState['onroute'] ?? 0, 'maintenance' => ($byState['maintenance'] ?? 0) + ($byState['breakdown'] ?? 0),
                'byState' => U::map($byState), 'docsExpiring' => $vehDocs->count(), 'docsExpiringCodes' => $vehDocs->pluck('code')->all(),
                'maintenanceDueSoon' => $vehicles->filter(fn ($v) => $v->next_maint_km !== null && $v->next_maint_km - $v->odometer <= 3000)->count(),
            ],
            'drivers' => [
                'total' => $drivers->count(), 'available' => $drivers->filter(fn ($d) => $d->state === 'available' && ! $d->blocked)->count(), 'onroute' => $drivers->where('state', 'onroute')->count(),
                'blocked' => $drivers->filter(fn ($d) => $d->blocked)->count(), 'docsExpiring' => $drvDocs->count(), 'docsExpiringCodes' => $drvDocs->pluck('code')->all(),
            ],
            'maintenance' => ['open' => MaintenanceOrder::where('status', 'open')->count(), 'openCost' => (float) MaintenanceOrder::where('status', 'open')->sum('cost')],
            'fuel' => ['anomalies' => FuelRecord::whereNull('voided_at')->where('anomaly', true)->count(), 'cost30d' => (float) (clone $fuel30)->sum('cost'), 'liters30d' => (float) (clone $fuel30)->sum('liters')],
            'trips' => ['closed' => Trip::where('status', 'closed')->count(), 'kmTotal' => (float) Trip::where('status', 'closed')->sum('km')],
            'driverRanking' => $ranking,
        ];
    }

    private static function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => (string) $value,
        };
    }
}
