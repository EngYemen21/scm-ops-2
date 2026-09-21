<?php

namespace App\Services\Transport;

use App\Models\ProofOfDelivery;
use App\Models\Trip;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\MapboxMaps;
use App\Services\Integrations\Adapters\Pending;
use App\Services\Transport\TransportUtil as U;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Cache;

/**
 * The map behind the transport screens. Everything drawn is a stored fact: warehouse / stop / customer coordinates,
 * the GPS fix a driver's phone attached to a proof of delivery, a vehicle position written by a telematics provider.
 * Nothing is estimated — a stop without coordinates is listed as "not located", and live vehicle tracking stays
 * `integration_pending` until a GPS provider is configured.
 */
class TransportMapService
{
    /** The drive line of a trip is cached by its exact point list, so reopening a trip costs no provider call. */
    private const ROUTE_CACHE_MINUTES = 15;

    private const OVERVIEW_TRIPS = 40;

    private const ORIGIN = '__origin__';

    public function __construct(private readonly TripsService $trips) {}

    /** What the browser needs to draw a map. Only a Mapbox PUBLIC token (pk.…) ever leaves the server. */
    public function config(): array
    {
        $token = trim((string) config('integrations.MAPBOX_PUBLIC_TOKEN'));
        $ok = str_starts_with($token, 'pk.');

        return [
            'provider' => $ok ? 'mapbox' : null, 'configured' => $ok, 'token' => $ok ? $token : null,
            'status' => $ok ? 'connected' : Pending::STATUS,
            'gps' => ['status' => AdapterFactory::gps()->configured() ? 'connected' : Pending::STATUS],
        ];
    }

    /** @return array{lat:float, lng:float}|null */
    private static function point(mixed $lat, mixed $lng): ?array
    {
        return is_numeric($lat) && is_numeric($lng) ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;
    }

    private static function stopPoint(TripStop $s): ?array
    {
        return self::point($s->lat, $s->lng) ?? self::point($s->customer?->lat, $s->customer?->lng);
    }

    private static function origin(?Warehouse $w): ?array
    {
        return $w ? ['code' => $w->code, 'nameAr' => $w->name_ar, 'nameEn' => $w->name_en, 'city' => $w->city] + (self::point($w->lat, $w->lng) ?? ['lat' => null, 'lng' => null]) : null;
    }

    private function load(string $number): Trip
    {
        return Trip::with(['warehouse', 'vehicle', 'driver', 'stops' => fn ($q) => $q->orderBy('seq'), 'stops.customer'])
            ->where(fn ($w) => $w->where('id', $number)->orWhere('number', $number))->first()
            ?? throw AppError::notFound('TRIP_NOT_FOUND', "الرحلة {$number} غير موجودة", "Trip {$number} not found");
    }

    /** @return list<array<string, mixed>> */
    private static function stops(Trip $t): array
    {
        return $t->stops->map(function (TripStop $s) {
            $p = self::stopPoint($s);

            return [
                'id' => $s->id, 'seq' => $s->seq, 'status' => $s->status, 'customerAr' => $s->customer_ar, 'customerEn' => $s->customer_en, 'customerCode' => $s->customer?->code,
                'address' => $s->address, 'window' => $s->window, 'plannedTime' => $s->planned_time, 'actualTime' => $s->actual_time, 'lat' => $p['lat'] ?? null, 'lng' => $p['lng'] ?? null,
            ];
        })->all();
    }

