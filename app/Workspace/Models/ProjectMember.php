<?php

namespace App\Workspace\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member of a project: an OPS user, or a name kept from Odoo. */
class ProjectMember extends BaseModel
{
    use HasUlids;

    protected $table = 'ws_project_members';

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
