<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's decision: this OPS document is of that tax code (instead of the code its rate would give it). */
class TaxDocumentCode extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_document_codes';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['set_at' => 'datetime'];
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id', 'id');
    }
}
