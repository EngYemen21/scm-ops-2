<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A favourite list of a customer: the products it orders again and again, in the customer's own order, each with the
 * quantity it usually takes. `items_count` is kept equal to the rows of the list, so a list of lists never counts.
 */
class FavoriteList extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_favorite_lists';

    /** A list is a working tool, not a catalogue: past this it is refused, and reordering stays a small job. */
    public const MAX_ITEMS = 1000;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'items_count' => 'integer', 'active' => 'boolean',
            'archived_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(FavoriteItem::class, 'list_id', 'id');
    }
}
