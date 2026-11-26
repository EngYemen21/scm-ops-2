<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a run says must be bought for a product at a warehouse in one period: how much, by when, from whom and at what price. */
class Requirement extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_requirements';

    public const UPDATED_AT = null;

    public const CRITICALITY = ['critical', 'high', 'normal', 'low'];

    /** vendor = a supplier price list · supplier_link = the price on the product's supplier link · last_purchase · cost = the product's purchase price · none */
    public const PRICE_SOURCES = ['vendor', 'supplier_link', 'last_purchase', 'cost', 'none'];

    protected function casts(): array
    {
        return [
            'period_index' => 'integer', 'period_from' => 'date', 'period_to' => 'date', 'need_date' => 'date', 'trigger_date' => 'date', 'late' => 'boolean', 'lead_days' => 'float',
            'buffer_days' => 'float', 'demand_qty' => 'float', 'opening_balance_qty' => 'float', 'projected_balance_qty' => 'float', 'safety_qty' => 'float', 'shortage_qty' => 'float',
            'net_qty' => 'float', 'moq' => 'float', 'qty_multiple' => 'float', 'qty' => 'float', 'price_unit' => 'float', 'value' => 'decimal:2', 'confidence_score' => 'float',
            'velocity_qty' => 'float', 'customer_score' => 'float', 'refs' => 'array',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
