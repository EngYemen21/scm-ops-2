<?php

namespace App\Credit\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A bank account of the company that asks for credit (Odoo's `b2b.credit.bank.line`). The account number and the IBAN
 * are confidential — `$hidden`, read through Privacy only; `entered_by_id` is who typed them.
 */
class CreditBankLine extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_bank_lines';

    protected $hidden = ['account_number', 'iban'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'account_number' => 'encrypted', 'iban' => 'encrypted', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
