<?php

namespace App\Http\Controllers\Api\Transport;

use App\Models\Vehicle;
use App\Services\Transport\GpsTrackingService;
use App\Support\AppError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GpsController extends TransportBaseController
{
    public function __construct(private readonly GpsTrackingService $gps) {}

    public function status(): array
    {
        return $this->gps->status();
    }

    public function sync(): JsonResponse
    {
        return $this->created($this->gps->sync(force: true));
    }

    public function units(): array
    {
        return $this->gps->units();
    }

    public function trail(Request $request, string $code): array
    {
        $hours = (int) ($request->query('hours') ?: 12);
        $v = Vehicle::where('code', $code)->orWhere('id', $code)->first() ?? throw AppError::notFound('VEHICLE_NOT_FOUND', "المركبة {$code} غير موجودة", 'Vehicle not found');

        return [
            'vehicle' => ['code' => $v->code, 'plateAr' => $v->plate_ar, 'lat' => $v->lat, 'lng' => $v->lng, 'speedKph' => $v->speed_kph, 'course' => $v->course, 'at' => $v->gps_at, 'gpsOnline' => (bool) $v->gps_online, 'unit' => $v->gps_unit_name, 'deviceRef' => $v->gps_device_id],
            'hours' => max(1, min(168, $hours)), 'trail' => $this->gps->trail($v->id, max(1, min(168, $hours))),
        ];
    }
}
