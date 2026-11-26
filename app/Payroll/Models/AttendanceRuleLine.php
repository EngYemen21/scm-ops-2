<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One step of a late / early / absence rule (see AttendanceRule). */
class AttendanceRuleLine extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_attendance_rule_lines';

    public const TYPES = ['rate', 'fix'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'threshold_minutes' => 'integer', 'rate' => 'decimal:4', 'amount_minutes' => 'integer', 'first' => 'decimal:4', 'second' => 'decimal:4',
            'third' => 'decimal:4', 'fourth' => 'decimal:4', 'fifth' => 'decimal:4', 'counter' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
