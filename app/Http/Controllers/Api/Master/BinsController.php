<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\WarehousesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

class BinsController extends MasterController
{
    private const TYPES = 'in:shelf,pallet,flow,bulk,dock,virtual';

    public const LIST_RULES = [
        'warehouse' => 'nullable|string', 'zone' => 'nullable|string', 'status' => 'sometimes|in:active,blocked,full,inactive', 'type' => 'nullable|string',
        'rack' => 'nullable|string', 'fixed' => self::FLAG,
    ];

    public const CREATE_RULES = [
        'warehouseCode' => 'required|string|min:1', 'zone' => ['required', 'string', 'regex:/^[A-Za-z]{1,3}$/'], 'aisle' => 'required|integer|min:1|max:99',
        'rack' => 'required|integer|min:1|max:99', 'shelf' => 'required|integer|min:1|max:99', 'type' => 'sometimes|'.self::TYPES, 'capacityUnits' => 'nullable|integer|min:0',
        'maxKg' => 'nullable|numeric|min:0', 'fixedSku' => 'nullable|string',
    ];

    public const STATUS_RULES = ['status' => 'required|in:active,blocked,full,inactive', 'note' => 'nullable|string'];

    public const MESSAGES = ['zone.regex' => 'رمز المنطقة حرف أو حرفان'];

    public function __construct(private readonly WarehousesService $service) {}

    public function index(Request $request): array
    {
        return $this->service->listBins(Paging::from($request), $this->filters($request, self::LIST_RULES));
    }

    public function show(string $id): array
    {
        return $this->service->getBin($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(self::CREATE_RULES, self::MESSAGES);

        return $this->service->createBin(AuthUser::current(), self::typed($data, ['aisle', 'rack', 'shelf', 'capacityUnits'], ['maxKg']));
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['type' => 'sometimes|'.self::TYPES, 'capacityUnits' => 'nullable|integer|min:0', 'maxKg' => 'nullable|numeric|min:0', 'fixedSku' => 'nullable|string']);

        return $this->service->updateBin(AuthUser::current(), $id, self::typed($data, ['capacityUnits'], ['maxKg']));
    }

    public function status(Request $request, string $id): array
    {
        $data = $request->validate(self::STATUS_RULES);

        return $this->service->setBinStatus(AuthUser::current(), $id, $data['status'], $data['note'] ?? null);
    }
}
