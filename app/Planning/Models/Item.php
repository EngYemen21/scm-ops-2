<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a run. `kind = stock`: a product at a warehouse, with its stock, the balance period by period and what
 * to buy. `kind = customer`: the share of one customer in a run split by customer (history and forecast only).
 * The names it carries are the names on the day of the run.
 */
class Item extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_items';

    public const UPDATED_AT = null;

    public const STATUSES = ['shortage', 'surplus', 'ok', 'no_demand'];

    public const KINDS = ['stock', 'customer'];

    protected function casts(): array
    {
        return [
            'is_rollup' => 'boolean', 'qty_on_hand' => 'float', 'incoming_qty' => 'float', 'outgoing_qty' => 'float', 'history_total_qty' => 'float', 'history_avg_qty' => 'float',
            'history_stdev_qty' => 'float', 'history_periods_used' => 'integer', 'forecast_total_qty' => 'float', 'customer_sum_qty' => 'float', 'avg_daily_qty' => 'float',
            'current_period_demand_qty' => 'float', 'min_projected_balance' => 'float', 'first_shortage_date' => 'date', 'shortage_qty' => 'float', 'surplus_qty' => 'float',
            'coverage_days' => 'float', 'safety_qty' => 'float', 'lead_days' => 'float', 'qty_multiple' => 'float', 'moq' => 'float', 'suggested_purchase_qty' => 'float',
            'suggested_purchase_date' => 'date', 'suggested_purchase_value' => 'decimal:2', 'last_purchase_price' => 'float', 'price_unit' => 'float', 'suggested_min_qty' => 'float',
            'suggested_max_qty' => 'float', 'current_min_qty' => 'float', 'current_max_qty' => 'float', 'push_selected' => 'boolean', 'orderpoint_pushed' => 'boolean',
            'orderpoint_push_date' => 'datetime', 'pushed_min_qty' => 'float', 'pushed_max_qty' => 'float', 'method_fallback' => 'boolean', 'confidence_score' => 'float',
            'explain' => 'array', 'refs' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id', 'id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(PeriodRow::class, 'item_id', 'id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(HistoryRow::class, 'item_id', 'id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class, 'item_id', 'id');
    }
}
