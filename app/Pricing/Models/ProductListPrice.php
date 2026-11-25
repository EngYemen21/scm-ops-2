<?php

namespace App\Pricing\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The list (public) price of a product: what a discount rule starts from, and the price where no rule applies. */
class ProductListPrice extends BaseModel
{
    use HasUlids;

    protected $table = 'product_list_prices';

    protected function casts(): array
    {
        return ['price' => 'float', 'source_updated_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
