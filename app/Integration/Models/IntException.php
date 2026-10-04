<?php

namespace App\Integration\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** Something in the integration a human must look at (mapping gap, rejected / dead event, mismatch). */
class IntException extends BaseModel
{
    use HasUlids;

    protected $table = 'int_exceptions';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'details' => 'array', 'occurrences' => 'integer', 'first_at' => 'datetime', 'last_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }
}
