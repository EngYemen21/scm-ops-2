<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One line of a task's checklist. */
class ChecklistItem extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_task_checklist';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['done' => 'boolean', 'done_at' => 'datetime', 'position' => 'integer'];
    }
}
