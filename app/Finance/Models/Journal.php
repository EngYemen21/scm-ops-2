<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Journal entry header. Posted journals are immutable; a correction is a reversal journal linked both ways. `event_key` makes posting idempotent. */
class Journal extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_journals';

    const UPDATED_AT = null;

    public const STATES = ['draft', 'posted', 'reversed'];

    public const KINDS = ['auto', 'manual', 'reversal', 'opening'];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
            'total_debit' => 'decimal:2',
            'total_credit' => 'decimal:2',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_id', 'id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id', 'id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_of_id', 'id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversed_by_id', 'id');
    }
}
