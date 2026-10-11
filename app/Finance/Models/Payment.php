<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money in (customer receipt, RCT-) or out (supplier payment, PMT-) through a bank account, allocated to invoices — partial or full. */
class Payment extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_payments';

    const UPDATED_AT = null;

    public const METHODS = ['bank', 'cash', 'cheque', 'card', 'wallet'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'allocated_amount' => 'decimal:2',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }
}
