<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** How one customer reorders one product at one warehouse, as a run read it from its history (App\Planning\Engine\Cadence). */
class Pattern extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_patterns';

    public const UPDATED_AT = null;

    public const STATES = ['active', 'overdue', 'lapsed', 'insufficient'];

    public const BANDS = ['high', 'medium', 'low', 'none'];

    protected function casts(): array
    {
        return [
            'order_count' => 'integer', 'first_order_date' => 'date', 'last_order_date' => 'date', 'avg_interval_days' => 'float', 'interval_stdev_days' => 'float', 'interval_cv' => 'float',
            'avg_order_qty' => 'float', 'qty_cv' => 'float', 'trend_factor' => 'float', 'expected_order_qty' => 'float', 'seasonal' => 'boolean', 'next_expected_date' => 'date',
            'days_since_last' => 'integer', 'overdue_days' => 'integer', 'confidence_score' => 'float', 'forecast_qty' => 'float', 'expected_order_count' => 'integer', 'refs' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'pattern_id', 'id');
    }
}
