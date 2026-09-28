<?php

namespace App\Services\Transport;

use App\Models\Vehicle;
use App\Models\VehiclePosition;
use App\Services\Delivery\PhoneTrackingService;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\Pending;
use App\Services\Integrations\Adapters\WialonGps;
use App\Support\AppError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Live vehicle positions from the telematics provider. `sync()` copies every matched unit's last fix onto its vehicle
 * (`lat / lng / speed_kph / course / gps_at / gps_online`) and appends it to the trail. It is throttled (one provider
 * call per THROTTLE_SECONDS whatever the number of viewers) and locked (one runner at a time), so the map screens may
 * call it on every refresh — that is what keeps tracking live on hosts without a scheduler. Everything shown is what the
 * provider reported; an unmatched vehicle is listed, never placed.
 */
class GpsTrackingService
{
    public const THROTTLE_SECONDS = 30;

    /** A fix older than this is "offline" (the marker stays, greyed, with its time). */
    public const ONLINE_MINUTES = 10;

    public const TRAIL_KEEP_DAYS = 7;

    /** A fix within this distance of the previous one (and within TRAIL_MIN_SECONDS) is standing-still noise. */
    public const TRAIL_MIN_METRES = 10;

    private const TRAIL_MIN_SECONDS = 300;

    /** A parked vehicle's fix must move this far to be drawn (parked GPS drift). */
    public const PARKED_DRIFT_METRES = 150;

    /** History is asked from the provider in spans of this many hours, at most this many requests per call. */
    private const HISTORY_CHUNK_HOURS = 6;

    private const HISTORY_CALLS_PER_LOAD = 3;

    /** The newest history is not asked again sooner than this. */
    private const HISTORY_MIN_SECONDS = 45;

    private const LAST_KEY = 'gps:last-sync';

    public function configured(): bool
    {
        return AdapterFactory::gps()->configured();
    }

    /** What the last sync did — for the status screens. */
    public function last(): ?array
    {
        return Cache::get(self::LAST_KEY);
    }

    public function status(): array
    {
        $gps = AdapterFactory::gps();
        $last = $this->last();
        $paired = Vehicle::where('active', true)->whereNotNull('gps_device_id')->where('gps_device_id', '!=', '')->count();

        return [
            'provider' => $gps instanceof WialonGps ? 'wialon' : ($gps->configured() ? 'http' : null),
            'configured' => $gps->configured(), 'status' => $gps->configured() ? ($last && ! $last['ok'] ? 'error' : 'connected') : Pending::STATUS,
            'lastSync' => $last, 'pairedVehicles' => $paired, 'onlineVehicles' => Vehicle::where('active', true)->where('gps_online', true)->count(),
            'throttleSeconds' => self::THROTTLE_SECONDS, 'onlineMinutes' => self::ONLINE_MINUTES,
        ];
    }

    /**
     * @return array{ran:bool, ok?:bool, detail?:string, units?:int, matched?:int, updated?:int, unmatched?:list<string>, at?:string}
     */
    public function sync(bool $force = false): array
    {
        $gps = AdapterFactory::gps();
        if (! $gps->configured()) {
            return ['ran' => false, 'ok' => false, 'detail' => Pending::DETAIL_EN];
        }
        if (! $gps instanceof WialonGps) {
            return ['ran' => false, 'ok' => false, 'detail' => 'bulk sync is implemented for Wialon only'];
        }
        $last = $this->last();
        if (! $force && $last && isset($last['at']) && now()->diffInSeconds($last['at'], true) < self::THROTTLE_SECONDS) {
            return ['ran' => false] + $last;
        }
        $lock = Cache::lock('gps:sync', 20);
        if (! $lock->get()) {
            return ['ran' => false] + ($last ?? ['ok' => false, 'detail' => 'sync in progress']);
        }
        try {
            $r = $gps->units();
            if ($r['status'] !== 'ok') {
                $out = ['ran' => true, 'ok' => false, 'detail' => $r['detail'], 'at' => now()->toIso8601ZuluString('millisecond')];
                Cache::put(self::LAST_KEY, $out, now()->addDay());

                return $out;
            }
            $out = $this->apply($r['units']);
            Cache::put(self::LAST_KEY, $out, now()->addDay());

            return $out;
        } finally {
            $lock->release();
        }
    }

