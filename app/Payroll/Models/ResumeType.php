<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A kind of résumé line: experience, education, a course, a certificate … */
class ResumeType extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_resume_types';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
