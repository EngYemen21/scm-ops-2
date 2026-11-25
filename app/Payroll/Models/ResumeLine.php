<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an employee's résumé: a job held, a degree, a course — with its dates. */
class ResumeLine extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_resume_lines';

    protected function casts(): array
    {
        return ['date_start' => 'date', 'date_end' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ResumeType::class, 'type_id', 'id');
    }
}
