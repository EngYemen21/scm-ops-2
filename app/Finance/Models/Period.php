<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Fiscal period (one calendar month). Posting needs an open period; closing is audited and reversible only with gl.close. */
class Period extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_periods';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
