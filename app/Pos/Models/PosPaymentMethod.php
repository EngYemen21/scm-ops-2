<?php

namespace App\Pos\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A way to pay at the counter (Odoo `pos.payment.method`), bound to the finance bank account or cash box the money
 * lands in. `cash` is counted at closing and may give change; `card` and `transfer` are recorded with their reference
 * (no terminal is driven); `account` puts the amount on the customer's account (the invoice stays open for it).
 */
class PosPaymentMethod extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_payment_methods';

    public const KINDS = ['cash', 'card', 'transfer', 'account'];

    /** How a method kind is recorded by the finance receipts (`fin_payments.method`). */
    public const FINANCE_METHOD = ['cash' => 'cash', 'card' => 'card', 'transfer' => 'bank'];

    protected function casts(): array
    {
        return [
            'requires_reference' => 'boolean', 'active' => 'boolean', 'sequence' => 'integer', 'extra' => 'array',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }
}
