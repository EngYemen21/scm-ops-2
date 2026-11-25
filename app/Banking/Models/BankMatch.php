<?php

namespace App\Banking\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A confirmed match: the statement lines on one side, the ledger entries of the bank's account on the other, equal to
 * the cent — any difference was posted by a write-off rule and stands among the ledger entries of the match.
 * Undoing a match keeps it (status `undone`, who, when, why) and frees its lines; its write-off journal is reversed.
 *
 * kind: match (lines against entries) · contra (entries that cancel each other and never reached the bank, or bank
 * lines that cancel each other and were never booked) · system (the write-off journal of an undone match with its
 * reversal: made by the section itself, never undone by hand).
 */
class BankMatch extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_matches';

    const UPDATED_AT = null;

    public const STATUSES = ['active', 'undone'];

    public const KINDS = ['match', 'contra', 'system'];

    public const ORIGINS = ['manual', 'suggestion', 'auto', 'system'];

    protected function casts(): array
    {
        return [
            'statement_total' => 'decimal:2', 'ledger_total' => 'decimal:2', 'writeoff_amount' => 'decimal:2', 'cleared_date' => 'date',
            'created_at' => 'datetime', 'undone_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(BankMatchItem::class, 'match_id', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(WriteoffRule::class, 'writeoff_rule_id', 'id');
    }
}
