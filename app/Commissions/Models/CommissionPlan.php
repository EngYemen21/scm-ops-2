<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A commission plan: who earns (a salesperson on his own sales, or a manager on a team's), on what (the invoice when
 * it is issued or when it is fully collected; before tax, with tax, or the margin; net of returns or not), at which
 * rate (its rules) and from when to when. draft → active → archived.
 */
class CommissionPlan extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_plans';

    public const EARNERS = ['salesperson', 'manager'];

    public const EARN_ON = ['invoiced', 'collected'];

    public const BASES = ['net', 'gross', 'margin'];

    public const STATUSES = ['draft', 'active', 'archived'];

    /** from → the states it may move to */
    public const TRANSITIONS = ['draft' => ['active'], 'active' => ['archived'], 'archived' => ['active']];

    protected function casts(): array
    {
        return [
            'everyone' => 'boolean', 'net_of_returns' => 'boolean', 'valid_from' => 'date', 'valid_to' => 'date',
            'activated_at' => 'datetime', 'archived_at' => 'datetime',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(CommissionPlanRule::class, 'plan_id', 'id')->orderBy('sequence')->orderBy('id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommissionPlanMember::class, 'plan_id', 'id')->orderBy('created_at')->orderBy('id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id', 'id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(CommissionStatement::class, 'plan_id', 'id');
    }
}
