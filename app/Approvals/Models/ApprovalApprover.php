<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approver of one request and his decision.
 *
 *   new       the request was not sent yet
 *   waiting   sent, but it is not his turn (approvers in sequence)
 *   pending   he is asked now
 *   approved  · refused
 *   cancelled the request was cancelled while he was asked (Odoo's own wording; OPS keeps the line as it was)
 *   removed   taken off the request by a manager — the line stays and no longer counts
 */
class ApprovalApprover extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_request_approvers';

    /** Not decided yet. */
    public const OPEN = ['new', 'waiting', 'pending'];

    /** No longer part of the decision. */
    public const OUT = ['cancelled', 'removed'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'required' => 'boolean',
            'decided_at' => 'datetime',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'request_id', 'id');
    }
}
