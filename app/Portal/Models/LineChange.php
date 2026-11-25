<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the log of order-line changes: who added, changed or removed which product on which order, with the
 * value before and after. Append-only — nothing edits or removes a line of it.
 */
class LineChange extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_line_changes';

    const UPDATED_AT = null;

    public const TYPES = ['added', 'modified', 'removed'];

    public const FIELDS = ['qty', 'price'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PortalBranch::class, 'branch_id', 'id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OrderRequest::class, 'request_id', 'id');
    }
}
