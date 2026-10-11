<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bank account or cash box mapped to a GL account; payments and receipts move money through it. */
class BankAccount extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_bank_accounts';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }
}
