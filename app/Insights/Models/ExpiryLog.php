<?php

namespace App\Insights\Models;

use App\Models\BaseModel;
use App\Models\Batch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One announcement of a lot at a level (Odoo `expiry.notification.log`). Unique per (level, lot): a lot is announced
 * once per level, never twice. What the lot was at that moment (number, expiry, quantity on hand) is kept as it was.
 */
class ExpiryLog extends BaseModel
{
    use HasUlids;

    protected $table = 'ins_expiry_log';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expiry_date' => 'datetime', 'notified_at' => 'datetime', 'qty_on_hand' => 'integer', 'days_left' => 'integer', 'recipients' => 'array'];
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(ExpiryLevel::class, 'level_id', 'id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }
}
