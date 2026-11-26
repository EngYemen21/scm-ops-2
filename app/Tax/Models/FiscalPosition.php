<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fiscal position: where a customer or a supplier stands for tax (inside the Kingdom, the Gulf, abroad) and which
 * tax code replaces which for its documents (`lines`).
 */
class FiscalPosition extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_fiscal_positions';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'auto_apply' => 'boolean', 'vat_required' => 'boolean', 'active' => 'boolean', 'extra' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FiscalPositionLine::class, 'position_id', 'id');
    }
}
