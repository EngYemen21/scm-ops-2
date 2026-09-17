<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\ProductsService;
use App\Support\AuthUser;
use Illuminate\Http\Request;

class UomsController extends MasterController
{
    public function __construct(private readonly ProductsService $service) {}

    public function index(): array
    {
        return $this->service->uoms();
    }

    public function store(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|min:1|max:16', 'nameAr' => 'required|string|min:1', 'nameEn' => 'nullable|string']);

        return $this->service->createUom(AuthUser::current(), $data);
    }

    public function update(Request $request, string $id)
    {
        $data = $request->validate(['code' => 'sometimes|string|min:1|max:16', 'nameAr' => 'sometimes|string|min:1', 'nameEn' => 'nullable|string']);

        return $this->service->updateUom(AuthUser::current(), $id, $data);
    }
}
