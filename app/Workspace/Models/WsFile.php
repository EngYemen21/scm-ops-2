<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A file of the workspace: on a ticket, on a message (a reply, a note, a comment), on a task — or one version of a
 * document (`version_no`). Its bytes are kept by FileStore (`storage = ops`) or, for an attachment that came from Odoo,
 * by the Odoo backup (`storage = odoo`, the attachment id in `external_ref`): linked, never copied.
 */
class WsFile extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_files';

    public const UPDATED_AT = null;

    public const OWNERS = ['ticket', 'message', 'task', 'document'];

    /** The storage key never leaves the server. */
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'size' => 'integer', 'removed_at' => 'datetime'];
    }
}
