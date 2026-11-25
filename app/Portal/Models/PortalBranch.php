<?php

namespace App\Portal\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\CustomerSite;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A branch of a customer as the portal knows it: the unit that orders, keeps favourite lists and gets a statement.
 * Its name, customer and people are the source system's when it came from one; where it is, whom to call and when it
 * takes deliveries are OPS's own on every branch. `site_id` names the customer's delivery site in OPS — the one link
 * between a branch and the orders and invoices OPS makes for it.
 */
class PortalBranch extends BaseModel
{
    use HasUlids;

    protected $table = 'portal_branches';

    /** The days a branch may take deliveries, Saturday first (the working week here). */
    public const DAYS = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'lat' => 'float', 'lng' => 'float', 'delivery_days' => 'array', 'portal_users' => 'array', 'active' => 'boolean', 'extra' => 'array',
            'archived_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(CustomerSite::class, 'site_id', 'id');
    }
}
