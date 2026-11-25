<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One approver of a category: his place in the order, whether his approval is a must, and from which amount he is asked. */
class ApprovalCategoryApprover extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_category_approvers';

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'required' => 'boolean',
            'min_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ApprovalCategory::class, 'category_id', 'id');
    }
}
