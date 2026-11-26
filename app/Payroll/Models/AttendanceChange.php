<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One corrected value of an attendance record or of a sheet line: what it was, what it became, who changed it and why. Append-only. */
class AttendanceChange extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_attendance_changes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
