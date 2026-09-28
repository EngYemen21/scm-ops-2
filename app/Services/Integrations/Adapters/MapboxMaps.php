<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mapbox: Directions (traffic-aware ETA + the route line), Optimization (stop ordering), Map Matching (a recorded
 * trail snapped to the streets). Coordinates only — Mapbox
 * takes `lng,lat`; places given as an address string are refused (geocode them in the client's map picker first).
 *
 * The token is a Mapbox PUBLIC token (pk.…): it is meant to be shipped to browsers and is also what draws the map in
 * the client. Restrict it by URL in the Mapbox account; a secret (sk.…) token must never be put here.
 */
class MapboxMaps implements MapsAdapter
{
    /** Directions accepts 25 coordinates per request, Optimization 12. */
    public const ROUTE_MAX = 25;

    public const OPTIMIZE_MAX = 12;

    /** Map Matching accepts 100 coordinates per request. */
    public const MATCH_MAX = 100;

    /** A matching Mapbox is less sure of than this is dropped: the recorded trace is drawn instead. */
    public const MATCH_MIN_CONFIDENCE = 0.2;

    public function __construct(private readonly string $token, private readonly int $timeoutMs = 8000) {}

    public function configured(): bool
    {
        return true;
    }

    /** @return array{ok:bool, data?:array, detail?:string} */
    private function getJson(string $url, array $query): array
    {
        return $this->send(fn () => Http::timeout($this->timeoutMs / 1000)->get($url, $query + ['access_token' => $this->token]));
    }

    /** POST form body: for long coordinate lists that would overflow a GET URL. */
    private function postForm(string $url, array $form): array
    {
        return $this->send(fn () => Http::timeout($this->timeoutMs / 1000)->asForm()->post($url.'?access_token='.urlencode($this->token), $form));
    }

    /** @param  callable():\Illuminate\Http\Client\Response  $request */
    private function send(callable $request): array
    {
        try {
            $res = $request();
            $data = (array) $res->json();
            if (! $res->successful() || ($data['code'] ?? 'Ok') !== 'Ok') {
                return ['ok' => false, 'detail' => trim(($data['code'] ?? "HTTP {$res->status()}").' '.Pending::snippet((string) ($data['message'] ?? ''), 80))];
            }

            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("mapbox request failed: {$detail}"); // URL not logged: it carries the token

            return ['ok' => false, 'detail' => $detail];
        }
    }

    /** `lng,lat` of a place, or null when it has no coordinates. */
    private static function coord(array|string $p): ?string
    {
        if (is_string($p) || ! isset($p['lat'], $p['lng']) || ! is_numeric($p['lat']) || ! is_numeric($p['lng'])) {
            return null;
        }

        return round((float) $p['lng'], 6).','.round((float) $p['lat'], 6);
    }

    public function eta(array|string $from, array|string $to): array
    {
        $r = $this->route([$from, $to], geometry: false);

        return $r['status'] === 'ok' ? ['status' => 'ok', 'minutes' => $r['minutes'], 'distanceKm' => $r['distanceKm']] : $r;
    }

    public function route(array $points, bool $geometry = true): array
    {
        $coords = array_map(self::coord(...), $points);
        if (count($coords) < 2 || in_array(null, $coords, true)) {
            return ['status' => 'error', 'detail' => 'every point needs coordinates'];
        }
        if (count($coords) > self::ROUTE_MAX) {
            return ['status' => 'error', 'detail' => 'too many points (max '.self::ROUTE_MAX.')'];
        }
        $r = $this->getJson('https://api.mapbox.com/directions/v5/mapbox/driving-traffic/'.implode(';', $coords), ['overview' => $geometry ? 'full' : 'false', 'geometries' => 'geojson']);
        if (! $r['ok']) {
            return ['status' => 'error', 'detail' => $r['detail']];
        }
        $route = $r['data']['routes'][0] ?? null;
        if (! $route) {
            return ['status' => 'error', 'detail' => 'no route'];
        }

        return [
            'status' => 'ok',
            'minutes' => (int) round(($route['duration'] ?? 0) / 60),
            'distanceKm' => round(($route['distance'] ?? 0) / 100) / 10,
            'legs' => array_map(fn ($l) => ['minutes' => (int) round(($l['duration'] ?? 0) / 60), 'distanceKm' => round(($l['distance'] ?? 0) / 100) / 10], $route['legs'] ?? []),
        ] + ($geometry ? ['geometry' => $route['geometry'] ?? null] : []);
    }

    /** First stop = start and (round trip) end; the stops in between are reordered for the shortest drive. */
    public function optimizeRoute(array $stops): array
    {
        $order = array_values(array_column($stops, 'id'));
        if (count($stops) < 3) {
            return ['status' => 'ok', 'order' => $order];
        }
        if (count($stops) > self::OPTIMIZE_MAX) {
            return ['status' => 'error', 'order' => $order, 'detail' => 'too many stops (max '.self::OPTIMIZE_MAX.')'];
        }
        $coords = array_map(self::coord(...), $stops);
        if (in_array(null, $coords, true)) {
            return ['status' => 'error', 'order' => $order, 'detail' => 'every stop needs coordinates'];
        }
        $r = $this->getJson('https://api.mapbox.com/optimized-trips/v1/mapbox/driving/'.implode(';', $coords), ['source' => 'first', 'roundtrip' => 'true', 'overview' => 'false']);
        if (! $r['ok']) {
            return ['status' => 'error', 'order' => $order, 'detail' => $r['detail']];
        }
        // waypoints come back in INPUT order, each with its position in the optimised trip
        $waypoints = $r['data']['waypoints'] ?? [];
        if (count($waypoints) !== count($stops)) {
            return ['status' => 'error', 'order' => $order, 'detail' => 'unexpected waypoints'];
        }
        $byPosition = [];
        foreach ($waypoints as $i => $w) {
            $byPosition[(int) ($w['waypoint_index'] ?? $i)] = $stops[$i]['id'];
        }
        ksort($byPosition);
        $trip = $r['data']['trips'][0] ?? [];

        return ['status' => 'ok', 'order' => array_values($byPosition), 'totalKm' => round(($trip['distance'] ?? 0) / 100) / 10, 'totalMinutes' => (int) round(($trip['duration'] ?? 0) / 60)];
    }

    /**
     * Map Matching. Each fix gets a search radius from its own accuracy (10–50 m, Mapbox's cap) and its timestamp, so
     * Mapbox can tell a U-turn from GPS noise; `tidy` drops clusters of fixes taken while standing. The driving
     * profile first; a stretch it cannot place on a road (the driver walking to a door, across a yard) is tried on the
     * walking network before giving up.
     */
    public function matchTrace(array $points): array
    {
        $r = $this->matchOn('driving', $points);

        $offRoad = $r['status'] !== 'ok' && preg_match('/^(low confidence|NoMatch)/', $r['detail'] ?? '');

        return $offRoad ? $this->matchOn('walking', $points) : $r;
    }

    private function matchOn(string $profile, array $points): array
    {
        $points = array_values($points);
        $coords = array_map(self::coord(...), $points);
        if (count($coords) < 2 || in_array(null, $coords, true)) {
            return ['status' => 'error', 'detail' => 'every point needs coordinates'];
        }
        if (count($coords) > self::MATCH_MAX) {
            return ['status' => 'error', 'detail' => 'too many points (max '.self::MATCH_MAX.')'];
        }
        $radius = fn (array $p) => (string) max(10, min(50, (int) round(2 * (float) ($p['accuracy'] ?? 12.5))));
        $r = $this->postForm("https://api.mapbox.com/matching/v5/mapbox/{$profile}", [
            'coordinates' => implode(';', $coords),
            'radiuses' => implode(';', array_map($radius, $points)),
            'timestamps' => implode(';', array_map(fn (array $p) => (string) Carbon::parse($p['at'])->getTimestamp(), $points)),
            'geometries' => 'geojson', 'overview' => 'full', 'tidy' => 'true',
        ]);
        if (! $r['ok']) {
            return ['status' => 'error', 'detail' => $r['detail']];
        }
        $lines = [];
        foreach ($r['data']['matchings'] ?? [] as $m) {
            $line = $m['geometry']['coordinates'] ?? [];
            if (($m['confidence'] ?? 0) >= self::MATCH_MIN_CONFIDENCE && count($line) > 1) {
                $lines[] = array_map(fn ($c) => [(float) $c[0], (float) $c[1]], $line);
            }
        }

        return $lines ? ['status' => 'ok', 'lines' => $lines] : ['status' => 'error', 'detail' => 'low confidence'];
    }
}
