<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer invoice (issued automatically from a proof of delivery for the delivered quantity) or credit note (from a post-delivery customer return). */
class CustomerInvoice extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_customer_invoices';

    const UPDATED_AT = null;

    public const STATES = ['issued', 'partially_paid', 'paid', 'cancelled'];

    public const OPEN = ['issued', 'partially_paid'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'vat_pct' => 'float',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'cogs_total' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class, 'invoice_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'so_id', 'id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }

    public function creditOf(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class, 'credit_of_id', 'id');
    }
}
