<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A history period of an item: what was sold or delivered in it. `used` = it counts as history (the periods before the first sale do not). */
class HistoryRow extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_history';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['period_index' => 'integer', 'date_from' => 'date', 'date_to' => 'date', 'day_count' => 'integer', 'qty' => 'float', 'used' => 'boolean'];
    }
}
