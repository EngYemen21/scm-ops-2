<?php

namespace App\Hr\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expense of a claim. The figures are kept the way the finance voucher computes them — amount before VAT, a VAT
 * percentage, VAT = round(amount × pct), total = amount + VAT — so the voucher a line is posted as carries exactly
 * the total the claim shows.
 */
class ClaimLine extends BaseModel
{
    use HasUlids;

    protected $table = 'hr_claim_lines';

    public const PAID_BY = ['employee', 'company'];

    protected function casts(): array
    {
        return ['line_no' => 'integer', 'expense_date' => 'date', 'amount' => 'decimal:2', 'vat_pct' => 'float', 'vat_amount' => 'decimal:2', 'total' => 'decimal:2',
            'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class, 'claim_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id', 'id');
    }
}
