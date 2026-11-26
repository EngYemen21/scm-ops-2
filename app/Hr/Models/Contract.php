<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employment contract: type, dates, wage and allowances. People's pay — only behind `hr.private`. */
class Contract extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_contracts';

    public const TYPES = ['permanent', 'fixed_term', 'temporary', 'part_time', 'trainee', 'other'];

    public const STATES = ['draft', 'running', 'expired', 'cancelled'];

    /** from → the states a person may move it to */
    public const TRANSITIONS = ['draft' => ['running', 'cancelled'], 'running' => ['expired', 'cancelled'], 'expired' => [], 'cancelled' => []];

    protected $hidden = ['wage', 'housing_allowance', 'transport_allowance', 'other_allowances'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'trial_end_date' => 'date', 'wage' => 'decimal:2', 'housing_allowance' => 'decimal:2',
            'transport_allowance' => 'decimal:2', 'other_allowances' => 'decimal:2', 'closed_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }
}
