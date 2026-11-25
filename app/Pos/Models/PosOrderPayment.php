<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment of an order (Odoo `pos.payment`). `amount` is what was handed over (cash may exceed what is due);
 * `applied` is what settles the order (the amount less the change it gave). For a refund it is the money paid back.
 * `fin_payment_id` is the finance receipt that recorded it.
 */
class PosOrderPayment extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_order_payments';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'applied' => 'decimal:2', 'line_no' => 'integer', 'extra' => 'array', 'created_at' => 'datetime'];
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PosPaymentMethod::class, 'method_id', 'id');
    }
}
