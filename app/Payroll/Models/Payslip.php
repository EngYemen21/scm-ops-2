<?php

namespace App\Payroll\Models;

use App\Hr\Models\Contract;
use App\Hr\Models\Department;
use App\Hr\Models\Employee;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The pay of one contract for one month: its lines (signed — a deduction is negative) add up to `net` to the halala,
 * and `explain` says where every figure came from. It follows the state of its run; `live_key` holds the contract's
 * month while the payslip stands.
 */
class Payslip extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_payslips';

    /** The line categories whose totals make the net (the others are summaries and the company's own share). */
    public const NET_CATEGORIES = ['basic', 'allowance', 'deduction', 'reimbursement'];

    /** The states in which the employee it pays may read it. */
    public const VISIBLE_TO_EMPLOYEE = ['approved', 'posted', 'paid'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'days_covered' => 'integer', 'days_in_month' => 'integer', 'basic' => 'decimal:2', 'gross' => 'decimal:2',
            'deductions' => 'decimal:2', 'reimbursements' => 'decimal:2', 'net' => 'decimal:2', 'employer_cost' => 'decimal:2', 'worked' => 'array', 'explain' => 'array',
            'warnings' => 'array', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class, 'payslip_id', 'id')->orderBy('sequence')->orderBy('id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id', 'id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contract_id', 'id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(Structure::class, 'structure_id', 'id');
    }

    public static function liveKey(string $contractId, string $period): string
    {
        return "{$contractId}:{$period}";
    }
}
