<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A column of one project's board. A task that enters a closing stage is done. */
class TaskStage extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_task_stages';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'is_closing' => 'boolean', 'active' => 'boolean'];
    }
}
