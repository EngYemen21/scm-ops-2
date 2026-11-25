<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product of a favourite list. The table holds every favourite of every customer: always read per list, by page. */
class FavoriteItem extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_favorite_items';

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'sequence' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(FavoriteList::class, 'list_id', 'id');
    }
}
