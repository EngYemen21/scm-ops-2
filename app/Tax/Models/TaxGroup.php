<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** A family of taxes as people group them on a document (VAT, other taxes, withholding). */
class TaxGroup extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_groups';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'active' => 'boolean', 'extra' => 'array'];
    }
}
