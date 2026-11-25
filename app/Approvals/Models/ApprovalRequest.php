<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An approval request: what is asked for (amount, dates, partner, reference, quantity / items, reason), who asked,
 * and where it stands. Its approver lines carry the decisions; its events tell the story in order.
 */
class ApprovalRequest extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_requests';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'quantity' => 'decimal:3',
            'request_date' => 'date',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'min_approvals' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'source_meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ApprovalCategory::class, 'category_id', 'id');
    }

    public function approvers(): HasMany
    {
        return $this->hasMany(ApprovalApprover::class, 'request_id', 'id')->orderBy('sequence')->orderBy('created_at')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ApprovalItem::class, 'request_id', 'id')->orderBy('line_no')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ApprovalEvent::class, 'request_id', 'id')->orderBy('at')->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ApprovalAttachment::class, 'request_id', 'id')->orderBy('created_at')->orderBy('id');
    }

    public function fromOdoo(): bool
    {
        return $this->source_system === 'odoo';
    }
}
