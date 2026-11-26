<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a statement, explainable by itself: the document, the base, the rule and the rate that produced the
 * commission. `invoice` and `credit_note` lines are calculated; a `reversal` takes back a line of an earlier statement
 * that no longer holds; an `adjustment` is a person's correction with a reason.
 */
class CommissionLine extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_lines';

    public const KINDS = ['invoice', 'credit_note', 'reversal', 'adjustment'];

    /** The kinds the calculation writes (and rewrites while the statement is a draft). */
    public const CALCULATED = ['invoice', 'credit_note', 'reversal'];

    /** The kinds that grant a document to an earner (a reversal takes one of them back). */
    public const GRANTS = ['invoice', 'credit_note'];

    public const SOURCE_FINANCE = 'CustomerInvoice';

    public const SOURCE_ODOO = 'OdooInvoice';

    protected function casts(): array
    {
        return [
            'doc_date' => 'date', 'earned_on' => 'date', 'invoice_total' => 'decimal:2', 'base_amount' => 'decimal:2', 'rate' => 'float', 'commission' => 'decimal:4',
            'explain' => 'array', 'excluded' => 'boolean', 'voided_at' => 'datetime', 'processed_at' => 'datetime',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CommissionStatement::class, 'statement_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id', 'id');
    }
}
