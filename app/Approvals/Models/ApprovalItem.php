<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing a request asks for: a product of OPS, or a free description, with its quantity. */
class ApprovalItem extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_request_items';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:3',
            'source_meta' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
