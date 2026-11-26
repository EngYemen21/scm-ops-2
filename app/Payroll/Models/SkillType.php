<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A family of skills (languages, licences, software …) with the skills in it and the levels a person can have in them. */
class SkillType extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_skill_types';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function skills(): HasMany
    {
        return $this->hasMany(Skill::class, 'type_id', 'id')->orderBy('sequence')->orderBy('name_ar');
    }

    public function levels(): HasMany
    {
        return $this->hasMany(SkillLevel::class, 'type_id', 'id')->orderBy('progress')->orderBy('name_ar');
    }
}
