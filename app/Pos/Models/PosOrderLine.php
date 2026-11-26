<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a sale or a refund. `unit_price` is the price before VAT, rounded once to the halala; `line_total` is
 * the line after its discount, before VAT. On a sale line `issued_qty` + `pending_qty` = `qty` and `refunded_qty` is
 * what refunds took back; a refund line points at the sale line it refunds.
 */
class PosOrderLine extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_order_lines';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3', 'unit_price' => 'decimal:2', 'discount_pct' => 'decimal:2', 'line_total' => 'decimal:2', 'unit_cost' => 'decimal:4', 'cogs' => 'decimal:2',
            'issued_qty' => 'integer', 'pending_qty' => 'integer', 'refunded_qty' => 'integer', 'line_no' => 'integer', 'extra' => 'array', 'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(PosOrderLineLot::class, 'line_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'order_id', 'id');
    }
}
