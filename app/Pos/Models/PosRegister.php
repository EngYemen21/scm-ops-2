<?php

namespace App\Pos\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A register (Odoo `pos.config`): the counter that sells from ONE warehouse, to a default walk-in customer, at the
 * prices of the price service, with the payment methods bound to it. Its settings are the rules of the selling screen:
 * the highest line discount, whether a sale may promise stock the shelf does not hold, where refunded goods go.
 */
class PosRegister extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_registers';

    /** Where refunded goods go: back to the bin they left (`shelf`) or to the returns area RET-01 for inspection. */
    public const RESTOCK = ['shelf', 'returns'];

    public const RECEIPTS = ['80mm', 'a4'];

    protected function casts(): array
    {
        return [
            'max_discount_pct' => 'decimal:2', 'allow_negative_stock' => 'boolean', 'refund_needs_manager' => 'boolean', 'active' => 'boolean',
            'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'default_customer_id', 'id');
    }

    public function methods(): BelongsToMany
    {
        return $this->belongsToMany(PosPaymentMethod::class, 'pos_register_methods', 'register_id', 'method_id');
    }
}
