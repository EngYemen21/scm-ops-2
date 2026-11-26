<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One line of a lead's timeline. Append-only: a stage move keeps where it came from and where it went, a closing keeps
 * its reason, a note keeps its text. `origin = 'odoo'` marks what happened in Odoo and was learned from the copy.
 */
class CrmLeadEvent extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_lead_events';

    public $timestamps = false;

    public const KINDS = ['created', 'stage', 'won', 'lost', 'reopened', 'assigned', 'note', 'converted', 'quotation', 'activity', 'archived', 'restored'];

    protected function casts(): array
    {
        return ['data' => 'array', 'at' => 'datetime'];
    }
}
