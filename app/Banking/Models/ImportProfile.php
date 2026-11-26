<?php

namespace App\Banking\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved column mapping: how the file a bank exports is read — where its header row is, which column is the date,
 * the description, the reference, the amount (one signed column, a debit and a credit column, or an amount with a
 * debit / credit mark), how dates and numbers are written. Kept per bank account so the next month's file is one click.
 */
class ImportProfile extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_import_profiles';

    protected function casts(): array
    {
        return ['options' => 'array', 'mapping' => 'array', 'is_default' => 'boolean', 'active' => 'boolean'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }
}
