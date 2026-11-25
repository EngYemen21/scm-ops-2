<?php

namespace App\Credit\Models;

use App\Credit\Support\Flow;
use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * A stage of the credit workflow — the workflow is data, this is one row of it (Odoo's `b2b.credit.stage`).
 *
 *   state                 the state a request carries while it stands here (Flow::STATES)
 *   responsible_role /    who decides it: the users of an OPS role, a named user, both, or — when nobody is named —
 *   responsible_user_id   any holder of the permission the decision needs
 *   approval_mode         any_user: one approval is enough · all_users: every one of them approves
 *   sla_hours             the target time of the stage; reminder_before_hours before its end the approvers are
 *                         reminded, escalate_after_hours after it the escalation role is brought in
 *   require_documents /   what must be true before a request LEAVES the stage: the required papers are all there,
 *   require_signature     the agreement is signed
 *   auto_send_signature   entering the stage issues the agreement for signature by itself
 *   lock_request_fields   the request's own fields cannot be edited while it stands here
 *   portal_visible        the customer is shown that the request stands here
 *
 * `responsible_role_name` / `escalation_role_name` keep the name of Odoo's security group while no OPS role answers
 * to it: such a stage cannot be entered by a request worked in OPS until a person names its OPS role.
 */
class CreditStage extends BaseModel
{
    use HasUlids;

    protected $table = 'credit_stages';

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'active' => 'boolean', 'fold' => 'boolean', 'sla_hours' => 'float', 'reminder_before_hours' => 'float', 'escalate_after_hours' => 'float',
            'require_documents' => 'boolean', 'require_signature' => 'boolean', 'auto_send_signature' => 'boolean', 'lock_request_fields' => 'boolean', 'portal_visible' => 'boolean',
            'created_at' => 'datetime', 'updated_at' => 'datetime',
        ];
    }

    /** The order of the workflow: by sequence; at the same sequence the older stage first. */
    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sequence')->orderBy('created_at')->orderBy('id');
    }

    /** A stage a request is worked in (decided by approvers), as opposed to the requester's own or a closed one. */
    public function isWork(): bool
    {
        return Flow::isWork($this->state);
    }

    /** Odoo names a security group here and no OPS role answers to it yet: nobody can be asked on its behalf. */
    public function unmapped(): bool
    {
        return $this->responsible_role === null && $this->responsible_role_name !== null && $this->responsible_role_name !== '';
    }

    /** @return array{id:string, code:string, nameAr:string, nameEn:string, state:string} */
    public function brief(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'nameAr' => $this->name_ar, 'nameEn' => $this->name_en, 'state' => $this->state];
    }
}
