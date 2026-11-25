<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A pay component — what a payslip line is an amount OF. The engine computes the `system` ones (contract amounts,
 * overtime, attendance and unpaid-leave deductions, social insurance, expense reimbursements); an `input` component is
 * what a person adds for a month through an adjustment (a bonus, a penalty, a loan instalment).
 */
class Component extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_components';

    public const CATEGORIES = ['basic', 'allowance', 'deduction', 'company', 'reimbursement'];

    public const CALCS = ['contract', 'overtime', 'late', 'early', 'absence', 'unpaid_leave', 'gosi_employee', 'gosi_employer', 'claims', 'input'];

    public const CONTRACT_FIELDS = ['wage', 'housing', 'transport', 'other'];

    /** Categories a person may add for a month through an adjustment. */
    public const INPUT_CATEGORIES = ['allowance', 'deduction'];

    protected function casts(): array
    {
        return ['gosi_base' => 'boolean', 'sequence' => 'integer', 'system' => 'boolean', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
