<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\WarehousesService;

class ZonesController extends MasterController
{
    public function __construct(private readonly WarehousesService $service) {}

    /** Racks (and their bins) of a zone addressed by its id. */
    public function racks(string $id): array
    {
        return $this->service->racks($id);
    }
}
