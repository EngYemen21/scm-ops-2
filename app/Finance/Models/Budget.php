<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Budget amount per account, month and optional cost center; compared with posted actuals. */
class Budget extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_budgets';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id', 'id');
    }
}
