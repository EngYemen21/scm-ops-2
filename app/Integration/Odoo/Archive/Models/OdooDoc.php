<?php

namespace App\Integration\Odoo\Archive\Models;

use App\Models\BaseModel;

/**
 * One row of the archive's index (`odoo_docs`): what a mirrored Odoo record is for a person — kind, number, title,
 * partner, date, state, amount, what it hangs on. Derived from `odoo_records` by the ArchiveWriter; read-only elsewhere.
 */
class OdooDoc extends BaseModel
{
    protected $table = 'odoo_docs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'odoo_id' => 'integer', 'live' => 'boolean', 'active' => 'boolean', 'private' => 'boolean', 'partner_id' => 'integer',
            'product_id' => 'integer', 'parent_id' => 'integer', 'seq' => 'integer', 'amount' => 'float', 'residual' => 'float',
            'user_id' => 'integer', 'rec_version' => 'integer',
        ];
    }
}
