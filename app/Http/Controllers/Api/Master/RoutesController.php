<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\WarehousesService;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Http\Request;

/** Delivery routes (master data used when trips are created). */
class RoutesController extends MasterController
{
    public function __construct(private readonly WarehousesService $service) {}

    public function index(Request $request): array
    {
        $filters = $this->filters($request, ['warehouse' => 'nullable|string', 'tempNeed' => 'nullable|string']);

        return $this->service->listRoutes(Paging::from($request), $filters);
    }

    public function show(string $id): array
    {
        return $this->service->getRoute($id);
    }

    public function store(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|min:1', 'zones' => 'required|string|min:1'] + self::optionalRules());

        return $this->service->createRoute(AuthUser::current(), $data);
    }

    public function update(Request $request, string $id): array
    {
        $data = $request->validate(['name' => 'sometimes|string|min:1', 'zones' => 'sometimes|string|min:1'] + self::optionalRules());

        return $this->service->updateRoute(AuthUser::current(), $id, $data);
    }

    public function destroy(string $id): array
    {
        return $this->service->deleteRoute(AuthUser::current(), $id);
    }

    private static function optionalRules(): array
    {
        return ['warehouseCode' => 'nullable|string', 'days' => 'nullable|string', 'window' => 'nullable|string', 'tempNeed' => 'sometimes|in:dry,chill,reefer'];
    }
}
