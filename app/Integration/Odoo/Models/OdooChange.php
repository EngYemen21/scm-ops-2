<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** One thing that happened in Odoo, in the order OPS learned it: created | updated | archived | restored | deleted. */
class OdooChange extends BaseModel
{
    protected $table = 'odoo_changes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['odoo_id' => 'integer', 'fields' => 'array', 'run_id' => 'integer', 'seen_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
