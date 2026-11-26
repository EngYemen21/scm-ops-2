<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A figure a person adds to a line of a return, with the reason. It is voided, never removed. */
class VatReturnAdjustment extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_return_adjustments';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['box' => 'integer', 'base' => 'decimal:2', 'vat' => 'decimal:2', 'voided_at' => 'datetime'];
    }
}
