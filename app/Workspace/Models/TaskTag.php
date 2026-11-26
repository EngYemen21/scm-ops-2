<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A label people put on tasks. `color` is the index of the palette they picked (0..11). */
class TaskTag extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_task_tags';

    public const COLORS = 12;

    protected function casts(): array
    {
        return ['color' => 'integer', 'active' => 'boolean'];
    }
}
