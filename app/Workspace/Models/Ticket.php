<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket of the help desk. `status` is its state machine (open → closed by a closing with a resolution, closed → open
 * by a reopening); `stage_id` is where it stands; `archived` puts it away without closing it. The requester is an OPS
 * customer, a member of staff or — for somebody who is neither — a name.
 */
class Ticket extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_tickets';

    public const STATUSES = ['open', 'closed'];

    public const REQUESTER_KINDS = ['customer', 'staff', 'other'];

    /** 0 normal · 1 medium · 2 high · 3 urgent. */
    public const MAX_PRIORITY = 3;

    protected function casts(): array
    {
        return [
            'priority' => 'integer', 'archived' => 'boolean', 'due_manual' => 'boolean', 'message_count' => 'integer', 'extra' => 'array',
            'due_at' => 'datetime', 'first_response_due_at' => 'datetime', 'first_response_at' => 'datetime', 'closed_at' => 'datetime', 'last_message_at' => 'datetime',
            'archived_at' => 'datetime', 'source_updated_at' => 'datetime', 'source_deleted_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(TicketTeam::class, 'team_id', 'id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TicketStage::class, 'stage_id', 'id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'type_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    public function requesterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id', 'id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id', 'id');
    }
}
