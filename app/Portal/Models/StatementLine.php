<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One document of a statement: an invoice (debit), a credit note or a receipt (credit), or the cancelling of one of them. */
class StatementLine extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_statement_lines';

    const UPDATED_AT = null;

    public const KINDS = ['invoice', 'credit_note', 'payment', 'reversal', 'other'];

    protected function casts(): array
    {
        return ['date' => 'date', 'seq' => 'integer', 'debit' => 'decimal:2', 'credit' => 'decimal:2', 'balance' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PortalBranch::class, 'branch_id', 'id');
    }
}
