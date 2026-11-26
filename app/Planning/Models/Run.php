<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A forecast run: its parameters, where its computation stands and its totals. `status` is its state machine
 * (draft → computing → computed → frozen); what it computed lives in the tables that carry its id.
 */
class Run extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_runs';

    public const STATUSES = ['draft', 'computing', 'computed', 'frozen'];

    public const METHODS = ['average', 'wma', 'seasonal', 'cadence'];

    /** The lists a run is scoped by, kept as arrays of OPS ids. */
    public const SCOPES = ['product_ids', 'category_ids', 'warehouse_ids', 'customer_ids', 'exclude_customer_ids'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean', 'anchor_date' => 'date', 'history_from' => 'date', 'history_to' => 'date', 'forecast_from' => 'date', 'forecast_to' => 'date',
            'wma_window' => 'integer', 'seasonal_scale' => 'boolean', 'growth_pct' => 'float', 'include_returns' => 'boolean', 'include_odoo' => 'boolean', 'include_ops' => 'boolean', 'lease_until' => 'datetime', 'safety_days' => 'float', 'target_cover_days' => 'float',
            'use_vendor_lead_time' => 'boolean', 'default_lead_days' => 'float', 'planning_buffer_days' => 'float', 'include_rollup' => 'boolean', 'include_history_sheet' => 'boolean',
            'cadence_min_orders' => 'integer', 'cadence_interval_window' => 'integer', 'cadence_lapse_factor' => 'float', 'cadence_use_trend' => 'boolean', 'cadence_trend_cap_pct' => 'float',
            'cadence_use_seasonality' => 'boolean', 'budget_monthly_amount' => 'decimal:2', 'product_ids' => 'array', 'category_ids' => 'array', 'warehouse_ids' => 'array', 'customer_ids' => 'array',
            'exclude_customer_ids' => 'array', 'diagnostics' => 'array', 'computed_at' => 'datetime', 'compute_seconds' => 'float', 'stock_at' => 'datetime', 'frozen_at' => 'datetime',
            'progress_done' => 'integer', 'progress_total' => 'integer', 'compute_started_at' => 'datetime', 'work' => 'array', 'item_count' => 'integer', 'shortage_item_count' => 'integer',
            'surplus_item_count' => 'integer', 'line_count' => 'integer', 'pattern_count' => 'integer', 'event_count' => 'integer', 'requirement_count' => 'integer',
            'total_forecast_qty' => 'float', 'total_shortage_qty' => 'float', 'total_surplus_qty' => 'float', 'total_suggested_qty' => 'float', 'total_suggested_value' => 'decimal:2',
            'total_requirement_value' => 'decimal:2', 'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'run_id', 'id');
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class, 'run_id', 'id');
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'run_id', 'id');
    }

    public function day(string $column): string
    {
        return $this->getAttribute($column)->format('Y-m-d');
    }
}
