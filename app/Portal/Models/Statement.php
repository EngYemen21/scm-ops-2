<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A statement of account as it was given to a customer: one period, the balance it opened and closed with, and the
 * documents in between. A statement is a snapshot — what the customer was told on that day — so it is never
 * recomputed: one that should not stand is voided with a reason and a new one is issued.
 */
class Statement extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_statements';

    public const STATUSES = ['draft', 'issued', 'void'];

    /** The longest period one statement may cover, and the most lines it may carry. */
    public const MAX_DAYS = 731;

    public const MAX_LINES = 20000;

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'opening_balance' => 'decimal:2', 'total_debit' => 'decimal:2', 'total_credit' => 'decimal:2',
            'closing_balance' => 'decimal:2', 'books_closing' => 'decimal:2', 'lines_count' => 'integer',
            'issued_at' => 'datetime', 'voided_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PortalBranch::class, 'branch_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StatementLine::class, 'statement_id', 'id');
    }
}
