<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month of depreciation of one asset and the journal that posted it (unique per asset and period). */
class DepreciationEntry extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_depreciation_entries';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id', 'id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }
}
