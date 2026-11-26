<?php

namespace App\Pricing\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price list of a customer (one at most). `basis` says how OPS knows it:
 *   manual   a person chose it here;
 *   partner  Odoo's own "price list" of the partner;
 *   order    the list of the customer's latest order in Odoo — read from the orders while the copy does not carry
 *            the partner's own field. A person may correct it; Odoo's own field replaces it the day it is copied.
 */
class PriceListCustomer extends BaseModel
{
    use HasUlids;

    protected $table = 'price_list_customers';

    public const BASES = ['manual', 'partner', 'order'];

    protected function casts(): array
    {
        return ['basis_odoo_id' => 'integer', 'basis_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id', 'id');
    }
}
