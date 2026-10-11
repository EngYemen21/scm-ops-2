<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Cost center: warehouses, fleet, vehicles, sales, admin. Journal lines carry it for cost analysis. */
class CostCenter extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_cost_centers';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
