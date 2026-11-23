<?php

namespace App\Integration\Odoo\Models;

use App\Models\BaseModel;

/** One synchronisation run: what it read and what it found. */
class OdooRun extends BaseModel
{
    protected $table = 'odoo_runs';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime', 'finished_at' => 'datetime', 'detail' => 'array', 'ms' => 'integer', 'models' => 'integer', 'calls' => 'integer',
            'pulled' => 'integer', 'created' => 'integer', 'updated' => 'integer', 'deleted' => 'integer', 'errors' => 'integer',
        ];
    }
}
