<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chart of accounts. `role` names the control account the posting rules resolve (ar, ap, inventory, grni …); headers are not postable. */
class Account extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_accounts';

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Debit-normal types: balance = debits − credits; the rest are credit-normal. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected function casts(): array
    {
        return [
            'is_postable' => 'boolean',
            'active' => 'boolean',
            'sort' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id', 'id');
    }
}
