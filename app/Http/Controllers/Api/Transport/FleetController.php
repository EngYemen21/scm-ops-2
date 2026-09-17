<?php

namespace App\Http\Controllers\Api\Transport;

use App\Services\Transport\FleetService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** /api/transport — fleet: vehicles, drivers, maintenance, fuel, alerts, driver ops requests, routes, KPIs. */
class FleetController extends TransportBaseController
{
    private const VEHICLE_STATES = 'in:available,reserved,assigned,loading,ready,onroute,returning,atwh,maintenance,breakdown,oos,inactive';

    public function __construct(private readonly FleetService $fleet) {}

    public function kpis(): array
    {
        return $this->fleet->kpis();
    }

    // ── vehicles ──
    public function listVehicles(Request $request): array
    {
        return $this->fleet->listVehicles(Paging::from($request), $request->only(['state', 'kind', 'warehouse']));
    }

    public function getVehicle(string $code): array
    {
        return $this->fleet->getVehicle($code);
    }

    public function createVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'], 'plateAr' => ['required', 'string'], 'plateEn' => ['nullable', 'string'], 'vin' => ['required', 'string'], 'brand' => ['required', 'string'],
            'model' => ['nullable', 'string'], 'year' => ['nullable', 'integer'], 'kind' => ['nullable', 'in:dry,chill,reefer'], 'ownership' => ['nullable', 'in:owned,leased,rented,third'],
            'maxKg' => ['required', 'numeric', 'gt:0'], 'maxCbm' => ['required', 'numeric', 'gt:0'], 'pallets' => ['nullable', 'integer', 'gt:0'], 'warehouseCode' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'], 'fuelType' => ['nullable', 'string'],
            'regExpiry' => ['required', 'string', self::DATE], 'insuranceExpiry' => ['required', 'string', self::DATE], 'inspectionExpiry' => ['required', 'string', self::DATE],
            'opCardExpiry' => ['nullable', 'string', self::DATE], 'gpsDeviceId' => ['nullable', 'string'],
        ]);

        return $this->created($this->fleet->createVehicle(AuthUser::current(), $this->withDefaults($data, ['kind' => 'dry', 'ownership' => 'owned', 'pallets' => 8, 'odometer' => 0])));
    }

    public function updateVehicle(Request $request, string $code): array
    {
        $data = $request->validate([
            'plateAr' => ['sometimes', 'required', 'string'], 'plateEn' => ['sometimes', 'nullable', 'string'], 'vin' => ['sometimes', 'required', 'string'], 'brand' => ['sometimes', 'required', 'string'],
            'model' => ['sometimes', 'nullable', 'string'], 'year' => ['sometimes', 'nullable', 'integer'], 'kind' => ['sometimes', 'required', 'in:dry,chill,reefer'],
            'ownership' => ['sometimes', 'required', 'in:owned,leased,rented,third'], 'maxKg' => ['sometimes', 'required', 'numeric', 'gt:0'], 'maxCbm' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'pallets' => ['sometimes', 'required', 'integer', 'gt:0'], 'warehouseCode' => ['sometimes', 'nullable', 'string'], 'odometer' => ['sometimes', 'required', 'integer', 'min:0'],
            'fuelType' => ['sometimes', 'nullable', 'string'],
            'regExpiry' => ['sometimes', 'required', 'string', self::DATE], 'insuranceExpiry' => ['sometimes', 'required', 'string', self::DATE], 'inspectionExpiry' => ['sometimes', 'required', 'string', self::DATE],
            'opCardExpiry' => ['sometimes', 'required', 'string', self::DATE], 'gpsDeviceId' => ['sometimes', 'nullable', 'string'],
            'avgKmL' => ['sometimes', 'required', 'numeric', 'gt:0'], 'nextMaintKm' => ['sometimes', 'required', 'integer', 'min:0'], 'nextMaintDate' => ['sometimes', 'required', 'string', self::DATE],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        return $this->fleet->updateVehicle(AuthUser::current(), $code, $data);
    }

    public function setVehicleState(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['to' => ['required', self::VEHICLE_STATES], 'reason' => ['nullable', 'string']]);

        return $this->created($this->fleet->setVehicleState(AuthUser::current(), $code, $data['to'], $data['reason'] ?? null));
    }

    public function breakdown(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'vehicleCode' => ['nullable', 'string'], 'type' => ['nullable', 'in:engine,tire,elec,ac,accident,other'], 'location' => ['required', 'string'], 'desc' => ['required', 'string'],
            'canMove' => ['nullable', 'boolean'],
        ]);

        return $this->created($this->fleet->reportBreakdown(AuthUser::current(), [
            'vehicleCode' => $code, 'type' => ($data['type'] ?? null) ?: 'other', 'location' => $data['location'], 'desc' => $data['desc'],
            'canMove' => filter_var($data['canMove'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]));
    }

    // ── drivers ──
    public function listDrivers(Request $request): array
    {
        return $this->fleet->listDrivers(Paging::from($request), $request->only(['state', 'blocked', 'shift']));
    }

    public function getDriver(string $code): array
    {
        return $this->fleet->getDriver($code);
    }

    public function createDriver(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'], 'nameAr' => ['required', 'string'], 'nameEn' => ['nullable', 'string'], 'employeeNo' => ['required', 'string'], 'mobile' => ['required', 'string'],
            'nationality' => ['nullable', 'string'], 'licenseNo' => ['required', 'string'], 'licenseType' => ['nullable', 'string'],
            'licenseExpiry' => ['required', 'string', self::DATE], 'iqamaExpiry' => ['required', 'string', self::DATE], 'medicalExpiry' => ['nullable', 'string', self::DATE],
            'shift' => ['nullable', 'in:am,pm,flex'], 'defaultVehicleCode' => ['nullable', 'string'], 'joinDate' => ['nullable', 'string', self::DATE],
            'username' => ['nullable', 'string'], 'password' => ['nullable', 'string', 'min:8'],
        ]);

        return $this->created($this->fleet->createDriver(AuthUser::current(), $this->withDefaults($data, ['shift' => 'am'])));
    }

    public function updateDriver(Request $request, string $code): array
    {
        $data = $request->validate([
            'nameAr' => ['sometimes', 'required', 'string'], 'nameEn' => ['sometimes', 'nullable', 'string'], 'employeeNo' => ['sometimes', 'required', 'string'], 'mobile' => ['sometimes', 'required', 'string'],
            'nationality' => ['sometimes', 'nullable', 'string'], 'licenseNo' => ['sometimes', 'required', 'string'], 'licenseType' => ['sometimes', 'nullable', 'string'],
            'licenseExpiry' => ['sometimes', 'required', 'string', self::DATE], 'iqamaExpiry' => ['sometimes', 'required', 'string', self::DATE], 'medicalExpiry' => ['sometimes', 'nullable', 'string', self::DATE],
            'shift' => ['sometimes', 'required', 'in:am,pm,flex'], 'defaultVehicleCode' => ['sometimes', 'nullable', 'string'], 'joinDate' => ['sometimes', 'nullable', 'string', self::DATE],
            'blocked' => ['sometimes', 'required', 'boolean'], 'active' => ['sometimes', 'required', 'boolean'],
        ]);

        return $this->fleet->updateDriver(AuthUser::current(), $code, $data);
    }

    public function setDriverState(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'in:available,off,inactive'], 'reason' => ['nullable', 'string']]);

        return $this->created($this->fleet->setDriverState(AuthUser::current(), $code, $data['to'], $data['reason'] ?? null));
    }

    public function addIncident(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:speed,harsh,accident,complaint,late,noshow'], 'date' => ['nullable', 'string', self::DATE], 'desc' => ['required', 'string'], 'severity' => ['nullable', 'in:low,med,high'],
        ]);

        return $this->created($this->fleet->addIncident(AuthUser::current(), $code, $this->withDefaults($data, ['severity' => 'low'])));
    }

    // ── maintenance / fuel ──
    public function listMaintenance(Request $request): array
    {
        return $this->fleet->listMaintenance(Paging::from($request), $request->only(['vehicle', 'status', 'kind']));
    }

    public function createMaintenance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicleCode' => ['required', 'string'], 'kind' => ['nullable', 'in:preventive,corrective,emergency,tire,oil,brake,engine,elec,ac,body'], 'desc' => ['required', 'string'], 'shop' => ['required', 'string'],
            'startDate' => ['required', 'string', self::DATE], 'odometer' => ['nullable', 'integer', 'min:0'], 'cost' => ['nullable', 'numeric', 'min:0'], 'downDays' => ['nullable', 'integer', 'min:0'],
            'nextKm' => ['nullable', 'integer', 'min:0'], 'block' => ['nullable', 'boolean'],
        ]);
        $data = $this->withDefaults($data, ['kind' => 'corrective', 'cost' => 0, 'downDays' => 1, 'block' => false]);
        $data['block'] = filter_var($data['block'], FILTER_VALIDATE_BOOLEAN);

        return $this->created($this->fleet->createMaintenance(AuthUser::current(), $data));
    }

    public function closeMaintenance(Request $request, string $number): JsonResponse
    {
        $data = $request->validate([
            'endDate' => ['required', 'string', self::DATE], 'cost' => ['nullable', 'numeric', 'min:0'], 'parts' => ['nullable', 'numeric', 'min:0'], 'labor' => ['nullable', 'numeric', 'min:0'],
            'nextKm' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string'],
        ]);

        return $this->created($this->fleet->closeMaintenance(AuthUser::current(), $number, $this->withDefaults($data, ['parts' => 0, 'labor' => 0])));
    }

    public function listFuel(Request $request): array
    {
        return $this->fleet->listFuel(Paging::from($request), $request->only(['vehicle', 'driver', 'anomaly']));
    }

    public function createFuel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicleCode' => ['required', 'string'], 'driverCode' => ['nullable', 'string'], 'date' => ['required', 'string', self::DATE], 'odometer' => ['required', 'integer', 'min:0'],
            'liters' => ['required', 'numeric', 'gt:0'], 'cost' => ['required', 'numeric', 'gt:0'], 'station' => ['nullable', 'string'], 'full' => ['nullable', 'boolean'], 'receipt' => ['nullable', 'string'],
        ]);
        $data = $this->withDefaults($data, ['full' => true]);
        $data['full'] = filter_var($data['full'], FILTER_VALIDATE_BOOLEAN);

        return $this->created($this->fleet->createFuel(AuthUser::current(), $data));
    }

    // ── alerts ──
    public function listAlerts(Request $request): array
    {
        return $this->fleet->listAlerts(Paging::from($request), $request->only(['status', 'category', 'severity', 'entity']));
    }

    public function generateAlerts(): JsonResponse
    {
        return $this->created($this->fleet->refreshDocumentAlerts(AuthUser::current()));
    }

    public function resolveAlert(Request $request, string $code): JsonResponse
    {
        return $this->alertAction($request, $code, 'resolve');
    }

    public function snoozeAlert(Request $request, string $code): JsonResponse
    {
        return $this->alertAction($request, $code, 'snooze');
    }

    public function assignAlert(Request $request, string $code): JsonResponse
    {
        return $this->alertAction($request, $code, 'assign');
    }

    private function alertAction(Request $request, string $code, string $action): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string'], 'owner' => ['nullable', 'string'], 'hours' => ['nullable', 'numeric', 'gt:0']]);

        return $this->created($this->fleet->alertAction(AuthUser::current(), $code, $action, $this->withDefaults($data, ['hours' => 24])));
    }

    // ── driver ops requests ──
    public function listOpsRequests(Request $request): array
    {
        return $this->fleet->listOpsRequests(Paging::from($request), $request->only(['status', 'type', 'driver']), AuthUser::current());
    }

    public function getOpsRequest(string $number): array
    {
        return $this->fleet->getOpsRequest($number);
    }

    public function createOpsRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:fuel,maint,tire,vehicle,toll,parking,emergency,other'], 'amount' => ['nullable', 'numeric', 'min:0'], 'desc' => ['required', 'string'], 'location' => ['nullable', 'string'],
            'attachment' => ['nullable', 'string'], 'driverCode' => ['nullable', 'string'], 'vehicleCode' => ['nullable', 'string'], 'tripNumber' => ['nullable', 'string'],
        ]);
        $data = $this->withDefaults($data, ['amount' => 0]);
        // the two schema refinements of the reference: reported as input errors (400 INVALID_INPUT) with their field path
        $errors = [];
        if (in_array($data['type'], ['fuel', 'toll', 'parking'], true) && ! ((float) $data['amount'] > 0)) {
            $errors['amount'] = 'هذا النوع يحتاج مبلغًا';
        }
        if ((float) $data['amount'] > 0 && empty($data['attachment'])) {
            $errors['attachment'] = 'المبلغ يحتاج إيصالًا مرفقًا';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $this->created($this->fleet->createOpsRequest(AuthUser::current(), $data));
    }

    public function setOpsRequestStatus(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'in:review,approved,rejected,processed,closed'], 'note' => ['nullable', 'string']]);

        return $this->created($this->fleet->setOpsRequestStatus(AuthUser::current(), $number, $data['to'], $data['note'] ?? null));
    }

    // ── routes ──
    public function listRoutes(Request $request): array
    {
        return $this->fleet->listRoutes(Paging::from($request), $request->only(['warehouse', 'tempNeed']));
    }

    public function createRoute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string'], 'warehouseCode' => ['nullable', 'string'], 'zones' => ['required', 'string'], 'days' => ['nullable', 'string'], 'window' => ['nullable', 'string'],
            'tempNeed' => ['nullable', 'in:dry,chill,reefer'],
        ]);

        return $this->created($this->fleet->createRoute(AuthUser::current(), $this->withDefaults($data, ['days' => 'الأحد – الخميس', 'window' => '08:00 – 14:00', 'tempNeed' => 'dry'])));
    }

    public function updateRoute(Request $request, string $code)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string'], 'warehouseCode' => ['sometimes', 'nullable', 'string'], 'zones' => ['sometimes', 'required', 'string'], 'days' => ['sometimes', 'nullable', 'string'],
            'window' => ['sometimes', 'nullable', 'string'], 'tempNeed' => ['sometimes', 'required', 'in:dry,chill,reefer'],
        ]);

        return response()->json($this->fleet->updateRoute(AuthUser::current(), $code, $data));
    }
}
