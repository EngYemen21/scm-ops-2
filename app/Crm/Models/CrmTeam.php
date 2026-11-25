<?php

namespace App\Crm\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sales team. Its leader is an OPS user, or a name kept from Odoo when that person has no OPS user. */
class CrmTeam extends BaseModel
{
    use HasUlids;

    protected $table = 'crm_teams';

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'pipeline' => 'boolean', 'active' => 'boolean'];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id', 'id');
    }
}
