<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of approval request ("طلب صرف", "طلب مرتجع"): who approves it, in which order, how many approvals it needs
 * and which fields a request of this kind must carry.
 */
class ApprovalCategory extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_categories';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort' => 'integer',
            'min_approvals' => 'integer',
            'allow_self_approval' => 'boolean',
            'fields' => 'array',
            'source_meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalCategoryApprover::class, 'category_id', 'id')->orderBy('sequence')->orderBy('id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'category_id', 'id');
    }
}
