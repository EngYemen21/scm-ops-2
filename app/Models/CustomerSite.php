<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery site of a customer. For a customer owned by another system (Sales) the name / city / address come from
 * that system; the coordinates it sends are only a hint until OPS verifies them on the map (coords_verified).
 */
class CustomerSite extends BaseModel
{
    use HasUlids;

    protected $table = 'customer_sites';

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float', 'coords_verified' => 'boolean', 'active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }
}
