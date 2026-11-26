<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A coin or a note the cash is counted in (Odoo `pos.bill`). */
class PosDenomination extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_denominations';

    public const KINDS = ['coin', 'note'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
