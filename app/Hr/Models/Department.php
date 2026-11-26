<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A department of the company: a tree (`parent_id`) with a manager who is an employee. */
class Department extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_departments';

    protected function casts(): array
    {
        return ['active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id', 'id');
    }
}
