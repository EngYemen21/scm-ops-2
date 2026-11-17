<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A file body kept in the database (base64 in `content`) when no object storage is configured. */
class StoredFile extends BaseModel
{
    use HasUlids;

    protected $table = 'stored_files';

    const UPDATED_AT = null;

    /** The body never travels in JSON listings. */
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
