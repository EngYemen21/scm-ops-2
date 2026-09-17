<?php

namespace App\Services\Integrations\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generic JSON telematics client: GET {baseUrl}/vehicles/{code}/position and /temperature.
 * Accepts the common field spellings (lat/latitude, lng/lon/longitude, speed/speedKph, temp/temperature/celsius).
 * Anything it cannot understand is reported as `error`, never guessed.
 */
class HttpGps implements GpsAdapter
{
    public function __construct(private readonly string $baseUrl, private readonly ?string $token = null, private readonly int $timeoutMs = 8000) {}

    public function configured(): bool
    {
        return true;
    }

    /** @return array{ok:bool, data?:mixed, detail?:string} */
    private function getJson(string $path): array
    {
        try {
            $request = Http::timeout($this->timeoutMs / 1000)->acceptJson();
            if ($this->token) {
                $request = $request->withToken($this->token);
            }
            $res = $request->get(rtrim($this->baseUrl, '/').$path);
            if (! $res->successful()) {
                return ['ok' => false, 'detail' => trim("HTTP {$res->status()} ".Pending::snippet($res->body(), 80))];
            }
            $data = json_decode($res->body(), true);

            return json_last_error() === JSON_ERROR_NONE ? ['ok' => true, 'data' => $data] : ['ok' => false, 'detail' => 'non-JSON response'];
        } catch (Throwable $e) {
            $detail = Pending::describeError($e);
            Log::warning("GPS request {$path} failed: {$detail}");

            return ['ok' => false, 'detail' => $detail];
        }
    }

    private static function number(mixed $v): ?float
    {
        return is_int($v) || is_float($v) || (is_string($v) && trim($v) !== '' && is_numeric($v)) ? (float) $v : null;
    }

    public function getVehiclePosition(string $vehicleCode): array
    {
        $r = $this->getJson('/vehicles/'.rawurlencode($vehicleCode).'/position');
        if (! $r['ok']) {
            return ['status' => 'error', 'vehicleCode' => $vehicleCode, 'detail' => $r['detail']];
        }
        $data = is_array($r['data']) ? $r['data'] : [];
        $d = $data['position'] ?? $data['data'] ?? $data;
        $d = is_array($d) ? $d : [];
        $lat = self::number($d['lat'] ?? $d['latitude'] ?? null);
        $lng = self::number($d['lng'] ?? $d['lon'] ?? $d['longitude'] ?? null);
        if ($lat === null || $lng === null) {
            return ['status' => 'error', 'vehicleCode' => $vehicleCode, 'detail' => 'position missing lat/lng'];
        }
        $at = $d['at'] ?? $d['timestamp'] ?? null;

        return ['status' => 'ok', 'vehicleCode' => $vehicleCode, 'lat' => $lat, 'lng' => $lng, 'speedKph' => self::number($d['speedKph'] ?? $d['speed'] ?? null), 'at' => is_string($at) ? $at : now()->toIso8601ZuluString('millisecond')];
    }

    public function getVehicleTemperature(string $vehicleCode): array
    {
        $r = $this->getJson('/vehicles/'.rawurlencode($vehicleCode).'/temperature');
        if (! $r['ok']) {
            return ['status' => 'error', 'vehicleCode' => $vehicleCode, 'detail' => $r['detail']];
        }
        $data = is_array($r['data']) ? $r['data'] : [];
        $d = $data['temperature'] ?? $data['data'] ?? $data;
        $celsius = self::number(is_array($d) ? ($d['celsius'] ?? $d['temp'] ?? $d['temperature'] ?? $d['value'] ?? null) : $d);
        if ($celsius === null) {
            return ['status' => 'error', 'vehicleCode' => $vehicleCode, 'detail' => 'temperature missing'];
        }

        return ['status' => 'ok', 'vehicleCode' => $vehicleCode, 'celsius' => $celsius, 'at' => is_array($d) && is_string($d['at'] ?? null) ? $d['at'] : now()->toIso8601ZuluString('millisecond')];
    }
}
