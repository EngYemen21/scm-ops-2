<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expense claim: what an employee spent for the company, line by line, on its way
 * draft → submitted → approved → posted (expense vouchers of the finance module) → paid.
 */
class Claim extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_claims';

    public const STATES = ['draft', 'submitted', 'approved', 'posted', 'paid', 'refused', 'cancelled'];

    /**
     * from → to. The server is the only authority; every move also checks who asks (App\Hr\Services\ClaimsService).
     * submitted → draft is "returned for correction" (a reviewer) or "withdrawn" (the claimant); approved → submitted
     * takes an approval back; posted / paid → approved reverses the vouchers; paid → posted takes a payment back.
     */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['approved', 'refused', 'draft'],
        'approved' => ['posted', 'submitted'],
        'posted' => ['paid', 'approved'],
        'paid' => ['posted', 'approved'],
        'refused' => [],
        'cancelled' => [],
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'vat_amount' => 'decimal:2', 'total' => 'decimal:2', 'employee_due' => 'decimal:2', 'company_paid' => 'decimal:2',
            'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'posted_at' => 'datetime', 'paid_at' => 'datetime', 'accounting_date' => 'date',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ClaimLine::class, 'claim_id', 'id')->orderBy('line_no');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
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
