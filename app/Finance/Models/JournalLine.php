<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One side of a journal entry with its analysis dimensions (cost center, partner, product, warehouse, vehicle, trip). */
class JournalLine extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_journal_lines';

    const UPDATED_AT = null;

    const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id', 'id');
    }
}
