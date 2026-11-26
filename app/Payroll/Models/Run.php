<?php

namespace App\Payroll\Models;

use App\Hr\Models\Department;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly payroll run:
 *
 *   draft ──submit──▶ review ──approve──▶ approved ──post──▶ posted ──pay──▶ paid
 *     ▲  ◀──return───┘                       │                  │  ◀─unpay─┘
 *     └──cancel──▶ cancelled                 └────reverse───────┴──▶ reversed
 *
 * From `approved` on the figures never change again: a mistake is undone by reversing the whole run (its payslips
 * release their contracts' month) or corrected by an adjustment in the next run.
 */
class Run extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_runs';

    public const STATES = ['draft', 'review', 'approved', 'posted', 'paid', 'reversed', 'cancelled'];

    public const TRANSITIONS = [
        'draft' => ['review', 'cancelled'],
        'review' => ['approved', 'draft', 'cancelled'],
        'approved' => ['posted', 'reversed'],
        'posted' => ['paid', 'reversed'],
        'paid' => ['posted'],
        'reversed' => [],
        'cancelled' => [],
    ];

    /** The figures may still be computed again. */
    public const OPEN = ['draft'];

    /** The run stands: its payslips hold their contracts' month. */
    public const LIVE = ['draft', 'review', 'approved', 'posted', 'paid'];

    /** Approved or beyond: immutable, and visible to the employees it pays. */
    public const FINAL = ['approved', 'posted', 'paid'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'slip_count' => 'integer', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'reimbursements' => 'decimal:2',
            'net' => 'decimal:2', 'employer_cost' => 'decimal:2', 'skipped' => 'array', 'warnings' => 'array', 'computed_at' => 'datetime', 'submitted_at' => 'datetime',
            'approved_at' => 'datetime', 'posted_at' => 'datetime', 'accounting_date' => 'date', 'paid_at' => 'datetime', 'payment_date' => 'date', 'closed_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public static function can(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from ?? ''] ?? [], true);
    }
}
