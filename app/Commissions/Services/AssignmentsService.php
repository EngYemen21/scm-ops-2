<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionAssignment;
use App\Commissions\Support\People;
use App\Commissions\Support\Shape;
use App\Models\Customer;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;

/**
 * Whose sale an invoice is — what a person decides once, so that the calculation can answer for every invoice:
 *   customer → salesperson   the invoices of this customer are that user's sales;
 *   rep name → user          the representative the sales platform writes on its orders is that OPS user.
 * One answer per customer and per name: setting it again replaces it (the audit trail keeps what it was). It decides
 * for the statements calculated from then on; a statement that is no longer a draft keeps the salesperson it recorded.
 */
class AssignmentsService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @param  array{kind?:?string, user?:?string}  $f */
    public function list(Paging $page, array $f): array
    {
        $q = CommissionAssignment::query();
        if (in_array($f['kind'] ?? null, CommissionAssignment::KINDS, true)) {
            $q->where('kind', $f['kind']);
        }
        if (! empty($f['user'])) {
            $q->where('user_id', $f['user']);
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '\\%_').'%';
            $op = Sql::like();
            $q->where(fn ($w) => $w->where('subject_label', $op, $like)->orWhere('subject', $op, $like)
                ->orWhereIn('user_id', fn ($s) => $s->from('users')->select('id')->where(fn ($u) => $u->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('username', $op, $like))));
        }
        $q->orderBy('kind')->orderBy('subject_label', $page->order === 'asc' ? 'asc' : 'desc')->orderBy('id');
        $out = $page->paginate($q);
        $users = People::byId(array_map(fn (CommissionAssignment $a) => $a->user_id, $out['items']));
        $out['items'] = array_map(fn (CommissionAssignment $a) => Shape::assignment($a, $users), $out['items']);

        return $out;
    }

    /** @param  array{kind:string, subject:string, user:string}  $dto */
    public function set(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $kind = (string) $dto['kind'];
            $earner = People::earner((string) $dto['user'], 'user');
            if ($kind === 'customer') {
                $customer = Customer::where('id', $dto['subject'])->orWhere('code', $dto['subject'])->first()
                    ?? throw AppError::notFound('CUSTOMER_NOT_FOUND', "العميل {$dto['subject']} غير موجود", 'Customer not found');
                $subject = (string) $customer->id;
                $label = "{$customer->code} · {$customer->name_ar}";
            } else {
                $label = trim((string) preg_replace('/\s+/u', ' ', (string) $dto['subject']));
                $subject = Salespeople::repKey($label)
                    ?? throw AppError::validation('COMMISSION_REP_NAME', 'اكتب اسم المندوب كما يرد من منصة المبيعات', 'Type the representative’s name as the sales platform writes it', [['path' => 'subject', 'message' => 'مطلوب']]);
            }
            $row = CommissionAssignment::where('kind', $kind)->where('subject', $subject)->lockForUpdate()->first();
            $before = $row?->user_id;
            if ($row) {
                $row->update(['user_id' => $earner->id, 'subject_label' => mb_substr($label, 0, 250), 'updated_by' => $user->username]);
            } else {
                $row = CommissionAssignment::create(['kind' => $kind, 'subject' => $subject, 'subject_label' => mb_substr($label, 0, 250), 'user_id' => $earner->id, 'created_by' => $user->username]);
            }
            if ((string) $before !== (string) $earner->id) {
                $this->audit->log($user, ['action' => $before ? 'COMMISSION.ASSIGNMENT.CHANGE' : 'COMMISSION.ASSIGNMENT.SET', 'entityType' => 'CommissionAssignment', 'entityId' => $row->id, 'entityNumber' => mb_substr($label, 0, 120),
                    'oldValue' => $before ? ['user' => $before] : null, 'newValue' => ['kind' => $kind, 'subject' => $subject, 'user' => $earner->username]]);
            }

            return Shape::assignment($row->refresh(), People::byId([$row->user_id]));
        });
    }

    /** Removes the answer: the invoices fall back to what else names their salesperson. The audit trail keeps what it was. */
    public function remove(AuthUser $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id) {
            $row = CommissionAssignment::whereKey($id)->lockForUpdate()->first()
                ?? throw AppError::notFound('COMMISSION_ASSIGNMENT_NOT_FOUND', 'هذا الإسناد غير موجود', 'Assignment not found');
            $this->audit->log($user, ['action' => 'COMMISSION.ASSIGNMENT.REMOVE', 'entityType' => 'CommissionAssignment', 'entityId' => $row->id, 'entityNumber' => mb_substr((string) ($row->subject_label ?? $row->subject), 0, 120),
                'oldValue' => ['kind' => $row->kind, 'subject' => $row->subject, 'user' => $row->user_id]]);
            $row->delete();

            return ['ok' => true, 'id' => $id];
        });
    }
}
