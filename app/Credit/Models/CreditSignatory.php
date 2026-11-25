<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A person the company authorises to sign on its behalf (Odoo's `b2b.credit.authorization.line`): name, job title, mobile, a note on the signature. */
class CreditSignatory extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_signatories';

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
