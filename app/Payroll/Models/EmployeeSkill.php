<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A skill an employee has, at a level. One row per employee and skill. */
class EmployeeSkill extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_employee_skills';

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class, 'skill_id', 'id');
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SkillLevel::class, 'level_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(SkillType::class, 'type_id', 'id');
    }
}