    /** Writes the provider's units onto the vehicles. Separate from the fetch so tests and imports can feed it directly. */
    public function apply(array $units): array
    {
        $vehicles = Vehicle::where('active', true)->whereNotNull('gps_device_id')->where('gps_device_id', '!=', '')->get();
        $matched = 0;
        $updated = 0;
        $unmatched = [];
        $onlineAfter = now()->subMinutes(self::ONLINE_MINUTES);
        DB::transaction(function () use ($vehicles, $units, &$matched, &$updated, &$unmatched, $onlineAfter) {
            foreach ($vehicles as $v) {
                $u = WialonGps::match($units, $v->gps_device_id);
                if (! $u) {
                    $unmatched[] = $v->code;
                    $v->update(['gps_online' => false, 'gps_unit_name' => null]);

                    continue;
                }
                $matched++;
                $at = $u['at'] ? CarbonImmutable::parse($u['at']) : null;
                $has = $u['lat'] !== null && $u['lng'] !== null && $at;
                $data = ['gps_unit_name' => $u['name'], 'gps_online' => $has && $at->greaterThan($onlineAfter), 'last_sync_at' => now()];
                if (($u['id'] ?? '') !== '' && (string) $u['id'] !== $v->gps_unit_id) { // another unit now: its history is copied afresh
                    $data += ['gps_unit_id' => (string) $u['id'], 'gps_history_from' => null, 'gps_history_until' => null];
                }
                if ($has && ($v->gps_at === null || $at->greaterThan($v->gps_at))) {
                    $data += ['lat' => $u['lat'], 'lng' => $u['lng'], 'speed_kph' => $u['speedKph'], 'course' => $u['course'], 'gps_at' => $at];
                    $this->store($v->id, [['lat' => $u['lat'], 'lng' => $u['lng'], 'speedKph' => $u['speedKph'], 'course' => $u['course'], 'at' => $at]]);
                    $updated++;
                }
                $v->update($data);
            }
        });
        VehiclePosition::where('at', '<', now()->subDays(self::TRAIL_KEEP_DAYS))->delete();

        return ['ran' => true, 'ok' => true, 'units' => count($units), 'matched' => $matched, 'updated' => $updated, 'unmatched' => $unmatched, 'at' => now()->toIso8601ZuluString('millisecond')];
    }

    /** The provider's units with the vehicle each one is paired to — for the pairing screen. */
    public function units(): array
    {
        $gps = AdapterFactory::gps();
        if (! $gps instanceof WialonGps) {
            throw AppError::rule('GPS_PENDING', 'مزود التتبع غير مربوط بعد', 'GPS provider is not configured');
        }
        $r = $gps->units();
        if ($r['status'] !== 'ok') {
            throw AppError::rule('GPS_ERROR', 'تعذّر الاتصال بمزود التتبع — '.$r['detail'], 'GPS provider error: '.$r['detail']);
        }
        $vehicles = Vehicle::whereNotNull('gps_device_id')->where('gps_device_id', '!=', '')->get(['code', 'gps_device_id']);
        $units = array_map(function ($u) use ($vehicles) {
            $paired = $vehicles->first(fn ($v) => WialonGps::match([$u], $v->gps_device_id) !== null);

            return $u + ['vehicle' => $paired?->code];
        }, $r['units']);

        return ['units' => $units, 'count' => count($units)];
    }

