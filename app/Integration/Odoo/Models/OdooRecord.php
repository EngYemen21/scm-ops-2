<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** The copy of one Odoo record: its values as Odoo returned them (`data`), read-only here. */
class OdooRecord extends BaseModel
{
    protected $table = 'odoo_records';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'odoo_id' => 'integer', 'data' => 'array', 'active' => 'boolean', 'version' => 'integer',
            'first_seen_at' => 'datetime', 'changed_at' => 'datetime', 'deleted_at' => 'datetime',
        ];
    }
}
