<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A supplier qualification document (CR, VAT certificate, licence…) with its expiry. The file itself: Integration Pending. */
class SupplierDocument extends BaseModel
{
    use HasUlids;

    protected $table = 'supplier_documents';

    public const TYPES = ['cr', 'vat', 'license', 'iso', 'insurance', 'other'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'mandatory' => 'boolean',
            'expiry_alerted_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now()->startOfDay());
    }
}
