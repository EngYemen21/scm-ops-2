<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One line of a payslip: a component, the quantity and rate behind it, and its signed total (a deduction is negative). */
class PayslipLine extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_payslip_lines';

    const UPDATED_AT = null;

    public const CATEGORIES = ['basic', 'allowance', 'gross', 'deduction', 'reimbursement', 'net', 'company'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'quantity' => 'decimal:4', 'rate' => 'decimal:4', 'amount' => 'decimal:2', 'total' => 'decimal:2', 'explain' => 'array', 'created_at' => 'datetime'];
    }
}
