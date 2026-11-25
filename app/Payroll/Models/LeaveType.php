<?php

namespace App\Payroll\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A kind of time off: paid or not, granted in advance or not, and who must approve a request of it. */
class LeaveType extends BaseModel
{
    use HasUlids;

    protected $table = 'pay_leave_types';

    /** none: approved as it is sent · manager: the direct manager (or a holder of leave.approve) · hr: a holder of leave.approve · both: the manager, then HR */
    public const APPROVALS = ['none', 'manager', 'hr', 'both'];

    public const COLORS = 12;

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'requires_allocation' => 'boolean', 'yearly_days' => 'decimal:2', 'requires_document' => 'boolean', 'allow_half_day' => 'boolean',
            'allow_negative' => 'boolean', 'color' => 'integer', 'sequence' => 'integer', 'active' => 'boolean', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
