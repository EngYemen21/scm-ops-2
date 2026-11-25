<?php

namespace App\Banking\Models;

use App\Finance\Models\Account;
use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A write-off rule: where a difference between the bank and the books goes (bank charges, interest, rounding) — the
 * ledger account, the label of the journal line, the direction it accepts and the largest amount it may absorb.
 * Applying it posts a journal through the ledger service; a difference above `max_amount` is never written off.
 *
 * kind: writeoff (posts the difference) · matching (a matching model of the source system that carries no counterpart
 * account: kept as the record it is, it never posts).
 */
class WriteoffRule extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_writeoff_rules';

    public const KINDS = ['writeoff', 'matching'];

    /** in: the bank shows MORE than the books (interest, a favourable difference) · out: LESS (charges) · both. */
    public const NATURES = ['in', 'out', 'both'];

    protected function casts(): array
    {
        return ['max_amount' => 'decimal:2', 'sequence' => 'integer', 'active' => 'boolean', 'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    /** The words that suggest the rule for a statement line, lower case. @return string[] */
    public function keywords(): array
    {
        return array_values(array_filter(array_map(fn (string $w) => mb_strtolower(trim($w)), preg_split('/[,،;\n]+/u', (string) $this->match_text) ?: []), fn (string $w) => mb_strlen($w) >= 2));
    }
}
