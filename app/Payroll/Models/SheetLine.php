<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One day of an attendance sheet. Times of day are minutes after midnight on the schedule's clock; durations are minutes. */
class SheetLine extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_sheet_lines';

    /** null = a day that was worked */
    public const STATUSES = ['absence', 'weekend', 'holiday', 'leave'];

    /** What a person may correct on a line (with a reason): the figures after the policy. */
    public const CORRECTABLE = ['late', 'early', 'overtime'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'weekday' => 'integer', 'planned_in' => 'integer', 'planned_out' => 'integer', 'actual_in' => 'integer', 'actual_out' => 'integer',
            'planned_minutes' => 'integer', 'worked_minutes' => 'integer', 'late_actual' => 'integer', 'late' => 'integer', 'early_actual' => 'integer', 'early' => 'integer',
            'overtime_actual' => 'integer', 'overtime' => 'integer', 'absence_minutes' => 'integer', 'corrected' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
