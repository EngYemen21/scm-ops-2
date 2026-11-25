<?php

namespace App\ProductRequests\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\ProductRequests\Support\Flow;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request for a product the catalogue does not hold yet: what is asked for, who asked and why, where the request
 * stands, and — once it is approved — the product it became. Its events tell the story in order; its pictures show
 * the product.
 */
class ProductRequest extends BaseModel
{
    use HasUlids;

    protected $table = 'product_requests';

    public const ODOO = 'odoo';

    /** Odoo's staff flow (three approvals) and what a customer asks for through the portal. */
    public const STAFF_MODEL = 'b2b.new.product';

    public const PORTAL_MODEL = 'hub.product.request';

    protected function casts(): array
    {
        return [
            'status_at' => 'datetime',
            'pack_qty' => 'integer',
            'cost_price' => 'decimal:4',
            'sale_price' => 'decimal:4',
            'shelf_life_days' => 'integer',
            'tracks_expiry' => 'boolean',
            'weight_kg' => 'float',
            'length_cm' => 'float',
            'width_cm' => 'float',
            'height_cm' => 'float',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'decided_at' => 'datetime',
            'returns_count' => 'integer',
            'source_meta' => 'array',
            'source_updated_at' => 'datetime',
            'source_deleted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id', 'id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'uom_id', 'id');
    }

    public function packUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'pack_uom_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProductRequestEvent::class, 'request_id', 'id')->orderByDesc('at')->orderByDesc('id');
    }

    /** The pictures still on the request, the first one added first (it is the one the lists show). */
    public function pictures(): HasMany
    {
        return $this->hasMany(ProductRequestPicture::class, 'request_id', 'id')->whereNull('removed_at')->orderBy('created_at')->orderBy('id');
    }

    public function fromOdoo(): bool
    {
        return $this->source_system === self::ODOO;
    }

    /** Still on somebody's desk: not approved, rejected or cancelled. */
    public function open(): bool
    {
        return in_array($this->status, Flow::OPEN, true);
    }
}
