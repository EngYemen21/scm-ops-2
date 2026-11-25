<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A sale put aside at the counter (the customer went to fetch something) and recalled later — on this device or
 * another. held → recalled | discarded. The cart is kept as the selling screen sent it; prices are taken again when the
 * sale is paid.
 */
class PosHeldSale extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_held_sales';

    public const STATES = ['held', 'recalled', 'discarded'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'total' => 'decimal:2', 'recalled_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
