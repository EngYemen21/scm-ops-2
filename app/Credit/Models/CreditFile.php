<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A paper of a credit request: one of the nine document slots, or the signed agreement. Its bytes are kept by
 * FileStore under a key that never leaves the server (`storage = ops`) or, for a paper that was attached in Odoo, by
 * the Odoo backup (`storage = odoo`, `attachment_ref` = the attachment's id there, once the copy knows it).
 *
 * These are identity and company papers: they are rows of THIS table, never of the generic attachments — and they are
 * read through GET /api/credit/requests/{id}/documents/{file} only, which asks who may read them
 * (App\Credit\Support\Privacy). The file name can itself say whose paper it is: it is `$hidden` like the key.
 *
 * Nothing is deleted: a paper that was replaced or taken off stays (`status`), with who closed it and why.
 */
class CreditFile extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_files';

    const UPDATED_AT = null;

    public const CURRENT = 'current';

    public const REPLACED = 'replaced';

    public const REMOVED = 'removed';

    protected $hidden = ['storage_key', 'name', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function fromOdoo(): bool
    {
        return $this->storage === 'odoo';
    }
}
