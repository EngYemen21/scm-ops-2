<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rate of a plan. `scope` says which invoice lines it covers (all · the products of some categories · the invoices
 * of some customers); the first rule of the plan, by `sequence`, that covers a line decides. The rate is flat, or
 * tiered on the month's base of this rule (`tier_mode`: whole | progressive).
 */
class CommissionPlanRule extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_plan_rules';

    const UPDATED_AT = null;

    public const SCOPES = ['all', 'category', 'customer'];

    public const RATE_TYPES = ['flat', 'tiered'];

    public const TIER_MODES = ['whole', 'progressive'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'scope_ids' => 'array', 'rate' => 'float', 'tiers' => 'array', 'created_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CommissionPlan::class, 'plan_id', 'id');
    }
}
