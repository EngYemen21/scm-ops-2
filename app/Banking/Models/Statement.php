<?php

namespace App\Banking\Models;

use App\Finance\Models\BankAccount;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank statement of one OPS bank account for a period: the balance the bank opened and closed with, and its lines.
 *
 * `status` is its state machine: open (lines are typed, imported and matched) → reconciled (locked: nothing of it
 * changes until a holder of `bank.reconcile` reopens it with a reason) → open again; an open statement entered by
 * mistake is voided with a reason and stays visible. `history` is what the source system held before OPS did the
 * work: it is read here and never takes part in the OPS reconciliation.
 */
class Statement extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_statements';

    public const STATUSES = ['open', 'reconciled', 'void', 'history'];

    /** The statements the OPS reconciliation is made of. */
    public const LIVE = ['open', 'reconciled'];

    /** from → the states it may move to. */
    public const TRANSITIONS = ['open' => ['reconciled', 'void'], 'reconciled' => ['open'], 'void' => [], 'history' => []];

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2',
            'reconciled_at' => 'datetime', 'reopened_at' => 'datetime', 'voided_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function sourceJournal(): BelongsTo
    {
        return $this->belongsTo(SourceJournal::class, 'source_journal_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'statement_id', 'id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ImportRun::class, 'statement_id', 'id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE, true);
    }
}
