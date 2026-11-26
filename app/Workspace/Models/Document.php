<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A document of a folder: a file with its versions (`ws_files`, `owner_type = document`; a new upload becomes the
 * version in force and the old ones stay) or a link. It is renamed, moved and archived — never removed.
 */
class Document extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_documents';

    public const KINDS = ['file', 'link'];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'size' => 'integer', 'archived' => 'boolean', 'extra' => 'array',
            'archived_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id', 'id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(DocTag::class, 'ws_document_tags', 'document_id', 'tag_id');
    }

    public function currentFile(): BelongsTo
    {
        return $this->belongsTo(WsFile::class, 'current_file_id', 'id');
    }

    /** Every version it ever had, the newest first. */
    public function versions(): HasMany
    {
        return $this->hasMany(WsFile::class, 'owner_id', 'id')->where('owner_type', 'document')->whereNull('removed_at')->orderByDesc('version_no')->orderByDesc('id');
    }
}
