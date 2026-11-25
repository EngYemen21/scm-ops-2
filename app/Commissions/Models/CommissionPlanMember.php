<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A salesperson of a plan: an earner of a salesperson plan, a member of the team of a manager plan. */
class CommissionPlanMember extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_plan_members';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
