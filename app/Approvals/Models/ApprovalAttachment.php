<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A file of a request. `status` says where its content lives: `stored` (database) / `uploaded` (object store) for a
 * file added in OPS — both through FileStore — and `odoo` for a file that was attached in Odoo, whose content is read
 * from the Odoo file archive once it is kept there.
 */
class ApprovalAttachment extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_attachments';

    const UPDATED_AT = null;

    public const ODOO = 'odoo';

    /** Where a file lives is nobody's business outside the server. */
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'removed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
