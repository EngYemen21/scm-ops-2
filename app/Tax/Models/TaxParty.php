<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What tax needs to know about a customer or a supplier and the master record has no field for: the national address
 * in its parts, another identification when it has no VAT number, its fiscal position, the tax code of its documents
 * and whether its invoices are standard or simplified.
 */
class TaxParty extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_parties';

    public const TYPES = ['customer', 'supplier'];

    public const INVOICE_KINDS = ['auto', 'standard', 'simplified'];

    public function position(): BelongsTo
    {
        return $this->belongsTo(FiscalPosition::class, 'fiscal_position_id', 'id');
    }

    public function defaultCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_code_id', 'id');
    }
}
