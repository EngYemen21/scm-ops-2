<?php

namespace App\Pos\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash put into the drawer or taken out of it during a session (a float top-up, a bank deposit), with its journal. */
class PosCashMove extends BaseModel
{
    use HasUlids;

    protected $table = 'pos_cash_moves';

    const UPDATED_AT = null;

    public const DIRECTIONS = ['in', 'out'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }
}
