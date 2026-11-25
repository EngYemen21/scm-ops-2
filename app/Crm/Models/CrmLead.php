<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lead / opportunity of the pipeline. `status` is its state machine (open → won | lost, and back to open by a
 * reopening); `stage_id` is where it stands on the board. `next_activity_*` mirror its earliest open to-do.
 */
class CrmLead extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_leads';

    public const STATUSES = ['open', 'won', 'lost'];

    public const KINDS = ['lead', 'opportunity'];

    /** 0 normal · 1 medium · 2 high · 3 very high (the stars of the card). */
    public const MAX_PRIORITY = 3;

    protected function casts(): array
    {
        return [
            'archived' => 'boolean', 'priority' => 'integer', 'expected_revenue' => 'decimal:2', 'probability' => 'float',
            'deadline' => 'date', 'next_activity_date' => 'date', 'opened_at' => 'datetime', 'stage_changed_at' => 'datetime', 'closed_at' => 'datetime',
            'converted_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime', 'extra' => 'array',
        ];
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(CrmStage::class, 'stage_id', 'id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(CrmTeam::class, 'team_id', 'id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function lostReason(): BelongsTo
    {
        return $this->belongsTo(CrmLostReason::class, 'lost_reason_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CrmTag::class, 'crm_lead_tags', 'lead_id', 'tag_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'lead_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrmLeadEvent::class, 'lead_id', 'id');
    }
}
