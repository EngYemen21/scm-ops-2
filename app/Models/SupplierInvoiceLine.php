<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceLine extends BaseModel
{
    use HasUlids;

    protected $table = 'supplier_invoice_lines';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'integer',
            'price' => 'decimal:2',
            'po_price' => 'decimal:2',
            'received_qty' => 'integer',
            'previously_invoiced_qty' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'invoice_id', 'id');
    }

    public function poLine(): BelongsTo
    {
        return $this->belongsTo(PoLine::class, 'po_line_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
