<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A budget scenario of a run: money per month, the weights of the priority and the rules of the allocation, and what the last allocation gave. */
class Budget extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_budgets';

    /** The weight as the API names it → its column. */
    public const WEIGHTS = ['urgency' => 'weight_urgency', 'stockout' => 'weight_stockout', 'criticality' => 'weight_criticality', 'velocity' => 'weight_velocity',
        'confidence' => 'weight_confidence', 'customer' => 'weight_customer', 'leadTime' => 'weight_lead_time'];

    /** What a new scenario weighs with until a person says otherwise. */
    public const DEFAULT_WEIGHTS = ['urgency' => 30, 'stockout' => 25, 'criticality' => 20, 'velocity' => 10, 'confidence' => 5, 'customer' => 5, 'leadTime' => 5];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean', 'allocated_at' => 'datetime', 'weight_urgency' => 'integer', 'weight_stockout' => 'integer', 'weight_criticality' => 'integer', 'weight_velocity' => 'integer',
            'weight_confidence' => 'integer', 'weight_customer' => 'integer', 'weight_lead_time' => 'integer', 'critical_first' => 'boolean', 'allow_partial' => 'boolean',
            'carry_deferred' => 'boolean', 'carry_unused' => 'boolean', 'urgency_horizon_days' => 'integer', 'budget_total' => 'decimal:2', 'required_value' => 'decimal:2',
            'funded_value' => 'decimal:2', 'deferred_value' => 'decimal:2', 'funded_pct' => 'float', 'unused_value' => 'decimal:2', 'stockout_unfunded_value' => 'decimal:2',
            'requirement_count' => 'integer', 'funded_count' => 'integer', 'partial_count' => 'integer', 'deferred_count' => 'integer', 'critical_deferred_count' => 'integer',
            'at_risk_count' => 'integer', 'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id', 'id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class, 'budget_id', 'id');
    }
}
