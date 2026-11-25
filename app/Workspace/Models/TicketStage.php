<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A stage a ticket passes through. A ticket that stands in a closing stage is closed. A stage is for every team unless
 * teams are named for it (`ws_ticket_stage_teams`).
 */
class TicketStage extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_ticket_stages';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_closing' => 'boolean', 'active' => 'boolean'];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(TicketTeam::class, 'ws_ticket_stage_teams', 'stage_id', 'team_id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('name_ar')->orderBy('id');
    }

    /** Is the stage for this team? (needs `teams` loaded) */
    public function fits(string $teamId): bool
    {
        return $this->teams->isEmpty() || $this->teams->contains('id', $teamId);
    }
}
