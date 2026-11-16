<?php

namespace App\Services\Transport;

use App\Models\Driver;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\TripEvent;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\WarehouseTransfer;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Services\Transport\TransportUtil as U;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * A warehouse transfer that leaves on one of the company's own vehicles is a trip.
 *
 * Without this link the goods of a transfer were "in transit" on paper only: no vehicle was busy, no driver was
 * accountable, the control tower did not see the truck and the run cost nothing. Now:
 *
 *  - start():   called by TransfersService::ship when a vehicle AND a driver are named. The pair is checked with the
 *               same rules as a delivery trip (documents, state, concurrent trip, capacity, temperature) and a trip of
 *               kind `transfer` is created already on the road, with one stop: the destination warehouse. A second
 *               transfer shipped on the same vehicle and driver from the same warehouse, while that trip is still on the
 *               road, joins it as another stop (a shuttle run carries several transfers).
 *  - arrived(): called by TransfersService::receive. The destination's FIRST receipt is the proof of delivery of the
 *               stop: delivered (everything arrived), partial (something is missing) or failed (nothing arrived). When
 *               every stop is done the trip is completed / partial and the vehicle is returning; the dispatcher then
 *               closes the trip as usual (odometer, cost sheet, vehicle and driver released).
 *
 * The driver can report "arrived" on such a stop from the phone, but cannot record a delivery: a transfer is received
 * by the destination warehouse, quantity by quantity, not by a signature (DeliveryService refuses with TRANSFER_STOP).
 *
 * A transfer handed to an outside carrier (carrier + waybill, no vehicle and driver) has no trip: there is no company
 * vehicle to follow. Live position comes from the vehicle's GPS unit like any trip; temperature along the road is not
 * recorded (no sensor feed is connected) and is not invented.
 *
 * Both methods run inside the caller's transaction.
 */
class TransferTripService
{
    /**
     * Straight line → road distance between two warehouses (inter-city highways run close to the straight line; city
     * rounds do not). Used only when both warehouses have coordinates, and the trip event says "estimated".
     */
    private const ROAD_FACTOR = 1.2;

