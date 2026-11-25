<?php

namespace App\Pos\Models;

use App\Finance\Models\CustomerInvoice;
use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A counter sale or a refund (Odoo `pos.order`). Amounts are positive for both kinds: `kind` says which way the money
 * and the goods went. A sale is written whole in one transaction (stock out, the order, its tax invoice, its receipts,
 * the cost of sales) and is immutable afterwards; it is corrected by a refund against it, never edited.
 * `pending_qty` > 0: the register allowed selling more than the shelf held and that much is still owed to stock.
 */
class PosOrder extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_orders';

    public const KINDS = ['sale', 'refund'];

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime', 'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'vat_pct' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'total' => 'decimal:2', 'tendered' => 'decimal:2', 'change_due' => 'decimal:2', 'on_account' => 'decimal:2', 'cogs_total' => 'decimal:2',
            'refunded_total' => 'decimal:2', 'pending_qty' => 'integer', 'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosOrderLine::class, 'order_id', 'id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosOrderPayment::class, 'order_id', 'id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'session_id', 'id');
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'register_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'invoice_id', 'id');
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'refund_of_id', 'id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'refund_of_id', 'id');
    }
}
