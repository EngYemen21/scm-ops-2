<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mapbox: Directions (traffic-aware ETA + the route line), Optimization (stop ordering). Coordinates only — Mapbox
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

    public function __construct(private readonly string $token, private readonly int $timeoutMs = 8000) {}

    public function configured(): bool
    {
        return true;
    }

    /** @return array{ok:bool, data?:array, detail?:string} */
    private function getJson(string $url, array $query): array
    {
        try {
            $res = Http::timeout($this->timeoutMs / 1000)->get($url, $query + ['access_token' => $this->token]);
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
}
