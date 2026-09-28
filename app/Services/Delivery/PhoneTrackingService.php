<?php

namespace App\Services\Delivery;

use App\Models\Driver;
use App\Models\DriverPosition;
use App\Models\Trip;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Driver phone tracking. The native driver app sends its fixes in batches (queued on the phone while offline); the
 * server keeps them only while the driver has an ACTIVE trip and has accepted tracking — outside a trip nothing is
 * stored and the answer tells the phone to stop. Each fix is tied to the trip and its vehicle, so the phone trail sits
 * next to the truck's GPS trail and the two can be compared.
 */
class PhoneTrackingService
{
    /** Trip states during which the driver's phone is tracked: from dispatch until the truck is back. */
    public const TRACKED_TRIP_STATES = ['dispatched', 'onroute', 'partial', 'completed', 'returning'];

    /** What the phone is told to do (seconds between reports when moving, metres between fixes). */
    public const REPORT_INTERVAL_SECONDS = 30;

    /** Dense enough that a turn shows as a turn (50 m drew corners as straight chords), sparse enough for the battery. */
    public const DISTANCE_FILTER_METRES = 25;

    /** A fix less precise than this is stored (last position, audit) but left off the drawn trail. */
    public const TRAIL_MAX_ACCURACY_METRES = 50;

    /** The trail sent to a browser is thinned to this many points (the snapped line is built from all of them). */
    public const TRAIL_MAX_POINTS = 3000;

    /** A tracked trip whose last phone fix is older than this is flagged "phone tracking stopped". */
    public const STALE_MINUTES = 10;

    /** Phone and truck further apart than this (both fixes fresh) are flagged. */
    public const APART_METRES = 1000;

    public const MAX_BATCH = 500;

    public const KEEP_DAYS = 30;

    public function __construct(private readonly AuditService $audit) {}

    private function driverFor(AuthUser $user): Driver
    {
        return ($user->driverId ? Driver::find($user->driverId) : null)
            ?? throw AppError::forbidden('NOT_A_DRIVER', 'الحساب غير مرتبط بسائق', 'User is not linked to a driver');
    }

    private function activeTrip(Driver $d): ?Trip
    {
        return Trip::where('driver_id', $d->id)->whereIn('status', self::TRACKED_TRIP_STATES)->orderByDesc('date')->orderByDesc('id')->first();
    }

    /** What the phone should be doing now. Polled by the app on start, on resume and every minute. */
    public function state(AuthUser $user): array
    {
        $d = $this->driverFor($user);
        $trip = $this->activeTrip($d);

        return [
            'tracking' => (bool) $trip && $d->phone_consent_at !== null,
            'needsConsent' => (bool) $trip && $d->phone_consent_at === null,
            'consentedAt' => $d->phone_consent_at,
            'trip' => $trip ? ['number' => $trip->number, 'status' => $trip->status] : null,
            'intervalSeconds' => self::REPORT_INTERVAL_SECONDS,
            'distanceFilterMetres' => self::DISTANCE_FILTER_METRES,
            'lastFixAt' => $d->phone_at,
        ];
    }

    public function consent(AuthUser $user, bool $accepted): array
    {
        $d = $this->driverFor($user);
        $before = $d->phone_consent_at;
        $d->update(['phone_consent_at' => $accepted ? now() : null]);
        $this->audit->log($user, ['action' => $accepted ? 'DRIVER.PHONE_TRACKING_CONSENT' : 'DRIVER.PHONE_TRACKING_WITHDRAWN', 'entityType' => 'Driver', 'entityId' => $d->id, 'entityNumber' => $d->code,
            'oldValue' => ['consentedAt' => $before], 'newValue' => ['consentedAt' => $d->phone_consent_at]]);

        return $this->state($user);
    }

