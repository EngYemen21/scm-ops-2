<?php

namespace App\Http\Controllers\Api\Master;

use App\Services\Master\MasterDataService;

class MasterDataController extends MasterController
{
    public function __construct(private readonly MasterDataService $service) {}

    /** Small arrays for form selects: warehouses (+zones, dock list), categories, uoms, suppliers, customers. Any signed-in user. */
    public function lookups(): array
    {
        return $this->service->lookups();
    }
}
