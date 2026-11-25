<?php

namespace App\Pricing\Models;

use App\Models\BaseModel;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a supplier charges for a product from a quantity (`min_qty`) inside a period. The price a buyer pays is
 * `price` less `discount_pct`. Archived (`active = false`), never deleted.
 */
class SupplierPrice extends BaseModel
{
    use HasUlids;

    protected $table = 'supplier_prices';

    protected function casts(): array
    {
        return [
            'price' => 'float', 'discount_pct' => 'float', 'min_qty' => 'float', 'lead_days' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date',
            'sequence' => 'integer', 'active' => 'boolean', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    /** The price after the supplier's discount. */
    public function net(): float
    {
        return round($this->price * (1 - $this->discount_pct / 100), 4);
    }

    /** A price of Odoo that waits for its supplier or its product to exist in OPS: kept, never used. */
    public function pending(): bool
    {
        return $this->supplier_id === null || $this->product_id === null;
    }

    /** expired | future | valid — where `$day` stands against the period of the price. */
    public function periodOn(Carbon $day): string
    {
        if ($this->valid_to !== null && $this->valid_to->lt($day)) {
            return 'expired';
        }

        return $this->valid_from !== null && $this->valid_from->gt($day) ? 'future' : 'valid';
    }
}
