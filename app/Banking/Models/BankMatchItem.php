<?php

namespace App\Banking\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a match: a statement line (`side = statement`) or a ledger entry of the bank's account
 * (`side = ledger`, a row of `fin_journal_lines`).
 *
 * `live_statement_line_id` / `live_journal_line_id` repeat the line's id while the match stands and are emptied when it
 * is undone; each is UNIQUE, so the database itself refuses a second standing match of the same statement line or the
 * same ledger entry — whatever two people do at the same moment.
 */
class BankMatchItem extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_match_items';

    public $timestamps = false;

    public const SIDE_STATEMENT = 'statement';

    public const SIDE_LEDGER = 'ledger';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'item_date' => 'date', 'is_writeoff' => 'boolean'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(BankMatch::class, 'match_id', 'id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(StatementLine::class, 'statement_line_id', 'id');
    }
}
