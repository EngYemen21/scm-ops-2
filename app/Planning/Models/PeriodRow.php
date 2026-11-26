<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A forecast period of an item: the demand expected in it and — for a stock item — the balance it would end with if nothing were bought. */
class PeriodRow extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_periods';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'period_index' => 'integer', 'date_from' => 'date', 'date_to' => 'date', 'day_count' => 'integer', 'history_qty' => 'float', 'forecast_demand_qty' => 'float',
            'scheduled_out_qty' => 'float', 'scheduled_in_qty' => 'float', 'unmet_demand_qty' => 'float', 'opening_balance_qty' => 'float', 'booked_balance_qty' => 'float',
            'projected_balance_qty' => 'float', 'shortage_qty' => 'float', 'surplus_qty' => 'float', 'coverage_days' => 'float',
        ];
    }
}
