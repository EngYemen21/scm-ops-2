<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A figure the payroll engine uses, from a date on (see App\Payroll\Services\Rates::KNOWN for the ones it reads). */
class Parameter extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_parameters';

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
