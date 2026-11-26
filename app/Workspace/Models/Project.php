<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project: a manager, members who work its tasks, dates, and a board of its own (its stages). `status` is
 * active ↔ on_hold → done (and back to active by a reopening); `archived` puts it away.
 */
class Project extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_projects';

    public const STATUSES = ['active', 'on_hold', 'done'];

    /** What a project may become from each status. */
    public const TRANSITIONS = ['active' => ['on_hold', 'done'], 'on_hold' => ['active', 'done'], 'done' => ['active']];

    public const VISIBILITY = ['all', 'members'];

    protected function casts(): array
    {
        return [
            'archived' => 'boolean', 'start_date' => 'date', 'end_date' => 'date', 'extra' => 'array',
            'archived_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id', 'id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class, 'project_id', 'id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(TaskStage::class, 'project_id', 'id')->orderBy('sequence')->orderBy('created_at')->orderBy('id');
    }
}
