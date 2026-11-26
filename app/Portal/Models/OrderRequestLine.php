<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One product of an order request: quantity, the unit price the customer saw and their product. */
class OrderRequestLine extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_order_request_lines';

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'qty' => 'decimal:3', 'price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
