<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One skill of a skill type. */
class Skill extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_skills';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
