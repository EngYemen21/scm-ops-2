<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A paper of an application. `status` says where its content lives: `stored` (database) / `uploaded` (object store)
 * for a file added in OPS — both through FileStore — and `odoo` for a file the company attached in Odoo, whose content
 * is read from the Odoo file archive once it is kept there. A paper taken off an application stays, marked as removed.
 */
class ApplicationDocument extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_application_documents';

    const UPDATED_AT = null;

    public const ODOO = 'odoo';

    /** What a company hands in: commercial registration, VAT certificate, national address, the owner's identity, a bank letter, the chamber of commerce certificate. */
    public const KINDS = ['cr', 'vat', 'national_address', 'owner_id', 'bank_letter', 'chamber', 'other'];

    /** Papers that show a person's identity or bank account: read by who decides applications, not by every reader of the page. */
    public const PRIVATE_KINDS = ['owner_id', 'bank_letter'];

    /** Where a file lives is nobody's business outside the server. */
    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'removed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
