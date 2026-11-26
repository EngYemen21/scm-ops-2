<?php

namespace App\Commissions\Models;

use App\Models\BaseModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whose sale an invoice is. OPS documents carry no salesperson, so a person says it once: this customer is that
 * salesperson's (`kind = customer`, `subject` = the customer id), or the representative the sales platform names on an
 * order is that OPS user (`kind = rep`, `subject` = the name, normalised).
 */
class CommissionAssignment extends BaseModel
{
    use HasUlids;

    protected $table = 'commission_assignments';

    public const KINDS = ['customer', 'rep'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
