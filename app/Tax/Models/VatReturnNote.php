<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * The explanation of a movement on a VAT account that no document of the return stands behind: a payment to the
 * authority, an opening balance, something else a person describes. It is withdrawn, never removed.
 */
class VatReturnNote extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_return_ledger_notes';

    const UPDATED_AT = null;

    public const TREATMENTS = [
        'settlement' => ['سداد أو استرداد مع الهيئة', 'Payment to / refund from the authority'],
        'opening' => ['رصيد افتتاحي', 'Opening balance'],
        'other' => ['سبب آخر', 'Another reason'],
    ];

    protected function casts(): array
    {
        return ['voided_at' => 'datetime'];
    }
}
