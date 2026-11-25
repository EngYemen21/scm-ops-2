<?php

namespace App\Pricing\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A customer price list: a currency, an optional validity and its rules. One list at most is the default — the list
 * of every customer that has none of its own. A list is archived (`active = false`), never deleted.
 */
class PriceList extends BaseModel
{
    use HasUlids;

    protected $table = 'price_lists';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean', 'sequence' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date', 'active' => 'boolean', 'extra' => 'array',
            'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PriceRule::class, 'price_list_id', 'id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(PriceListCustomer::class, 'price_list_id', 'id');
    }

    /** May the list price anything on that day: active and inside its validity. */
    public function usableOn(Carbon $day): bool
    {
        return $this->active && ($this->valid_from === null || $this->valid_from->lte($day)) && ($this->valid_to === null || $this->valid_to->gte($day));
    }
}
