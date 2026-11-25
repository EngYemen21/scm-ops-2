<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A work schedule: which hours of which weekdays are worked. The planned sign in / out of a day comes from here. */
class Schedule extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_schedules';

    protected function casts(): array
    {
        return ['hours_per_day' => 'decimal:2', 'is_default' => 'boolean', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function days(): HasMany
    {
        return $this->hasMany(ScheduleDay::class, 'schedule_id', 'id')->orderBy('weekday')->orderBy('from_minute');
    }

    /** Minutes one day of pay stands for (the divisor of the hourly rate). */
    public function dayMinutes(): int
    {
        return max(1, (int) round(((float) $this->hours_per_day) * 60));
    }
}
