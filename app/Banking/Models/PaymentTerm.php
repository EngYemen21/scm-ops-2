<?php

namespace App\Banking\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A payment term as the source system holds it: its name, the instalments it is made of (`schedule`: share or fixed
 * amount, after how many days) and its early-payment discount. A dictionary the section keeps so that no record of the
 * source is without a home; `due_days` is the delay of its last instalment.
 */
class PaymentTerm extends BaseModel
{
    use HasUlids;

    protected $table = 'bank_payment_terms';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'early_discount' => 'boolean', 'discount_percent' => 'decimal:2', 'discount_days' => 'integer', 'due_days' => 'integer',
            'schedule' => 'array', 'active' => 'boolean', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }
}
