<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One physical carton of a packed fulfilment order; `code` is printed on its label and scanned at loading / delivery. */
class Carton extends BaseModel
{
    use HasUlids;

    protected $table = 'cartons';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'weight_kg' => 'float',
            'loaded_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function fo(): BelongsTo
    {
        return $this->belongsTo(FulfillmentOrder::class, 'fo_id', 'id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id', 'id');
    }
}