    public function __construct(
        private readonly TripsService $trips,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    /**
     * @param  ?Trip  $join  a trip named explicitly by the caller (`tripId`); otherwise an open transfer run of the same
     *                       vehicle and driver is joined, or a new trip is created
     */
    public function start(AuthUser $user, WarehouseTransfer $t, Vehicle $vehicle, Driver $driver, ?Trip $join = null): Trip
    {
        $this->requireTransaction();
        $t->loadMissing(['fromWarehouse', 'toWarehouse', 'lines.product']);
        if (TripStop::where('transfer_id', $t->id)->exists()) {
            throw AppError::rule('TRANSFER_ON_TRIP', "التحويل {$t->number} مرتبط برحلة مسبقًا", "Transfer {$t->number} is already on a trip");
        }
        $load = $this->load($t);
        $trip = $join ?? $this->openRun($vehicle, $driver, $t->from_warehouse_id);
        if ($trip) {
            return $this->join($user, $trip, $t, $vehicle, $driver, $load);
        }

        // the same gate as a delivery trip
        $vehicle = $this->trips->vehicleByCode($vehicle->id);
        $ca = $this->trips->vehicleCanAssign($vehicle);
        if (! $ca['ok']) {
            throw AppError::rule('VEHICLE_CANNOT_ASSIGN', "مرفوض: {$ca['why']}", $ca['whyEn']);
        }
        if ($this->trips->isVehicleBusy($vehicle->id)) {
            throw AppError::rule('VEHICLE_BUSY', 'مرفوض: المركبة برحلة متزامنة', 'Vehicle busy on a concurrent trip');
        }
        if ($why = $this->trips->vehicleFitsTrip($vehicle, $load)) {
            throw AppError::rule('VEHICLE_UNFIT', "مرفوض: {$why}", 'Vehicle does not fit the load');
        }
        $dc = $this->trips->driverCanAssign($driver);
        if (! $dc['ok']) {
            throw AppError::rule('DRIVER_CANNOT_ASSIGN', "مرفوض: {$dc['why']}", $dc['whyEn']);
        }
        if ($this->trips->isDriverBusy($driver->id)) {
            throw AppError::rule('DRIVER_BUSY', 'مرفوض: السائق برحلة متزامنة', 'Driver busy on a concurrent trip');
        }

        $now = now();
        $from = $t->fromWarehouse;
        $to = $t->toWarehouse;
        $km = $this->roadKm($from?->lat, $from?->lng, $to?->lat, $to?->lng);
        $number = $this->numbering->next('TRP');
        $trip = Trip::create([
            'number' => $number, 'kind' => 'transfer', 'date' => $now->copy()->startOfDay(), 'warehouse_id' => $t->from_warehouse_id,
            'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
            'route_ar' => "تحويل من {$from->name_ar} إلى {$to->name_ar}", 'route_en' => 'Transfer '.($from->name_en ?: $from->code).' to '.($to->name_en ?: $to->code),
            'km' => $km ?? 0, 'planned_start' => $now->copy()->setTimezone(config('app.business_timezone', 'Asia/Riyadh'))->format('H:i'), 'actual_start' => $now, 'dispatched_at' => $now, 'status' => 'onroute',
            'kg' => $load['kg'], 'cbm' => $load['cbm'], 'pallets' => $load['pallets'], 'temp_need' => $load['tempNeed'],
            'created_by_id' => $user->id, 'created_by' => $user->nameAr,
        ]);
        TripCost::create(['trip_id' => $trip->id]);
        $this->event($trip->id, "رحلة تحويل {$t->number} — انطلقت من {$from->name_ar} · {$user->nameAr}", "Transfer trip for {$t->number} — left ".($from->name_en ?: $from->code));
        $this->event($trip->id, "المركبة {$vehicle->code} · السائق {$driver->name_ar}", "Vehicle {$vehicle->code} · driver ".($driver->name_en ?: $driver->code));
        if ($km !== null) {
            $this->event($trip->id, 'المسافة '.U::fmt0($km).' كم تقديرية من إحداثيات المستودعين — تُصحَّح بقراءة العداد عند الإقفال', 'Distance '.U::fmt0($km).' km estimated from the warehouse coordinates');
        }
        $this->addStop($trip, $t, 1, $load);

        // vehicle and driver are on the road from this moment (a factual report, like a dispatch)
        $this->trips->transitionVehicle($user, $vehicle, 'onroute', "رحلة التحويل {$number}", force: true);
        if ($driver->state !== 'onroute') {
            $was = $driver->state;
            $driver->update(['state' => 'onroute']);
            $this->audit->status($user, 'Driver', $driver->id, $driver->code, $was, 'onroute', "رحلة التحويل {$number}");
        }

        $this->audit->log($user, ['action' => 'TRIP.CREATE', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $number,
            'newValue' => ['kind' => 'transfer', 'transfer' => $t->number, 'from' => $from->code, 'to' => $to->code, 'kg' => $load['kg'], 'vehicle' => $vehicle->code, 'driver' => $driver->code]]);
        $this->audit->status($user, 'Trip', $trip->id, $number, null, 'onroute', "تحويل {$t->number}");
        $this->notify->activity($user, 'Trip', $trip->id, $number, "انطلقت رحلة التحويل {$number} — {$t->number} من {$from->name_ar} إلى {$to->name_ar} · {$vehicle->code}",
            "Transfer trip {$number} left — {$t->number} · {$vehicle->code}", ['disp']);
        $this->link($t, $trip, $vehicle, $driver);
        $this->planArrival($trip);

        return $trip;
    }

    /**
     * The destination recorded a receipt. Only the first one closes the stop — later receipts and the variance
     * resolution belong to the transfer, the truck has already been unloaded.
     */
    public function arrived(AuthUser $user, WarehouseTransfer $t, int $receivedNow, int $remaining): void
    {
        $this->requireTransaction();
        $stop = TripStop::with('trip.vehicle')->where('transfer_id', $t->id)->whereIn('status', ['pending', 'arrived'])->lockForUpdate()->first();
        if (! $stop) {
            return;
        }
        $now = now();
        $result = $remaining === 0 ? 'delivered' : ($receivedNow > 0 ? 'partial' : 'failed');
        $from = $stop->status;
        $stop->update(['status' => $result, 'arrived_at' => $stop->arrived_at ?: $now, 'completed_at' => $now]);
        $this->audit->status($user, 'TripStop', $stop->id, "{$stop->trip->number}#{$stop->seq}", $from, $result, "استلام التحويل {$t->number}");
        $textAr = $result === 'delivered' ? "استُلم التحويل {$t->number} كاملًا"
            : ($result === 'partial' ? "استُلم التحويل {$t->number} ناقصًا — باقٍ {$remaining}" : "لم يصل شيء من التحويل {$t->number}");
        $this->event($stop->trip_id, "المحطة {$stop->seq} · {$textAr} · {$user->nameAr}", "Stop {$stop->seq} · transfer {$t->number} {$result}");

        $trip = $stop->trip;
        if ($trip->status !== 'onroute' || TripStop::where('trip_id', $trip->id)->whereIn('status', ['pending', 'arrived'])->exists()) {
            return;
        }
        $anyShort = TripStop::where('trip_id', $trip->id)->whereIn('status', ['failed', 'rejected', 'partial'])->exists();
        $status = $anyShort ? 'partial' : 'completed';
        Trip::whereKey($trip->id)->update(['status' => $status, 'updated_at' => $now]);
        $this->audit->status($user, 'Trip', $trip->id, $trip->number, 'onroute', $status, 'استلمت الوجهة ما على المركبة');
        if ($trip->vehicle) {
            $this->trips->walkVehicle($user, $trip->vehicle, ['returning'], "انتهت رحلة التحويل {$trip->number}");
        }
        $this->notify->activity($user, 'Trip', $trip->id, $trip->number, "وصلت رحلة التحويل {$trip->number} — أقفلها بقراءة العداد لتُحسب تكلفتها وتتاح المركبة", "Transfer trip {$trip->number} arrived — close it", ['disp']);
    }

    /** An open transfer run of this vehicle and driver from this warehouse that the destination has not unloaded yet. */
    private function openRun(Vehicle $vehicle, Driver $driver, string $warehouseId): ?Trip
    {
        return Trip::where('kind', 'transfer')->where('status', 'onroute')->where('vehicle_id', $vehicle->id)->where('driver_id', $driver->id)
            ->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
    }

    /** @param  array{kg:float, cbm:float, pallets:int, tempNeed:string, items:int}  $load */
    private function join(AuthUser $user, Trip $trip, WarehouseTransfer $t, Vehicle $vehicle, Driver $driver, array $load): Trip
    {
        if ($trip->kind !== 'transfer' || $trip->status !== 'onroute') {
            throw AppError::rule('TRIP_NOT_TRANSFER', "الرحلة {$trip->number} ليست رحلة تحويل في الطريق — اشحن بالمركبة والسائق لتُنشأ رحلة تحويل، أو اختر رحلة تحويل لم تصل بعد",
                "Trip {$trip->number} is not a transfer trip on the road");
        }
        if ($trip->warehouse_id !== $t->from_warehouse_id) {
            throw AppError::rule('TRIP_OTHER_WH', "الرحلة {$trip->number} انطلقت من مستودع آخر", "Trip {$trip->number} left from another warehouse");
        }
        if ($trip->vehicle_id !== $vehicle->id || $trip->driver_id !== $driver->id) {
            throw AppError::rule('TRIP_OTHER_CREW', "الرحلة {$trip->number} بمركبة أو سائق مختلف عمّا حُدّد للتحويل", "Trip {$trip->number} has another vehicle or driver");
        }
        if (TripStop::where('trip_id', $trip->id)->whereNotIn('status', ['pending', 'arrived'])->exists()) {
            throw AppError::rule('TRIP_UNLOADING', "الرحلة {$trip->number} بدأت التفريغ في الوجهة — اشحن هذا التحويل برحلة جديدة بعد إقفالها", "Trip {$trip->number} is already being unloaded");
        }
        $total = ['kg' => U::round1($trip->kg + $load['kg']), 'cbm' => U::round1($trip->cbm + $load['cbm']), 'pallets' => $trip->pallets + $load['pallets'],
            'tempNeed' => U::TEMP_RANK[$load['tempNeed']] > (U::TEMP_RANK[$trip->temp_need] ?? 0) ? $load['tempNeed'] : ($trip->temp_need ?: 'dry')];
        if ($why = $this->trips->vehicleFitsTrip($vehicle, $total)) {
            throw AppError::rule('VEHICLE_UNFIT', "مرفوض: {$why}", 'Vehicle does not fit the load');
        }
        $seq = (int) TripStop::where('trip_id', $trip->id)->max('seq') + 1;
        $this->addStop($trip, $t, $seq, $load);
        Trip::whereKey($trip->id)->update(['kg' => $total['kg'], 'cbm' => $total['cbm'], 'pallets' => $total['pallets'], 'temp_need' => $total['tempNeed'], 'updated_at' => now()]);
        $this->event($trip->id, "أُضيف التحويل {$t->number} إلى الرحلة · {$user->nameAr}", "Transfer {$t->number} added to the trip");
        $this->audit->log($user, ['action' => 'TRIP.ADD_TRANSFER', 'entityType' => 'Trip', 'entityId' => $trip->id, 'entityNumber' => $trip->number,
            'newValue' => ['transfer' => $t->number, 'to' => $t->toWarehouse->code, 'kg' => $load['kg']]]);
        $this->link($t, $trip, $vehicle, $driver);
        $this->planArrival($trip);

        return $trip->refresh();
    }

    /** @param  array{kg:float, items:int}  $load */
    private function addStop(Trip $trip, WarehouseTransfer $t, int $seq, array $load): TripStop
    {
        $to = $t->toWarehouse;

        return TripStop::create([
            'trip_id' => $trip->id, 'seq' => $seq, 'transfer_id' => $t->id, 'customer_ar' => "مستودع {$to->name_ar}", 'customer_en' => ($to->name_en ?: $to->code).' warehouse',
            'items' => $load['items'], 'kg' => $load['kg'], 'address' => $to->city, 'window' => $to->hours, 'lat' => $to->lat, 'lng' => $to->lng,
        ]);
    }

    /** Planned arrival at the destination, like any trip (never fails the shipment). */
    private function planArrival(Trip $trip): void
    {
        try {
            $this->trips->recomputeEtas($trip);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function link(WarehouseTransfer $t, Trip $trip, Vehicle $vehicle, Driver $driver): void
    {
        $t->update(['trip_id' => $trip->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);
    }

    /**
     * Weight, volume, pallets and temperature need of the transfer, derived from its lines like a trip derives them
     * from its orders. Volume is not a product attribute here: the same density rule as sales orders (200 kg per m³).
     *
     * @return array{kg:float, cbm:float, pallets:int, tempNeed:string, items:int}
     */
    private function load(WarehouseTransfer $t): array
    {
        $kg = 0.0;
        $items = 0;
        $need = 'dry';
        foreach ($t->lines as $l) {
            $kg += (int) $l->qty * (float) ($l->product?->weight_kg ?: 0);
            $items += (int) $l->qty;
            $class = $l->product?->storage_class;
            $lineNeed = $class === 'frozen' ? 'reefer' : ($class === 'chilled' ? 'chill' : 'dry');
            if (U::TEMP_RANK[$lineNeed] > U::TEMP_RANK[$need]) {
                $need = $lineNeed;
            }
        }
        $kg = U::round1($kg);

        return ['kg' => $kg, 'cbm' => U::round1($kg / 200), 'pallets' => 0, 'tempNeed' => $need, 'items' => $items];
    }

    private function roadKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $rad = M_PI / 180;
        $a = sin(($lat2 - $lat1) * $rad / 2) ** 2 + cos($lat1 * $rad) * cos($lat2 * $rad) * sin(($lng2 - $lng1) * $rad / 2) ** 2;

        return (float) round(6371 * 2 * atan2(sqrt($a), sqrt(1 - $a)) * self::ROAD_FACTOR);
    }

    private function event(string $tripId, string $textAr, ?string $textEn = null): TripEvent
    {
        return TripEvent::create(['trip_id' => $tripId, 'at' => now(), 'label' => U::hhmm(), 'text_ar' => $textAr, 'text_en' => $textEn]);
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('TransferTripService must run inside the caller\'s DB::transaction().');
        }
    }
}
