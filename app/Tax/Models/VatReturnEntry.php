<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * One document behind a figure of a return: the journal that put it in the books, the document, its base and its VAT
 * with their sign, and the line and column of the form it is counted in. For a draft these rows are rebuilt from the
 * books whenever something changed; from the review on they are the return's record of what it declared.
 */
class VatReturnEntry extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_return_entries';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'reversal' => 'boolean', 'late' => 'boolean', 'box' => 'integer', 'base' => 'decimal:2', 'vat' => 'decimal:2', 'doc_vat' => 'decimal:2', 'rate' => 'decimal:4'];
    }
}
