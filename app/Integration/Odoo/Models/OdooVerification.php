<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** One comparison of the copy with Odoo: is everything there, and is it what Odoo holds. */
class OdooVerification extends BaseModel
{
    protected $table = 'odoo_verifications';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'detail' => 'array', 'complete' => 'boolean', 'models' => 'integer',
            'records_odoo' => 'integer', 'records_copy' => 'integer', 'missing' => 'integer', 'extra' => 'integer', 'sampled' => 'integer', 'differing' => 'integer',
        ];
    }
}
