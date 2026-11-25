<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A help-desk team: who works its tickets, who gets a new one nobody was named for, and the two targets a ticket of the
 * team is measured against (hours to the first answer, hours to the closing — the second gives a ticket its due date).
 */
class TicketTeam extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_ticket_teams';

    /** Who reads the tickets of a team: everybody who opens the section, or its members only. */
    public const VISIBILITY = ['all', 'members'];

    protected function casts(): array
    {
        return ['first_response_hours' => 'float', 'resolution_hours' => 'float', 'sequence' => 'integer', 'active' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(TicketTeamMember::class, 'team_id', 'id');
    }

    public function defaultAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assignee_id', 'id');
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence')->orderBy('name_ar')->orderBy('id');
    }
}
