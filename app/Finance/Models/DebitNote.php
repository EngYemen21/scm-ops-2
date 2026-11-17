<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use App\Models\ReturnOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Supplier debit note: the financial document of goods sent back to a supplier. The stock movement already credits
 * inventory against the supplier's payable at cost; the note adds what makes the claim equal to the purchase value
 * (price difference and input VAT) and gives the claim a number the supplier statement and the buyer can refer to.
 */
class DebitNote extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_debit_notes';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'date' => 'datetime',
            'subtotal' => 'decimal:2',
            'vat_pct' => 'float',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cost_total' => 'decimal:2',
            'lines' => 'array',
            'created_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(ReturnOrder::class, 'return_id', 'id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }
}
