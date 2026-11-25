<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** What an expense line is for (travel, fuel, meals …) and the account of the chart its lines are charged to. */
class ExpenseCategory extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_expense_categories';

    protected function casts(): array
    {
        return ['vat_pct' => 'float', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
