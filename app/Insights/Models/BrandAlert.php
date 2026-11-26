<?php

namespace App\Insights\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alert of a brand owner's dashboard (Odoo `brand.owner.dashboard.alert`). `occurrence_key` names the occurrence
 * it was raised for (a franchisee and a month, an invoice, a silence that began on a date…): unique per owner, so an
 * occurrence is raised once, whoever runs the rules and however often. Alerts are never deleted: they are read.
 */
class BrandAlert extends BaseModel
{
    use HasUlids;

    protected $table = 'ins_brand_alerts';

    const UPDATED_AT = null;

    public const TYPES = ['no_purchase', 'purchase_drop', 'target_exceeded', 'target_not_met', 'cancelled_orders', 'product_drop', 'overdue_invoice', 'high_returns'];

    public const SEVERITIES = ['info', 'warning', 'danger', 'success'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'data' => 'array', 'is_read' => 'boolean', 'read_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(BrandOwner::class, 'owner_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
