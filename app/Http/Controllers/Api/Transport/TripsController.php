<?php

namespace App\Http\Controllers\Api\Transport;

use App\Services\Transport\TripsService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/transport — TMS: trips + the transport control tower. */
class TripsController extends TransportBaseController
{
    public function __construct(private readonly TripsService $trips) {}

    public function tower(Request $request): array
    {
        $warehouse = $request->query('warehouse');

        return $this->trips->tower(is_string($warehouse) && $warehouse !== '' ? $warehouse : null);
    }

    public function index(Request $request): array
    {
        return $this->trips->list(Paging::from($request), $request->only(['status', 'warehouse', 'date', 'vehicle', 'driver']));
    }

    public function show(string $number): array
    {
        return $this->trips->get($number);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouseCode' => ['required', 'string'],
            'date' => ['required', 'string', self::DATE],
            'plannedStart' => ['nullable', 'string'],
            'routeAr' => ['required', 'string'],
            'tempNeed' => ['nullable', 'in:dry,chill,reefer'],
            'foNumbers' => ['required', 'array', 'min:1'],
            'foNumbers.*' => ['nullable', 'string'],
            'vehicleCode' => ['nullable', 'string'],
            'driverCode' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'routeCode' => ['nullable', 'string'],
            'km' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->created($this->trips->create(AuthUser::current(), $data));
    }

    public function recommend(string $number): array
    {
        return $this->trips->recommendVehicles($number);
    }

    public function assign(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['vehicleCode' => ['nullable', 'string'], 'driverCode' => ['nullable', 'string']]);

        return $this->created($this->trips->assign(AuthUser::current(), $number, $data['vehicleCode'] ?? null, $data['driverCode'] ?? null));
    }

    public function unassign(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['vehicle' => ['nullable', 'boolean'], 'driver' => ['nullable', 'boolean']]);
        $flag = fn (string $key) => isset($data[$key]) ? filter_var($data[$key], FILTER_VALIDATE_BOOLEAN) : null;

        return $this->created($this->trips->unassign(AuthUser::current(), $number, $flag('vehicle'), $flag('driver')));
    }

    public function reorder(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['stopIds' => ['required', 'array', 'min:1'], 'stopIds.*' => ['required', 'string']]);

        return $this->created($this->trips->reorderStops(AuthUser::current(), $number, array_values($data['stopIds'])));
    }

    public function cancel(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);

        return $this->created($this->trips->cancel(AuthUser::current(), $number, $data['reason']));
    }

    public function close(string $number): JsonResponse
    {
        return $this->created($this->trips->close(AuthUser::current(), $number));
    }

    public function events(string $number): array
    {
        return $this->trips->events($number);
    }

    public function addEvent(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['textAr' => ['required', 'string'], 'textEn' => ['nullable', 'string'], 'label' => ['nullable', 'string']]);

        return $this->created($this->trips->addEvent(AuthUser::current(), $number, $data));
    }
}
