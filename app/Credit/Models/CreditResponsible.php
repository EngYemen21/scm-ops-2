<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A responsible person of the company that asks for credit (Odoo's `b2b.credit.responsible.line`): name, position,
 * mobile and national identity number. The identity number is personal — `$hidden`, read through Privacy only;
 * `entered_by_id` is who typed it (the one person besides the reviewers who may read it back while the request is open).
 */
class CreditResponsible extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_responsibles';

    protected $hidden = ['national_id'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'national_id' => 'encrypted', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