    /**
     * The vehicle's track for the last $hours (from the start of that clock hour, so the snapped line's per-hour pieces
     * stay cached while the window slides), oldest first. The provider's recorded history is copied in first, so the
     * track holds every fix the device sent — not only the ones a map refresh happened to sample.
     *
     * @return list<array{lat:float, lng:float, speedKph:?float, at:mixed}>
     */
    public function trail(string $vehicleId, int $hours = 12): array
    {
        $since = CarbonImmutable::now()->subHours($hours)->startOfHour();
        if ($v = Vehicle::find($vehicleId)) {
            $this->backfill($v, $since);
        }
        $rows = VehiclePosition::where('vehicle_id', $vehicleId)->where('at', '>=', $since)->orderBy('at')->limit(20000)
            ->get()->map(fn ($p) => ['lat' => $p->lat, 'lng' => $p->lng, 'speedKph' => $p->speed_kph, 'at' => $p->at])->all();

        return TraceMatcher::declutter($rows, self::TRAIL_MIN_METRES, self::PARKED_DRIFT_METRES);
    }

    /**
     * Copies the provider's recorded track into the trail for [$since, now] — only the spans not copied yet (newer than
     * `gps_history_until` first, then older than `gps_history_from`), in HISTORY_CHUNK_HOURS requests, at most
     * HISTORY_CALLS_PER_LOAD per call: a long window fills in over a few refreshes. Wialon only; a no-op otherwise.
     */
    public function backfill(Vehicle $v, CarbonImmutable $since): void
    {
        $gps = AdapterFactory::gps();
        if (! $gps instanceof WialonGps || ! $v->gps_unit_id) {
            return;
        }
        $lock = Cache::lock("gps:history:{$v->id}", 30);
        if (! $lock->get()) {
            return; // another request is copying it
        }
        try {
            $now = CarbonImmutable::now();
            $from = $v->gps_history_from ? CarbonImmutable::parse($v->gps_history_from) : null;
            $until = $v->gps_history_until ? CarbonImmutable::parse($v->gps_history_until) : null;
            if (! $from || ! $until || $until->lt($since)) { // nothing usable yet: start from now and go back
                $from = $until = $now;
            }
            $calls = self::HISTORY_CALLS_PER_LOAD;
            // newest first: the live end of the line matters most
            while ($calls > 0 && $until->diffInSeconds($now) > self::HISTORY_MIN_SECONDS) {
                $calls--;
                $to = $until->addHours(self::HISTORY_CHUNK_HOURS)->min($now);
                $r = $gps->track($v->gps_unit_id, $until->getTimestamp(), $to->getTimestamp());
                if ($r['status'] !== 'ok') {
                    break;
                }
                $this->store($v->id, $r['points']);
                $until = $r['more'] && $r['points'] ? CarbonImmutable::parse(end($r['points'])['at']) : $to;
            }
            while ($calls > 0 && $from->gt($since)) {
                $calls--;
                $start = $from->subHours(self::HISTORY_CHUNK_HOURS)->max($since);
                $r = $gps->track($v->gps_unit_id, $start->getTimestamp(), $from->getTimestamp());
                if ($r['status'] !== 'ok') {
                    break;
                }
                $this->store($v->id, $r['points']);
                $from = $start; // a chunk is never fuller than the provider's cap at a fix every few seconds
            }
            $v->update(['gps_history_from' => $from, 'gps_history_until' => $until]);
        } finally {
            $lock->release();
        }
    }

    /** Appends fixes to the trail, once per (vehicle, time), skipping standing-still repeats within a batch. */
    private function store(string $vehicleId, array $points): void
    {
        $rows = [];
        $prev = null;
        foreach ($points as $p) {
            $at = CarbonImmutable::parse($p['at'])->utc();
            if ($prev && $at->getTimestamp() - $prev['t'] < self::TRAIL_MIN_SECONDS
                && PhoneTrackingService::metresBetween($prev['lat'], $prev['lng'], (float) $p['lat'], (float) $p['lng']) < self::TRAIL_MIN_METRES) {
                continue;
            }
            $rows[] = ['id' => (string) Str::ulid(), 'vehicle_id' => $vehicleId, 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
                'speed_kph' => $p['speedKph'] ?? null, 'course' => $p['course'] ?? null, 'at' => $at->format('Y-m-d H:i:s.v')];
            $prev = ['t' => $at->getTimestamp(), 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            VehiclePosition::query()->insertOrIgnore($chunk);
        }
    }
}
