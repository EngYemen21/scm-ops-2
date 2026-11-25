<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase plan: what a funded budget buys in a stretch of order dates. `status`: draft → to_approve → approved (in
 * progress) → done (closed); cancel from any open state. Its lines become purchase requests of the procurement section.
 */
class Plan extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_plans';

    public const STATUSES = ['draft', 'to_approve', 'approved', 'done', 'cancel'];

    /** from → the states it may move to. */
    public const TRANSITIONS = ['draft' => ['to_approve', 'cancel'], 'to_approve' => ['approved', 'draft', 'cancel'], 'approved' => ['done', 'cancel'], 'done' => [], 'cancel' => []];

    /** One purchase request per vendor and order date · per vendor and week · per vendor for the whole plan. */
    public const GROUPINGS = ['date', 'week', 'plan'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date', 'date_to' => 'date', 'approved_at' => 'datetime', 'submitted_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'extra' => 'array', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id', 'id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PlanLine::class, 'plan_id', 'id');
    }
}
