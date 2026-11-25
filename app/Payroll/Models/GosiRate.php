<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Social insurance (GOSI) of one scheme from a date on: the employee's and the employer's percentage of the
 * contribution base, and the floor and ceiling of that base. A change of rates is a new row with its own date.
 */
class GosiRate extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_gosi_rates';

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'employee_pct' => 'decimal:4', 'employer_pct' => 'decimal:4', 'base_min' => 'decimal:2', 'base_max' => 'decimal:2',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
