<?php

namespace App\Planning\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchase of a plan: a product for a warehouse from a vendor, to be ordered on a date. Once requested it names the
 * purchase request (and its line) it became; what was ordered and received is read from the purchase orders behind it.
 */
class PlanLine extends BaseModel
{
    use HasUlids;

    protected $table = 'planning_plan_lines';

    public const STATUSES = ['planned', 'rfq', 'confirmed', 'partial', 'received', 'cancelled'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer', 'qty' => 'float', 'price_unit' => 'float', 'subtotal' => 'decimal:2', 'order_date' => 'date', 'need_date' => 'date', 'lead_days' => 'float',
            'cancelled' => 'boolean', 'requested_at' => 'datetime', 'ordered_qty' => 'float', 'received_qty' => 'float', 'committed_value' => 'decimal:2', 'received_value' => 'decimal:2',
            'late_order' => 'boolean', 'late_receipt' => 'boolean', 'refs' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }
}
