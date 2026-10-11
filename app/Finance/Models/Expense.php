<?php

namespace App\Finance\Models;

use App\Models\BaseModel;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Expense voucher: an operating cost (tolls, parking, services …) settled from a bank account, on a supplier account or accrued, with its cost dimensions. */
class Expense extends BaseModel
{
    use HasUlids;

    protected $table = 'fin_expenses';

    const UPDATED_AT = null;

    public const SETTLEMENTS = ['bank', 'supplier', 'accrued'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'vat_pct' => 'float',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_id', 'id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id', 'id');
    }
}
