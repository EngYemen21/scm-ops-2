<?php

namespace App\Insights\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A brand owner: an outsider who looks in at how his brand sells through the company (Odoo `brand.owner`).
 * His products are the products named on him, the products of the categories named on him and the OPS products whose
 * `brand` is his brand name. His franchisees are OPS customers. What he may see beyond his purchases is said by the
 * four access flags; margin also needs `inventory.view_cost`.
 *
 * States: draft → active ⇄ suspended; draft | active | suspended → archived (final). Only an ACTIVE owner's user sees
 * anything (App\Insights\Support\Access).
 */
class BrandOwner extends BaseModel
{
    use HasUlids;

    protected $table = 'ins_brand_owners';

    public const STATES = ['draft', 'active', 'suspended', 'archived'];

    /** from → the states it may move to */
    public const TRANSITIONS = [
        'draft' => ['active', 'archived'],
        'active' => ['suspended', 'archived'],
        'suspended' => ['active', 'archived'],
        'archived' => [],
    ];

    protected function casts(): array
    {
        return [
            'date_start' => 'date:Y-m-d', 'allow_invoice' => 'boolean', 'allow_payment' => 'boolean', 'allow_return' => 'boolean', 'allow_margin' => 'boolean',
            'active' => 'boolean', 'source_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'contact_customer_id', 'id');
    }

    public function franchisees(): HasMany
    {
        return $this->hasMany(Franchisee::class, 'owner_id', 'id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ins_brand_owner_products', 'owner_id', 'product_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'ins_brand_owner_categories', 'owner_id', 'category_id');
    }
}
