<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One stretch of a weekday of a schedule — work, or a break inside it. `weekday`: 0 = Monday … 6 = Sunday. */
class ScheduleDay extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_schedule_days';

    public const KINDS = ['work', 'break'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'from_minute' => 'integer', 'to_minute' => 'integer', 'sequence' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
