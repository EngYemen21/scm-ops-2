<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One line of the leave ledger: days granted (+) or taken (−) of one type, for one employee, in one allowance year.
 * Lines are only ever added — a balance is the sum of its lines and nothing else.
 */
class LeaveEntry extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_leave_entries';

    const UPDATED_AT = null;

    /** allocation: days granted · taken: an approved leave · restored: an approved leave that was cancelled · adjustment: a correction with a reason */
    public const KINDS = ['allocation', 'taken', 'restored', 'adjustment'];

    protected function casts(): array
    {
        return ['days' => 'decimal:2', 'year' => 'integer', 'created_at' => 'datetime'];
    }
}
