<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A task. In a project it stands in one of the project's stages; without a project it is personal — seen by whoever
 * made it and whoever it is given to, by nobody else. `status` is open → done | cancelled, and back to open by a
 * reopening; entering a closing stage IS finishing it.
 */
class Task extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_tasks';

    public const STATUSES = ['open', 'done', 'cancelled'];

    public const TRANSITIONS = ['open' => ['done', 'cancelled'], 'done' => ['open'], 'cancelled' => ['open']];

    public const MAX_PRIORITY = 3;

    protected function casts(): array
    {
        return [
            'priority' => 'integer', 'archived' => 'boolean', 'deadline' => 'date', 'comment_count' => 'integer', 'extra' => 'array',
            'done_at' => 'datetime', 'cancelled_at' => 'datetime', 'stage_changed_at' => 'datetime', 'archived_at' => 'datetime',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TaskStage::class, 'stage_id', 'id');
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(TaskAssignee::class, 'task_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TaskTag::class, 'ws_task_tag_links', 'task_id', 'tag_id');
    }

    public function checklist(): HasMany
    {
        return $this->hasMany(ChecklistItem::class, 'task_id', 'id')->orderBy('position')->orderBy('created_at')->orderBy('id');
    }
}
