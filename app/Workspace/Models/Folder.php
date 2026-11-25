<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A folder of the documents tree. Who reads and who writes is said by role: `all` = everybody who opens the section,
 * `roles` = the listed roles only (an empty list = the documents manager alone); writing includes reading.
 * `own_read` / `own_write` narrow a reader / a writer to the documents he owns (App\Workspace\Support\FolderAccess).
 */
class Folder extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_folders';

    public const SCOPES = ['all', 'roles'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'read_roles' => 'array', 'write_roles' => 'array', 'own_read' => 'boolean', 'own_write' => 'boolean',
            'archived' => 'boolean', 'archived_at' => 'datetime', 'extra' => 'array',
        ];
    }
}
