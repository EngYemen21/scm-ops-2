<?php

namespace App\Tax\Models;

use App\Models\BaseModel;
use App\Tax\Support\Boxes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax code: what kind of VAT treatment a document has, at which rate, on which line of the VAT return it is
 * declared, and — for a sale without VAT — the category and the reason the e-invoice must name.
 * `is_default` marks the code OPS documents of that use and rate get when nobody says otherwise.
 */
class TaxCode extends BaseModel
{
    use HasUlids;

    protected $table = 'tax_codes';

    public const USES = ['sale', 'purchase'];

    public const SCOPES = ['goods', 'service'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4', 'return_box' => 'integer', 'price_include' => 'boolean', 'sequence' => 'integer', 'is_default' => 'boolean', 'active' => 'boolean',
            'extra' => 'array', 'source_updated_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TaxGroup::class, 'group_id', 'id');
    }

    /** The kinds a code of a use may have. @return string[] */
    public static function kinds(string $use): array
    {
        return array_keys(Boxes::ALLOWED[$use] ?? []);
    }
}
