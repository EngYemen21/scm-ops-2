<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One line of the timeline of an application, an order request, a favourite list, a statement or a branch: what
 * happened to it, and the notes people leave on it. Append-only. `origin = 'odoo'` marks what happened in Odoo and
 * was learned from the copy.
 */
class PortalEvent extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_events';

    public $timestamps = false;

    public const ENTITIES = ['application', 'request', 'list', 'statement', 'branch'];

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'datetime'];
    }
}
