<?php

namespace App\Payroll\Models;

use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for time off:
 *
 *   draft ──submit──▶ submitted ──approve──▶ (manager_approved ──approve──▶) approved
 *     ▲  ◀─withdraw──┘     └──refuse──▶ refused                               └──cancel──▶ cancelled
 *     └──cancel──▶ cancelled
 */
class Leave extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_leaves';

    public const STATES = ['draft', 'submitted', 'manager_approved', 'approved', 'refused', 'cancelled'];

    /** from → to. The server is the only authority; every move also checks who asks (App\Payroll\Services\LeaveService). */
    public const TRANSITIONS = [
        'draft' => ['submitted', 'approved', 'cancelled'],             // straight to approved: a type nobody has to approve
        'submitted' => ['manager_approved', 'approved', 'refused', 'draft', 'cancelled'],
        'manager_approved' => ['approved', 'refused', 'cancelled'],
        'approved' => ['cancelled'],
        'refused' => [],
        'cancelled' => [],
    ];

    /** The states in which the days are spoken for: nobody else's request may overlap them. */
    public const STANDING = ['draft', 'submitted', 'manager_approved', 'approved'];

    /** Waiting for somebody's decision. */
    public const WAITING = ['submitted', 'manager_approved'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'half_day' => 'boolean', 'days' => 'decimal:2', 'year' => 'integer', 'submitted_at' => 'datetime',
            'first_approved_at' => 'datetime', 'decided_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id', 'id');
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