    /** Warehouse → located stops in sequence → back to the warehouse. */
    private function routeFor(?array $origin, array $stops): array
    {
        $located = array_values(array_filter($stops, fn ($s) => $s['lat'] !== null));
        if (! $origin || $origin['lat'] === null || ! $located) {
            return ['status' => 'no_coordinates'];
        }
        $adapter = AdapterFactory::maps();
        if (! $adapter->configured()) {
            return Pending::result();
        }
        $points = [$origin, ...$located, $origin];
        if (count($points) > MapboxMaps::ROUTE_MAX) {
            return ['status' => 'error', 'detail' => 'too many stops for one route request'];
        }
        $key = 'trip-route:'.sha1(json_encode(array_map(fn ($p) => [round($p['lat'], 5), round($p['lng'], 5)], $points)));
        if ($hit = Cache::get($key)) {
            return $hit;
        }
        $route = $adapter->route($points);
        if ($route['status'] === 'ok') {
            Cache::put($key, $route, now()->addMinutes(self::ROUTE_CACHE_MINUTES));
        }

        return $route;
    }

    /** The newest GPS fix a driver's phone attached to a proof of delivery on this trip — a fact, not live tracking. */
    private static function lastFix(array $tripIds): array
    {
        $out = [];
        foreach (ProofOfDelivery::whereIn('trip_id', $tripIds)->whereNotNull('gps_lat')->whereNotNull('gps_lng')->orderBy('at')->orderBy('id')->get(['trip_id', 'stop_id', 'gps_lat', 'gps_lng', 'gps_accuracy', 'at']) as $p) {
            $out[$p->trip_id] = ['lat' => (float) $p->gps_lat, 'lng' => (float) $p->gps_lng, 'accuracy' => $p->gps_accuracy, 'at' => $p->at, 'stopId' => $p->stop_id, 'source' => 'pod'];
        }

        return $out;
    }

    private static function vehiclePosition(?Vehicle $v): ?array
    {
        $p = $v ? self::point($v->lat, $v->lng) : null;

        return $p ? $p + ['code' => $v->code, 'plateAr' => $v->plate_ar, 'state' => $v->state, 'gpsOnline' => (bool) $v->gps_online, 'at' => $v->last_sync_at, 'source' => 'gps'] : null;
    }

    /** Why the stop order cannot be optimised right now — null when it can. */
    private static function optimizeBlock(Trip $t, ?array $origin, array $stops): ?array
    {
        $missing = array_filter($stops, fn ($s) => $s['lat'] === null);

        return match (true) {
            ! AdapterFactory::maps()->configured() => ['MAPS_PENDING', 'خدمة الخرائط غير مربوطة بعد', 'Maps provider is not configured'],
            ! in_array($t->status, U::EDITABLE_TRIP_STATES, true) => ['TRIP_LOCKED', 'لا يمكن إعادة ترتيب المحطات بعد بدء التحميل', 'Stops locked after loading starts'],
            count($stops) < 2 => ['MAP_NOTHING_TO_OPTIMIZE', 'الترتيب يحتاج محطتين على الأقل', 'At least two stops are needed'],
            ! $origin || $origin['lat'] === null => ['MAP_NO_ORIGIN', 'حدّد موقع المستودع على الخريطة أولًا', 'The warehouse has no map location yet'],
            (bool) $missing => ['MAP_STOPS_UNLOCATED', 'محطات بلا موقع على الخريطة: '.implode('، ', array_map(fn ($s) => "{$s['seq']} · {$s['customerAr']}", $missing)), 'Some stops have no map location'],
            count($stops) > MapboxMaps::OPTIMIZE_MAX - 1 => ['MAP_TOO_MANY_STOPS', 'الترتيب الآلي يدعم حتى '.(MapboxMaps::OPTIMIZE_MAX - 1).' محطة في الرحلة', 'Too many stops to optimise'],
            default => null,
        };
    }

    public function trip(string $number): array
    {
        $t = $this->load($number);
        $origin = self::origin($t->warehouse);
        $stops = self::stops($t);
        $block = self::optimizeBlock($t, $origin, $stops);

        return [
            'number' => $t->number, 'status' => $t->status, 'routeAr' => $t->route_ar,
            'origin' => $origin, 'stops' => $stops,
            'unlocated' => array_values(array_map(fn ($s) => ['seq' => $s['seq'], 'customerAr' => $s['customerAr'], 'customerCode' => $s['customerCode']], array_filter($stops, fn ($s) => $s['lat'] === null))),
            'route' => $this->routeFor($origin, $stops),
            'lastFix' => self::lastFix([$t->id])[$t->id] ?? null,
            'vehicle' => self::vehiclePosition($t->vehicle),
            'optimize' => ['allowed' => $block === null, 'code' => $block[0] ?? null, 'reasonAr' => $block[1] ?? null, 'reasonEn' => $block[2] ?? null],
        ];
    }

