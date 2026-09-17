<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\WarehousesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class WarehousesController extends MasterController
{
    private const ZONE_TYPES = 'in:ambient,chill,chilled,frozen,hazmat,quarantine,staging,damaged,returns';

    public function __construct(private readonly WarehousesService $service) {}

    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['active' => self::FLAG, 'city' => 'nullable|string', 'type' => 'nullable|string']);

        return $this->service->list(Paging::from($request), $filters);
    }

    public function show(string $code): array
    {
        return $this->service->get($code);
    }

    public function store(Request $request): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'nameAr' => 'required|string|min:1', 'city' => 'required|string|min:1', 'areaM2' => 'required|numeric|gt:0',
        ] + self::warehouseOptionalRules(), ['code.regex' => 'الرمز 3 أحرف لاتينية']);

        return $this->service->create(AuthUser::current(), self::typed($data, ['docks'], ['areaM2']));
    }

    public function update(Request $request, string $code): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:1', 'city' => 'sometimes|string|min:1', 'areaM2' => 'sometimes|numeric|gt:0', 'active' => 'sometimes|boolean',
        ] + self::warehouseOptionalRules());

        return $this->service->update(AuthUser::current(), $code, self::typed($data, ['docks'], ['areaM2'], ['active']));
    }

    // ── zones + racks ──

    public function zones(string $code): array
    {
        return $this->service->zones($code);
    }

    public function createZone(Request $request, string $code): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z]{1,2}$/'], 'nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string', 'type' => 'sometimes|'.self::ZONE_TYPES,
            'pickStrategy' => 'sometimes|in:fefo,fifo,lifo', 'aisles' => 'nullable|integer|min:1|max:99', 'racks' => 'nullable|integer|min:1|max:50', 'bins' => 'nullable|integer|min:1|max:50',
            'capacityUnits' => 'nullable|integer|min:0', 'maxKg' => 'nullable|numeric|min:0', 'minTempC' => 'nullable|numeric', 'maxTempC' => 'nullable|numeric',
        ], ['code.regex' => 'رمز المنطقة حرف أو حرفان']);

        return $this->service->createZone(AuthUser::current(), $code, self::typed($data, ['aisles', 'racks', 'bins', 'capacityUnits'], ['maxKg', 'minTempC', 'maxTempC']));
    }

    public function updateZone(Request $request, string $code, string $zone): array
    {
        $data = $request->validate([
            'nameAr' => 'sometimes|string|min:1', 'nameEn' => 'nullable|string', 'type' => 'sometimes|'.self::ZONE_TYPES, 'pickStrategy' => 'sometimes|in:fefo,fifo,lifo',
            'capacityUnits' => 'nullable|integer|min:0', 'maxKg' => 'nullable|numeric|min:0', 'minTempC' => 'nullable|numeric', 'maxTempC' => 'nullable|numeric', 'active' => 'sometimes|boolean',
        ]);

        return $this->service->updateZone(AuthUser::current(), $code, $zone, self::typed($data, ['capacityUnits'], ['maxKg', 'minTempC', 'maxTempC'], ['active']));
    }

    public function racks(string $code, string $zone): array
    {
        return $this->service->racks($zone, $code);
    }

    // ── bins scoped to a warehouse ──

    public function bins(Request $request, string $code): array
    {
        return $this->service->listBins(Paging::from($request), ['warehouse' => $code] + $this->filters($request, BinsController::LIST_RULES));
    }

    public function bin(string $code, string $bin): array
    {
        return $this->service->getBin($bin, $code);
    }

    public function createBin(Request $request, string $code): array
    {
        $rules = BinsController::CREATE_RULES;
        unset($rules['warehouseCode']);
        $data = $request->validate($rules, BinsController::MESSAGES);

        return $this->service->createBin(AuthUser::current(), ['warehouseCode' => $code] + self::typed($data, ['aisle', 'rack', 'shelf', 'capacityUnits'], ['maxKg']));
    }

    public function binStatus(Request $request, string $code, string $bin): array
    {
        $data = $request->validate(BinsController::STATUS_RULES);

        return $this->service->setBinStatus(AuthUser::current(), $bin, $data['status'], $data['note'] ?? null, $code);
    }

    // ── staff ──

    public function staff(string $code): array
    {
        return $this->service->staff($code);
    }

    public function assignStaff(Request $request, string $code): array
    {
        $data = $request->validate([
            'who' => 'required|string|min:1', 'role' => 'required|in:picker,receiver,putaway,packer,loader,counter,forklift,super,manager', 'zones' => 'sometimes|in:all,amb,cold,hz',
            'shift' => 'sometimes|in:am,pm,night', 'fromDate' => ['required', 'string', ...self::DATE], 'cert' => 'nullable|string',
        ]);

        return $this->service->assignStaff(AuthUser::current(), $code, $data + ['zones' => 'all', 'shift' => 'am']);
    }

    public function removeStaff(string $code, string $id): array
    {
        return $this->service->removeStaff(AuthUser::current(), $code, $id);
    }

    // ── dock appointments ──

    public function docks(Request $request, string $code): array
    {
        $filters = $request->validate([
            'date' => ['nullable', 'string', ...self::DATE], 'from' => ['nullable', 'string', ...self::DATE], 'to' => ['nullable', 'string', ...self::DATE], 'dock' => 'nullable|string',
        ]);

        return $this->service->docks($code, $filters);
    }

    public function bookDock(Request $request, string $code): array
    {
        $data = $request->validate([
            'dock' => 'required|string|min:1', 'type' => 'sometimes|in:in,out,ret', 'reference' => 'required|string|min:1', 'date' => ['required', 'string', ...self::DATE],
            'slot' => 'required|string|min:1', 'carrier' => 'nullable|string',
        ]);

        return $this->service->bookDock(AuthUser::current(), $code, $data + ['type' => 'in']);
    }

    public function cancelDock(string $code, string $id): array
    {
        return $this->service->cancelDock(AuthUser::current(), $code, $id);
    }

    private static function warehouseOptionalRules(): array
    {
        return [
            'nameEn' => 'nullable|string', 'type' => 'sometimes|in:dc,hub,cross,cold', 'docks' => 'nullable|integer|min:0', 'tempZones' => 'sometimes|in:all,dry,cold',
            'hours' => 'nullable|string', 'openDate' => ['nullable', 'string', ...self::DATE],
        ];
    }
}
