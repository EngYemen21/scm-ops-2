<?php

namespace App\Http\Controllers\Api\Transport;

use App\Services\Transport\TransportMapService;
use App\Support\AuthUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapController extends TransportBaseController
{
    public function __construct(private readonly TransportMapService $map) {}

    public function config(): array
    {
        return $this->map->config();
    }

    public function overview(Request $request): array
    {
        $warehouse = $request->query('warehouse');

        return $this->map->overview(is_string($warehouse) && $warehouse !== '' ? $warehouse : null);
    }

    public function trip(string $number): array
    {
        return $this->map->trip($number);
    }

    public function optimize(string $number): JsonResponse
    {
        return $this->created($this->map->optimize(AuthUser::current(), $number));
    }
}
