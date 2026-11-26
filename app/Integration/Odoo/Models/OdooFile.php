<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** The content of one Odoo attachment as OPS keeps it (or why it does not). */
class OdooFile extends BaseModel
{
    protected $table = 'odoo_files';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attachment_id' => 'integer', 'res_id' => 'integer', 'size' => 'integer', 'stored_at' => 'datetime'];
    }
}
