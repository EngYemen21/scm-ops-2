<?php

namespace App\Insights\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A franchisee of a brand owner: an OPS customer with the region and city it serves, the day it started, the monthly
 * purchase target agreed with it and its status (Odoo `brand.franchisee.mapping`). One customer appears once per owner.
 *
 * Status: active ⇄ weak; active | weak → inactive → active; any → stopped (final).
 */
class Franchisee extends BaseModel
{
    use HasUlids;

    protected $table = 'ins_franchisees';

    public const STATES = ['active', 'weak', 'inactive', 'stopped'];

    public const TRANSITIONS = [
        'active' => ['weak', 'inactive', 'stopped'],
        'weak' => ['active', 'inactive', 'stopped'],
        'inactive' => ['active', 'stopped'],
        'stopped' => [],
    ];

    /** The statuses that are expected to buy (the alerts and the target look at these). */
    public const BUYING = ['active', 'weak'];

    protected function casts(): array
    {
        return ['date_start' => 'date:Y-m-d', 'monthly_target' => 'decimal:2', 'active' => 'boolean'];
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
