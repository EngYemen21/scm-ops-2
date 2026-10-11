<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer invoice line: delivered quantity at the order price, with the cost of goods it carried. */
class CustomerInvoiceLine extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_customer_invoice_lines';

    const UPDATED_AT = null;

    const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'integer',
            'price' => 'decimal:2',
            'disc_pct' => 'float',
            'line_total' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'cogs' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'invoice_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
