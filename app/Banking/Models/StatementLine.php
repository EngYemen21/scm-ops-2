<?php

namespace App\Banking\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement the bank reports: money in (+) or out (−) on a day. `match_id` names the match that explains it while
 * that match stands; `fingerprint` is what a second import of the same line is refused by.
 *
 * `kind = opening` is the balance the FIRST statement of a bank account opens with, kept as a line of its own so that
 * it too is explained by ledger entries before the account can be called reconciled; it is not part of the statement's
 * "opening + lines = closing".
 */
class StatementLine extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_statement_lines';

    public const KIND_LINE = 'line';

    public const KIND_OPENING = 'opening';

    /** What the source system says of a line it held (history only). */
    public const SOURCE_STATES = ['reconciled', 'open', 'cancelled'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'txn_date' => 'date', 'value_date' => 'date', 'amount' => 'decimal:2', 'balance_after' => 'decimal:2', 'extra' => 'array',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class, 'statement_id', 'id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(BankMatch::class, 'match_id', 'id');
    }
}
