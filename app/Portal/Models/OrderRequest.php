<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order a branch of a customer asks for through the portal. pending → approved | rejected | cancelled, and nothing
 * leaves those three: an approved request has become a document of the sales module (a quotation here, a sale order in
 * Odoo) and is followed there.
 */
class OrderRequest extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_order_requests';

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    public const TRANSITIONS = ['pending' => ['approved', 'rejected', 'cancelled'], 'approved' => [], 'rejected' => [], 'cancelled' => []];

    public const MAX_LINES = 300;

    protected function casts(): array
    {
        return [
            'amount_total' => 'decimal:2', 'decision_hours' => 'float', 'lines_count' => 'integer',
            'request_date' => 'datetime', 'decided_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(PortalBranch::class, 'branch_id', 'id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderRequestLine::class, 'request_id', 'id');
    }
}
