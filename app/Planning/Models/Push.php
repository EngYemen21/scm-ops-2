<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One time a run's suggested minimum / maximum was written to a product's reorder settings: what stood there and what was written. */
class Push extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_pushes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['old_min' => 'integer', 'old_max' => 'integer', 'new_min' => 'integer', 'new_max' => 'integer', 'at' => 'datetime', 'item_ids' => 'array'];
    }
}
