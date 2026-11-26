<?php

namespace App\Payroll\Models;

use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One presence of an employee: a check-in and (once they leave) a check-out. A correction keeps what the record said
 * first (`original_*`) and leaves a line per changed value in pay_attendance_changes; a wrong record is voided, not removed.
 */
class Attendance extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_attendance';

    public const MODES = ['self', 'manual', 'kiosk', 'import', 'systray', 'technical'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'check_in' => 'datetime', 'check_out' => 'datetime', 'worked_minutes' => 'integer', 'corrected' => 'boolean',
            'original_check_in' => 'datetime', 'original_check_out' => 'datetime', 'voided_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }
}
