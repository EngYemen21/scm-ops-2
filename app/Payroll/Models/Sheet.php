<?php

namespace App\Payroll\Models;

use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The attendance sheet of one employee for a period: a line per day (planned and actual sign in / out, late, early
 * leave, overtime, absence, weekend / holiday / leave) and their totals after the policy.
 * draft → confirmed → approved; an approved sheet feeds the payslip of its period.
 */
class Sheet extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_sheets';

    public const STATES = ['draft', 'confirmed', 'approved'];

    public const TRANSITIONS = ['draft' => ['confirmed'], 'confirmed' => ['approved', 'draft'], 'approved' => ['draft']];

    /** The totals a sheet carries, in the order the screens show them. */
    public const TOTALS = ['planned_minutes', 'worked_minutes', 'late_count', 'late_actual_minutes', 'late_minutes', 'early_count', 'early_actual_minutes', 'early_minutes',
        'overtime_count', 'overtime_actual_minutes', 'overtime_minutes', 'absence_count', 'absence_minutes', 'leave_days', 'holiday_days', 'weekend_days'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'leave_days' => 'decimal:2', 'extra' => 'array', 'confirmed_at' => 'datetime', 'approved_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SheetLine::class, 'sheet_id', 'id')->orderBy('work_date');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id', 'id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SheetBatch::class, 'batch_id', 'id');
    }

    public static function can(?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from ?? ''] ?? [], true);
    }
}
