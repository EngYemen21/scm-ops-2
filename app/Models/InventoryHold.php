<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock held on one balance row for a document that is not a sales order (an approved transfer, a supplier return
 * taken from the shelf). Written only by InventoryService::hold / releaseHolds / consumeHolds.
 */
class InventoryHold extends BaseModel
{
    use HasUlids;

    protected $table = 'inventory_holds';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class, 'bin_id', 'id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id', 'id');
    }
}
