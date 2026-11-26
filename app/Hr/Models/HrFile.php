<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A file of this section: the scan of a document, a receipt of a claim line, a paper of the personal file. Its bytes
 * are kept by FileStore (`storage = ops`) or, for an attachment that came from Odoo, by the Odoo backup
 * (`storage = odoo`, `external_ref` = the attachment id). It is read through GET /api/hr/files/{id} only, which
 * checks the permission of what the file hangs on (App\Hr\Services\FilesService).
 */
class HrFile extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_files';

    const UPDATED_AT = null;

    public const OWNERS = ['employee', 'contract', 'document', 'claim', 'claim_line'];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
