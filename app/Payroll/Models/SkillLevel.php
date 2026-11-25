<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A level of mastery inside a skill type, with how far it stands on the way to full mastery (0..100). */
class SkillLevel extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_skill_levels';

    protected function casts(): array
    {
        return ['progress' => 'integer', 'is_default' => 'boolean', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
