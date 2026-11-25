<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A rule of an attendance policy.
 *   late / early   lines by threshold: from N minutes on, deduct `rate × minutes` or a fixed time, weighed by how many
 *                  times it happened in the period (first … fifth)
 *   absence        lines by occurrence: the n-th absence of the period costs `rate ×` the day's hours
 *   overtime       the rule itself: on a working day / weekend / holiday, once it exceeds `active_after_minutes`, an
 *                  hour worked is paid as `rate` hours
 */
class AttendanceRule extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_attendance_rules';

    public const KINDS = ['late', 'early', 'absence', 'overtime'];

    public const DAY_TYPES = ['workday', 'weekend', 'holiday'];

    protected function casts(): array
    {
        return ['active_after_minutes' => 'integer', 'rate' => 'decimal:4', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AttendanceRuleLine::class, 'rule_id', 'id')->orderBy('sequence')->orderBy('id');
    }
}
