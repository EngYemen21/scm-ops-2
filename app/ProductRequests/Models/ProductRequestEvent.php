<?php

namespace App\ProductRequests\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One thing that happened to a request, kept in the order it happened. Append-only: nothing edits or removes a row. */
class ProductRequestEvent extends BaseModel
{
    use HasUlids;

    protected $table = 'product_request_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'at' => 'datetime',
        ];
    }
}
