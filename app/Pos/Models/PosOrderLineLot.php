<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Where the goods of a line physically went: one row per stock movement — `out` from a bin (and batch) for a sale,
 * `back` into a bin for a refund. A refund returns stock against the `out` rows of the sale line (`returned_qty`), so
 * each unit comes back to the batch it left and at the cost it left at.
 */
class PosOrderLineLot extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_order_line_lots';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['qty' => 'integer', 'returned_qty' => 'integer', 'unit_cost' => 'decimal:4', 'total_cost' => 'decimal:2', 'created_at' => 'datetime'];
    }
}
