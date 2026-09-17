<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Google Maps Platform: Distance Matrix for ETA, Directions (optimize:true) for stop ordering. */
class GoogleMaps implements MapsAdapter
{
    public function __construct(private readonly string $apiKey, private readonly int $timeoutMs = 8000) {}

    public function configured(): bool
    {
        return true;
    }

    /** @return array{ok:bool, data?:array, detail?:string} */
    private function getJson(string $url, array $query): array
    {
        try {
            $res = Http::timeout($this->timeoutMs / 1000)->get($url, $query + ['key' => $this->apiKey]);
            if (! $res->successful()) {
                return ['ok' => false, 'detail' => "HTTP {$res->status()}"];
            }
            $data = (array) $res->json();
            if (($data['status'] ?? 'OK') !== 'OK') {
                return ['ok' => false, 'detail' => trim($data['status'].' '.Pending::snippet($data['error_message'] ?? '', 80))];
            }

            return ['ok' => true, 'data' => $data];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("maps request failed: {$detail}"); // URL not logged: it carries the API key

            return ['ok' => false, 'detail' => $detail];
        }
    }

    private static function place(array|string $p): string
    {
        return is_string($p) ? $p : "{$p['lat']},{$p['lng']}";
    }

    private static function stopPlace(array $s): string
    {
        return isset($s['lat'], $s['lng']) ? "{$s['lat']},{$s['lng']}" : (string) ($s['address'] ?? '');
    }

    public function eta(array|string $from, array|string $to): array
    {
        $r = $this->getJson('https://maps.googleapis.com/maps/api/distancematrix/json', ['origins' => self::place($from), 'destinations' => self::place($to), 'departure_time' => 'now']);
        if (! $r['ok']) {
            return ['status' => 'error', 'detail' => $r['detail']];
        }
        $el = $r['data']['rows'][0]['elements'][0] ?? null;
        if (! $el || ($el['status'] ?? null) !== 'OK') {
            return ['status' => 'error', 'detail' => $el['status'] ?? 'no route'];
        }
        $seconds = $el['duration_in_traffic']['value'] ?? $el['duration']['value'] ?? null;
        if ($seconds === null) {
            return ['status' => 'error', 'detail' => 'no duration'];
        }

        return ['status' => 'ok', 'minutes' => (int) round($seconds / 60), 'distanceKm' => round(($el['distance']['value'] ?? 0) / 100) / 10];
    }

    public function optimizeRoute(array $stops): array
    {
        $stops = array_values($stops);
        $order = array_column($stops, 'id');
        if (count($stops) < 3) {
            return ['status' => 'ok', 'order' => $order];
        }
        foreach ($stops as $s) {
            if (self::stopPlace($s) === '') {
                return ['status' => 'error', 'order' => $order, 'detail' => 'every stop needs coordinates or an address'];
            }
        }
        $middle = array_slice($stops, 1, -1);
        $r = $this->getJson('https://maps.googleapis.com/maps/api/directions/json', [
            'origin' => self::stopPlace($stops[0]), 'destination' => self::stopPlace($stops[count($stops) - 1]),
            'waypoints' => 'optimize:true|'.implode('|', array_map(self::stopPlace(...), $middle)),
        ]);
        if (! $r['ok']) {
            return ['status' => 'error', 'order' => $order, 'detail' => $r['detail']];
        }
        $route = $r['data']['routes'][0] ?? [];
        $wpOrder = $route['waypoint_order'] ?? [];
        if (count($wpOrder) !== count($middle)) {
            return ['status' => 'error', 'order' => $order, 'detail' => 'unexpected waypoint_order'];
        }
        $legs = $route['legs'] ?? [];
        $meters = array_sum(array_map(fn ($l) => $l['distance']['value'] ?? 0, $legs));
        $seconds = array_sum(array_map(fn ($l) => $l['duration']['value'] ?? 0, $legs));

        return [
            'status' => 'ok',
            'order' => [$stops[0]['id'], ...array_map(fn ($i) => $middle[$i]['id'], $wpOrder), $stops[count($stops) - 1]['id']],
            'totalKm' => round($meters / 100) / 10, 'totalMinutes' => (int) round($seconds / 60),
        ];
    }
}
