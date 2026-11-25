<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * An order a run expects from a customer. What the run expected (date, quantity, confidence) never changes after the
 * run; `actual_*` is what really happened, written as it becomes known.
 */
class Event extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_events';

    public $timestamps = false;

    public const ACTUAL = ['pending', 'ordered', 'missed'];

    protected function casts(): array
    {
        return [
            'expected_date' => 'date', 'occurrence' => 'integer', 'catch_up' => 'boolean', 'period_index' => 'integer', 'in_horizon' => 'boolean', 'qty' => 'float', 'confidence_score' => 'float',
            'actual_date' => 'date', 'actual_qty' => 'float', 'qty_deviation' => 'float', 'actual_checked_at' => 'datetime', 'refs' => 'array',
        ];
    }
}
