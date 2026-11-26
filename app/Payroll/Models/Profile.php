<?php

namespace App\Payroll\Models;

use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** How one employee is paid and timed: pay structure, work schedule, attendance policy, bank transfer or cash. */
class Profile extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_profiles';

    public const PAYMENT_METHODS = ['bank', 'cash'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'structure_id', 'id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id', 'id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id', 'id');
    }
}
