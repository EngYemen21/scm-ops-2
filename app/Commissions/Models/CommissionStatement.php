<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one earner is owed for one month: the calculated lines, the adjustments, the total — and how far it got:
 * draft (recalculated at will) → submitted (frozen, waiting for approval) → approved → paid (posted through finance).
 */
class CommissionStatement extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_statements';

    public const STATUSES = ['draft', 'submitted', 'approved', 'paid'];

    /** A statement in one of these states is no longer recalculated: its lines are history. */
    public const FROZEN = ['submitted', 'approved', 'paid'];

    /** from → the states it may move to (the server is the only authority on a statement's state) */
    public const TRANSITIONS = [
        'draft' => ['submitted'],
        'submitted' => ['approved', 'draft'],
        'approved' => ['paid', 'draft'],
        'paid' => ['approved'],
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'terms' => 'array', 'lines_count' => 'integer',
            'base_total' => 'decimal:2', 'commission_total' => 'decimal:2', 'adjustments_total' => 'decimal:2', 'total' => 'decimal:2',
            'calculated_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'returned_at' => 'datetime', 'paid_at' => 'datetime',
            'payout_date' => 'date', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionLine::class, 'statement_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'plan_id', 'id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id', 'id');
    }

    public function frozen(): bool
    {
        return in_array($this->status, self::FROZEN, true);
    }
}
