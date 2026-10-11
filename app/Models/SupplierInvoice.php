<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's invoice registered against a purchase order and matched three ways (PO × GRN × invoice). */
class SupplierInvoice extends BaseModel
{
    use HasUlids;

    protected $table = 'supplier_invoices';

    public const STATES = ['matched', 'disputed', 'approved', 'paid', 'cancelled'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'datetime',
            'due_date' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'vat_pct' => 'float',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'variances' => 'array',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function po(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id', 'id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class, 'invoice_id', 'id');
    }
}
