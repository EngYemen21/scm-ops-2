<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** The budget of one month of a scenario, and what the allocation made of it. */
class BudgetLine extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_budget_lines';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['month' => 'date', 'amount' => 'decimal:2', 'carried_in' => 'decimal:2', 'required_value' => 'decimal:2', 'funded_value' => 'decimal:2', 'remaining_value' => 'decimal:2'];
    }
}
