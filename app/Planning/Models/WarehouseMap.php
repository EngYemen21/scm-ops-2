<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Which OPS warehouse a warehouse of another system (Odoo) is — said by a person, never guessed. No row, or no warehouse on it: not mapped. */
class WarehouseMap extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_warehouse_maps';
}
