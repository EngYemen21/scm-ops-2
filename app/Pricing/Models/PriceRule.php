<?php

namespace App\Pricing\Models;

use App\Models\BaseModel;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rule of a price list. `applies_to` says what it prices (a product, a category with its sub-categories, or
 * everything); `compute` says how:
 *   fixed    the price itself;
 *   percent  the base less `percent` %;
 *   formula  the base less `discount_pct` %, rounded to a multiple of `round_to`, plus `surcharge`, kept between
 *            base + `min_margin` and base + `max_margin`.
 * The base of percent / formula is the product's list price, its cost, or its price on another list.
 */
class PriceRule extends BaseModel
{
    use HasUlids;

    protected $table = 'price_list_rules';

    public const APPLIES = ['product', 'category', 'all'];

    public const COMPUTES = ['fixed', 'percent', 'formula'];

    public const BASES = ['list', 'cost', 'pricelist'];

    /** The order rules are tried in: the most specific first. */
    public const RANK = ['product' => 0, 'category' => 1, 'all' => 2];

    protected function casts(): array
    {
        return [
            'min_qty' => 'float', 'valid_from' => 'date', 'valid_to' => 'date', 'fixed_price' => 'float', 'percent' => 'float', 'discount_pct' => 'float',
            'surcharge' => 'float', 'round_to' => 'float', 'min_margin' => 'float', 'max_margin' => 'float', 'seq' => 'integer', 'active' => 'boolean',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id', 'id');
    }

    public function baseList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'base_list_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'id');
    }

    /** A rule of Odoo that waits for its product or its category to exist in OPS: kept, never used. */
    public function pending(): bool
    {
        return ($this->applies_to === 'product' && $this->product_id === null) || ($this->applies_to === 'category' && $this->category_id === null);
    }
}
