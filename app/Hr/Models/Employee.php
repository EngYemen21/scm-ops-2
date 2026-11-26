<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The WORK part of an employee — what the staff directory shows to everyone who may open the section. The personal
 * file is another model and another table (EmployeePrivate): nothing of it is reachable from here without asking for
 * it by name, and the services only ask after checking `hr.private`.
 */
class Employee extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_employees';

    public const STATUSES = ['active', 'left', 'inactive'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'job_id', 'id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id', 'id');
    }

    /** The personal file. Read it only behind `hr.private`. */
    public function personal(): HasOne
    {
        return $this->hasOne(EmployeePrivate::class, 'employee_id', 'id');
    }
}
