<?php

namespace App\Approvals\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** One thing that happened to a request, kept in the order it happened. Append-only: nothing edits or removes a row. */
class ApprovalEvent extends BaseModel
{
    use HasUlids;

    protected $table = 'approval_request_events';

    public $timestamps = false;

    public const KINDS = [
        'created', 'edited', 'submitted', 'approved', 'refused', 'withdrawn', 'cancelled', 'reopened',
        'comment', 'attached', 'detached', 'approver_added', 'approver_removed',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'at' => 'datetime',
        ];
    }
}
