<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Somebody a task is given to: an OPS user, or a name kept from Odoo. */
class TaskAssignee extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_task_assignees';

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
