<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member of a help-desk team: an OPS user, or the name Odoo gives for somebody who has no OPS user. */
class TicketTeamMember extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_ticket_team_members';

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