    /**
     * @param  list<array{lat:mixed, lng:mixed, accuracy?:mixed, speed?:mixed, heading?:mixed, at:mixed}>  $points  speed in m/s as phones report it
     * @return array{accepted:int, rejected:int, tracking:bool}
     */
    public function ingest(AuthUser $user, array $points, ?string $platform = null): array
    {
        $d = $this->driverFor($user);
        if ($d->phone_consent_at === null) {
            throw AppError::rule('TRACKING_NOT_CONSENTED', 'لم يوافق السائق على تتبع الجوال بعد', 'Driver has not accepted phone tracking');
        }
        $trip = $this->activeTrip($d);
        if (! $trip) {
            return ['accepted' => 0, 'rejected' => count($points), 'tracking' => false]; // no trip: nothing kept, the phone stops
        }
        $now = CarbonImmutable::now();
        $rows = [];
        $rejected = 0;
        foreach (array_slice($points, 0, self::MAX_BATCH) as $p) {
            $at = null;
            try {
                $at = is_numeric($p['at'] ?? null) ? CarbonImmutable::createFromTimestampMs((int) $p['at']) : CarbonImmutable::parse((string) ($p['at'] ?? ''));
            } catch (\Throwable) {
            }
            $lat = $p['lat'] ?? null;
            $lng = $p['lng'] ?? null;
            $acc = isset($p['accuracy']) && is_numeric($p['accuracy']) ? (float) $p['accuracy'] : null;
            // a fix must be real, recent (≤ 24 h), not from the future and not wildly inaccurate
            if (! is_numeric($lat) || ! is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180 || ($lat == 0 && $lng == 0)
                || ! $at || $at->lt($now->subDay()) || $at->gt($now->addMinutes(5)) || ($acc !== null && $acc > 1000)) {
                $rejected++;

                continue;
            }
            $speed = isset($p['speed']) && is_numeric($p['speed']) && $p['speed'] >= 0 ? round((float) $p['speed'] * 3.6, 1) : null;
            $rows[$at->format('Y-m-d H:i:s.v')] = [
                'id' => (string) Str::ulid(), 'driver_id' => $d->id, 'trip_id' => $trip->id, 'vehicle_id' => $trip->vehicle_id,
                'lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'accuracy' => $acc, 'speed_kph' => $speed,
                'heading' => isset($p['heading']) && is_numeric($p['heading']) ? ((int) round((float) $p['heading'])) % 360 : null,
                'at' => $at->utc()->format('Y-m-d H:i:s.v'),
            ];
        }
        ksort($rows);
        $accepted = 0;
        DB::transaction(function () use ($d, $trip, $rows, $platform, &$accepted) {
            foreach (array_chunk(array_values($rows), 200) as $chunk) {
                $accepted += DriverPosition::query()->insertOrIgnore($chunk); // (driver, at) unique: a replayed batch adds nothing
            }
            $last = end($rows);
            if ($last && ($d->phone_at === null || CarbonImmutable::parse($last['at'])->greaterThan($d->phone_at))) {
                $d->update(['phone_lat' => $last['lat'], 'phone_lng' => $last['lng'], 'phone_accuracy' => $last['accuracy'], 'phone_speed_kph' => $last['speed_kph'],
                    'phone_at' => $last['at'], 'phone_trip_id' => $trip->id, 'phone_platform' => $platform ?: $d->phone_platform]);
            }
        });
        if (random_int(1, 50) === 1) {
            DriverPosition::where('at', '<', $now->subDays(self::KEEP_DAYS))->delete();
        }

        return ['accepted' => $accepted, 'rejected' => $rejected + (count($rows) - $accepted), 'tracking' => true];
    }

    // ───────────────────────────── read side (maps) ─────────────────────────────

    public static function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    /** The driver's phone on a tracked trip, compared with the truck: fresh / stale, distance apart. */
    public function phoneOf(Trip $t): ?array
    {
        $d = $t->driver;
        if (! $d || ! in_array($t->status, self::TRACKED_TRIP_STATES, true)) {
            return null;
        }
        $has = $d->phone_at && $d->phone_trip_id === $t->id && $d->phone_lat !== null;
        $fresh = $has && $d->phone_at->greaterThan(now()->subMinutes(self::STALE_MINUTES));
        $v = $t->vehicle;
        $apart = null;
        if ($has && $v && $v->lat !== null && $v->gps_at && $v->gps_at->greaterThan(now()->subMinutes(self::STALE_MINUTES)) && $fresh) {
            $apart = self::metresBetween($d->phone_lat, $d->phone_lng, $v->lat, $v->lng);
        }

        return [
            'driverCode' => $d->code, 'driverAr' => $d->name_ar, 'consented' => $d->phone_consent_at !== null,
            'lat' => $has ? $d->phone_lat : null, 'lng' => $has ? $d->phone_lng : null, 'accuracy' => $has ? $d->phone_accuracy : null,
            'speedKph' => $has ? $d->phone_speed_kph : null, 'at' => $has ? $d->phone_at : null, 'platform' => $d->phone_platform,
            'status' => ! $d->phone_consent_at ? 'no_consent' : (! $has ? 'waiting' : ($fresh ? 'live' : 'stale')),
            'metresFromTruck' => $apart, 'apart' => $apart !== null && $apart > self::APART_METRES,
        ];
    }

    /** @return list<array{lat:float, lng:float, speedKph:?float, at:mixed}> oldest first */
    /**
     * The phone's path on a trip, oldest first, cleaned for drawing: imprecise fixes are dropped, and so is a fix that
     * lies within the GPS error of the previous one (standing still makes the reported position wander a few metres,
     * which drew as spikes). The latest fix is always kept so the line reaches the phone marker.
     *
     * @return list<array{lat:float, lng:float, accuracy:float|null, speedKph:float|null, at:mixed}>
     */
    public function trail(string $tripId, ?string $driverId = null): array
    {
        $rows = DriverPosition::where('trip_id', $tripId)->when($driverId, fn ($q) => $q->where('driver_id', $driverId))
            ->orderBy('at')->limit(20000)->get(['lat', 'lng', 'accuracy', 'speed_kph', 'at']);
        $out = [];
        $last = $rows->count() - 1;
        foreach ($rows->values() as $i => $p) {
            if ($p->accuracy !== null && $p->accuracy > self::TRAIL_MAX_ACCURACY_METRES) {
                continue;
            }
            $prev = $out ? $out[count($out) - 1] : null;
            if ($prev) {
                $noise = max(5.0, (float) ($p->accuracy ?? 10), (float) ($prev['accuracy'] ?? 10));
                $tooClose = self::metresBetween($prev['lat'], $prev['lng'], (float) $p->lat, (float) $p->lng) < $noise;
                if ($p->at->getTimestamp() <= $prev['at']->getTimestamp() || ($tooClose && $i !== $last)) {
                    continue;
                }
                if ($tooClose) { // the latest fix, but still inside the noise: move the end of the line there
                    array_pop($out);
                }
            }
            $out[] = ['lat' => (float) $p->lat, 'lng' => (float) $p->lng, 'accuracy' => $p->accuracy, 'speedKph' => $p->speed_kph, 'at' => $p->at];
        }

        return $out;
    }
}
