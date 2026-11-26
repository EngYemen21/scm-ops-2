<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a thread — the conversation of a ticket or the comments of a task:
 *   reply    what was answered to the requester (the first one stops the clock of the first-response target)
 *   note     an internal remark on a ticket
 *   comment  a remark on a task
 *   event    something that happened to the record (`event` names it, `data` holds its facts)
 * A line that came from Odoo carries `source_system` + the Odoo message id.
 */
class Message extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_messages';

    public $timestamps = false;

    public const THREADS = ['ticket', 'task'];

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id', 'id');
    }
}
