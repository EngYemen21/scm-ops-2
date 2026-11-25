<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One call to the authority and what came back: status and the authority's messages. Never a credential, never a document. */
class EinvoiceAttempt extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_einvoice_attempts';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['http_status' => 'integer', 'messages' => 'array', 'duration_ms' => 'integer', 'at' => 'datetime'];
    }
}
