<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A job position (accountant, driver, warehouse supervisor …), optionally tied to one department. */
class Job extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_jobs';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }
}
