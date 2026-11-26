<?php

namespace App\Payroll\Models;

use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off addition or deduction of one month for one employee, with its reason. It is how an approved run is
 * corrected: the difference goes into the next run. pending → applied (the payslip that carried it was approved) | cancelled.
 */
class Adjustment extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_adjustments';

    public const STATES = ['pending', 'applied', 'cancelled'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cancelled_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'component_id', 'id');
    }
}
