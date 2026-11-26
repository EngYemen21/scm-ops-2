<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "For a partner of this position, the code `from` becomes the code `to`." */
class FiscalPositionLine extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_fiscal_position_lines';

    const UPDATED_AT = null;

    public function from(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'from_code_id', 'id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'to_code_id', 'id');
    }
}