    /** Asks the provider for the shortest round trip from the warehouse and applies it through the normal reorder rule. */
    public function optimize(AuthUser $user, string $number): array
    {
        $t = $this->load($number);
        $origin = self::origin($t->warehouse);
        $stops = self::stops($t);
        if ($block = self::optimizeBlock($t, $origin, $stops)) {
            throw AppError::rule($block[0], $block[1], $block[2]);
        }
        $result = AdapterFactory::maps()->optimizeRoute([['id' => self::ORIGIN] + $origin, ...$stops]);
        if ($result['status'] !== 'ok') {
            throw AppError::rule('MAPS_ERROR', 'تعذّر حساب المسار من مزود الخرائط — حاول مرة أخرى', 'Maps provider error: '.($result['detail'] ?? 'unknown'));
        }
        $before = array_column($stops, 'id');
        $after = array_values(array_filter($result['order'], fn ($id) => $id !== self::ORIGIN));
        $changed = $after !== $before;
        if ($changed) {
            $this->trips->reorderStops($user, $t->id, $after);
        }

        return ['changed' => $changed, 'totalKm' => $result['totalKm'] ?? null, 'totalMinutes' => $result['totalMinutes'] ?? null, 'map' => $this->trip($t->id)];
    }

    /** Control-tower / fleet map: warehouses, the open trips with their located stops, and every position that really exists. */
    public function overview(?string $warehouseCode = null): array
    {
        $whId = $warehouseCode ? Warehouse::where('code', $warehouseCode)->value('id') : null;
        $trips = Trip::with(['warehouse', 'vehicle', 'driver', 'stops' => fn ($q) => $q->orderBy('seq'), 'stops.customer'])
            ->when($whId, fn ($q) => $q->where('warehouse_id', $whId))
            ->whereNotIn('status', [...U::ENDED_TRIP_STATES, 'draft'])
            ->orderByDesc('date')->orderByDesc('id')->limit(self::OVERVIEW_TRIPS)->get();
        $fixes = self::lastFix($trips->pluck('id')->all());
        $vehicles = Vehicle::where('active', true)->when($whId, fn ($q) => $q->where('warehouse_id', $whId))->whereNotNull('lat')->whereNotNull('lng')->orderBy('code')->get();

        return [
            'warehouses' => Warehouse::where('active', true)->when($whId, fn ($q) => $q->where('id', $whId))->orderBy('code')->get()->map(fn (Warehouse $w) => self::origin($w))->all(),
            'trips' => $trips->map(fn (Trip $t) => [
                'number' => $t->number, 'status' => $t->status, 'routeAr' => $t->route_ar, 'warehouse' => $t->warehouse?->code, 'delayMin' => $t->delay_min,
                'vehicle' => $t->vehicle?->code, 'driverAr' => $t->driver?->name_ar, 'stops' => self::stops($t), 'lastFix' => $fixes[$t->id] ?? null,
            ])->all(),
            'vehicles' => $vehicles->map(fn (Vehicle $v) => self::vehiclePosition($v))->all(),
            'gps' => AdapterFactory::gps()->configured()
                ? ['status' => 'connected']
                : ['status' => Pending::STATUS, 'noteAr' => 'التتبع الحي للمركبات يتطلب ربط مزود GPS — المعروض هو مواقع المحطات وآخر موقع سجّله جوال السائق عند التسليم', 'noteEn' => 'Live vehicle tracking needs a GPS provider; shown are stop locations and the last fix from the driver phone'],
        ];
    }
}
