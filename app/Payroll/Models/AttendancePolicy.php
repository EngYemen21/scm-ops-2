<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * How attendance turns into pay: the grace a late arrival or an early leave gets, how lateness, early leave and absence
 * are deducted, and how overtime is paid. A policy without a rule for something deducts (or pays) the actual time.
 */
class AttendancePolicy extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_attendance_policies';

    protected function casts(): array
    {
        return ['late_grace_minutes' => 'integer', 'early_grace_minutes' => 'integer', 'is_default' => 'boolean', 'incomplete' => 'boolean', 'active' => 'boolean',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function lateRule(): BelongsTo
    {
        return $this->belongsTo(AttendanceRule::class, 'late_rule_id', 'id');
    }

    public function earlyRule(): BelongsTo
    {
        return $this->belongsTo(AttendanceRule::class, 'early_rule_id', 'id');
    }

    public function absenceRule(): BelongsTo
    {
        return $this->belongsTo(AttendanceRule::class, 'absence_rule_id', 'id');
    }

    public function overtimeRules(): BelongsToMany
    {
        return $this->belongsToMany(AttendanceRule::class, 'pay_policy_overtime_rules', 'policy_id', 'rule_id');
    }
}
