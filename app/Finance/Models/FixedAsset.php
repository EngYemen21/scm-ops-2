<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Fixed asset (vehicles first): acquisition cost, salvage value, useful life, straight-line monthly depreciation, disposal. */
class FixedAsset extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_fixed_assets';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'useful_life_months' => 'integer',
            'accumulated_depreciation' => 'decimal:2',
            'depreciated_through' => 'date',
            'disposed_on' => 'date',
            'disposal_proceeds' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function depreciation(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class, 'asset_id', 'id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id', 'id');
    }
}
