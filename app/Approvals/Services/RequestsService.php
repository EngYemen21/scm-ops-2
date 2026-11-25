<?php

namespace App\Approvals\Services;

use App\Approvals\Models\ApprovalApprover;
use App\Approvals\Models\ApprovalAttachment;
use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalEvent;
use App\Approvals\Models\ApprovalItem;
use App\Approvals\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Approval requests — the section that replaces Odoo's Approvals app.
 *
 * WHO MAY DO WHAT. Raising a request needs `approval.request`. Deciding it is the right of its named approvers and of
 * nobody else — a manager (`approval.manage`) sees every request, cancels, recalls and changes its approvers, but does
 * not decide in an approver's place. A request is seen by its requester, its approvers and the managers.
 *
 * HOW IT IS DECIDED. A request is sent with the approvers of its category (each asked only from his amount on) plus
 * the ones the requester added. They are asked all at once or one after the other (the category's mode); one refusal
 * refuses the request; it is approved when the approvals reach the category's minimum and every required approver has
 * approved (ApprovalFlow::evaluate). The rule is copied onto the request when it is sent, so a later change of the
 * category does not move a running request.
 *
 * WHAT CAME FROM ODOO. While the approvals area follows Odoo (OdooProjection::owns) a request that came from Odoo is
 * Odoo's: it is shown, comments and attachments can be added, and every change of it is refused with
 * APPROVAL_OWNED_BY_SOURCE. Requests raised here work fully, also with a category that came from Odoo.
 *
 * Every change is one transaction on the locked request row, with its timeline entry, audit rows and notifications.
 */
class RequestsService
{
    public const SCOPES = ['waiting', 'mine', 'involved', 'all'];

    private const NUMBERING = 'APR';

    public function __construct(
        private readonly People $people,
        private readonly CategoriesService $categories,
        private readonly NumberingService $numbering,
        private readonly ApprovalLog $log,
    ) {}

    // ───────────── reads ─────────────

    /** The figures of the page head and the tab badges. */
    public function summary(AuthUser $u): array
    {
        $byStatus = fn ($query) => $query->groupBy('status')->selectRaw('status, count(*) as n')->toBase()->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $shape = fn (array $counts) => array_merge(array_fill_keys(ApprovalFlow::STATUSES, 0), $counts) + ['total' => array_sum($counts)];
        $mine = $shape($byStatus($this->people->mine(ApprovalRequest::query(), $u, 'requester')));

        return [
            'waiting' => $this->waiting($u)->count(),
            'mine' => $mine,
            'all' => $this->manages($u) ? $shape($byStatus(ApprovalRequest::query())) : null,
            'canRequest' => $u->can('approval.request'), 'canManage' => $this->manages($u),
            // while true, what came from Odoo is shown and not changed here
            'odooOwns' => CategoriesService::odooOwns(), 'hasOdoo' => ApprovalRequest::where('source_system', 'odoo')->exists(),
        ];
    }

    /**
     * @param  array{scope?:?string, status?:?string, category?:?string, source?:?string, from?:?string, to?:?string, requester?:?string, amountMin?:mixed, amountMax?:mixed}  $f
     */
    public function list(AuthUser $u, Paging $page, array $f): array
    {
        $scope = in_array($f['scope'] ?? null, self::SCOPES, true) ? $f['scope'] : 'mine';
        if ($scope === 'all' && ! $this->manages($u)) {
            throw AppError::forbidden('APPROVAL_ALL_FORBIDDEN', 'عرض كل الطلبات يحتاج صلاحية إدارة الاعتمادات (approval.manage)', 'Seeing every request needs approval.manage');
        }
        $q = match ($scope) {
            'waiting' => $this->waiting($u),
            'involved' => ApprovalRequest::query()->whereHas('approvers', fn ($a) => $this->people->mine($a->whereNotIn('approval_request_approvers.status', ['removed', 'new']), $u, 'user', 'approval_request_approvers')),
            'all' => ApprovalRequest::query(),
            default => $this->people->mine(ApprovalRequest::query(), $u, 'requester'),
        };
        $statuses = array_values(array_intersect(array_map('trim', explode(',', (string) ($f['status'] ?? ''))), ApprovalFlow::STATUSES));
        if ($statuses) {
            $q->whereIn('status', $statuses);
        }
        if (($key = trim((string) ($f['category'] ?? ''))) !== '') {
            $q->where('category_id', ApprovalCategory::where('id', $key)->orWhere('code', $key)->value('id') ?? '-');
        }
        if (($f['source'] ?? '') === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? '') === 'ops') {
            $q->whereNull('source_system');
        }
        if (! empty($f['from'])) {
            $q->whereDate('created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('created_at', '<=', $f['to']);
        }
        if (is_numeric($f['amountMin'] ?? null)) {
            $q->where('amount', '>=', (float) $f['amountMin']);
        }
        if (is_numeric($f['amountMax'] ?? null)) {
            $q->where('amount', '<=', (float) $f['amountMax']);
        }
        if (($who = trim((string) ($f['requester'] ?? ''))) !== '') {
            $q->where('requester_name', Sql::like(), '%'.addcslashes($who, '%_\\').'%');
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '%_\\').'%';
            $q->where(fn ($w) => $w->where('number', Sql::like(), $like)->orWhere('title', Sql::like(), $like)->orWhere('reference', Sql::like(), $like)
                ->orWhere('partner_name', Sql::like(), $like)->orWhere('requester_name', Sql::like(), $like));
        }
        $column = match ($page->sort) {
            'number' => 'number', 'title' => 'title', 'amount' => 'amount', 'status' => 'status', 'requester' => 'requester_name', 'createdAt' => 'created_at',
            'submittedAt' => 'submitted_at', 'decidedAt' => 'decided_at', default => null,
        };
        if ($column) {
            // rows without the value last, on both database engines
            $q->orderByRaw("{$column} IS NULL")->orderBy($column, $page->order)->orderBy('id', $page->order);
        } elseif ($scope === 'waiting') {
            $q->orderBy('submitted_at')->orderBy('id'); // the one that has waited longest first
        } else {
            $q->orderByDesc('created_at')->orderByDesc('id');
        }
        $out = $page->paginate($q->with(['category', 'approvers']), fn (ApprovalRequest $r) => $r);
        $this->loadPeople($out['items']);
        $out['items'] = array_map(fn (ApprovalRequest $r) => $this->row($u, $r), $out['items']);

        return $out + ['scope' => $scope];
    }

    public function get(AuthUser $u, string $idOrNumber): array
    {
        return $this->detail($u, $this->visible($u, $idOrNumber));
    }

    /** The request, when this user may see it: its requester, one of its approvers, or a manager. */
    public function visible(AuthUser $u, string $idOrNumber, bool $lock = false): ApprovalRequest
    {
        $r = $this->find($idOrNumber, $lock);
        // an approver sees a request from the moment it is sent: a draft is its requester's own work
        $asApprover = fn () => ($line = $this->myLine($u, $this->lines($r))) !== null && $line->status !== 'new';
        if (! $this->manages($u) && ! $this->isRequester($u, $r) && ! $asApprover()) {
            throw AppError::forbidden('APPROVAL_NOT_VISIBLE', "الطلب {$r->number} يراه صاحبه ومعتمدوه ومدير الاعتمادات فقط", "Request {$r->number} is visible to its requester, its approvers and the approvals manager only");
        }

        return $r;
    }

    // ───────────── raising and editing ─────────────

    public function create(AuthUser $u, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $dto) {
            $category = $this->categories->find((string) ($dto['category'] ?? ''));
            if (! $category->active) {
                throw AppError::rule('APPROVAL_CATEGORY_INACTIVE', "نوع الطلب «{$category->name_ar}» موقوف — اختر نوعًا آخر", "Category \"{$category->name_en}\" is not active");
            }
            $requester = (object) ['id' => $u->id, 'name_ar' => $u->nameAr];
            if (($behalf = trim((string) ($dto['requester'] ?? ''))) !== '' && strcasecmp($behalf, $u->id) !== 0 && strcasecmp($behalf, $u->username) !== 0) {
                if (! $this->manages($u)) {
                    throw AppError::forbidden('APPROVAL_ON_BEHALF', 'رفع طلب باسم شخص آخر يحتاج صلاحية إدارة الاعتمادات', 'Raising a request on behalf of someone else needs approval.manage');
                }
                $requester = $this->people->find($behalf) ?? throw AppError::notFound('APPROVAL_REQUESTER_NOT_FOUND', 'صاحب الطلب ليس مستخدمًا نشطًا', 'The requester is not an active user');
            }
            $values = $this->values($category, $dto, null);
            $title = trim((string) ($values['title'] ?? ''));
            if ($title === '') {
                $about = $values['partner_name'] ?? $values['reference'] ?? null;
                $title = mb_substr($category->name_ar.($about ? " — {$about}" : ''), 0, 250);
            }
            $r = ApprovalRequest::create(['title' => $title] + $values + [
                'number' => $this->numbering->next(self::NUMBERING), 'category_id' => $category->id, 'status' => 'draft',
                'requester_id' => $requester->id, 'requester_name' => $requester->name_ar, 'created_by_id' => $u->id, 'created_by' => $u->nameAr,
                'approval_mode' => $category->approval_mode, 'min_approvals' => $category->min_approvals,
            ]);
            $this->saveItems($r, $category, is_array($dto['items'] ?? null) ? $dto['items'] : []);
            $this->saveExtraApprovers($u, $r, $category, is_array($dto['approvers'] ?? null) ? $dto['approvers'] : []);
            $this->syncCategoryLines($r, $category);
            $this->log->event($r, 'created', $u, null, $requester->id !== $u->id ? ['onBehalfOf' => $requester->name_ar] : []);
            $this->log->audit($u, $r, 'CREATE', null, $this->snapshot($r));
            $this->log->status($u, $r, null, 'draft');
            if (! empty($dto['submit'])) {
                $this->send($u, $r, $category);
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function update(AuthUser $u, string $idOrNumber, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $dto) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockEdit($u, $r));
            $category = ApprovalCategory::findOrFail($r->category_id);
            $before = $this->snapshot($r);
            $values = $this->values($category, $dto, $r);
            if (array_key_exists('title', $values) && trim((string) $values['title']) === '') {
                unset($values['title']); // a request keeps its title: an empty one is not a change
            }
            $r->fill($values)->save();
            if (is_array($dto['items'] ?? null)) {
                $this->saveItems($r, $category, $dto['items']);
            }
            if (is_array($dto['approvers'] ?? null)) {
                $this->saveExtraApprovers($u, $r, $category, $dto['approvers']);
            }
            $this->syncCategoryLines($r, $category);
            $after = $this->snapshot($r->refresh());
            $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== ($before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($changed) {
                $this->log->event($r, 'edited', $u, null, ['fields' => $changed]);
                $this->log->audit($u, $r, 'UPDATE', array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function submit(AuthUser $u, string $idOrNumber): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockSubmit($u, $r));
            $this->send($u, $r, ApprovalCategory::findOrFail($r->category_id));

            return $r->id;
        });

        return $this->get($u, $id);
    }

    // ───────────── deciding ─────────────

    public function approve(AuthUser $u, string $idOrNumber, ?string $comment = null): array
    {
        return $this->decide($u, $idOrNumber, 'approved', $comment);
    }

    public function refuse(AuthUser $u, string $idOrNumber, ?string $comment): array
    {
        if (mb_strlen(trim((string) $comment)) < 3) {
            throw AppError::validation('APPROVAL_COMMENT_REQUIRED', 'اكتب سبب الرفض (3 أحرف على الأقل)', 'A reason is required to refuse (3 characters at least)', [['path' => 'comment', 'message' => 'required']]);
        }

        return $this->decide($u, $idOrNumber, 'refused', $comment);
    }

    private function decide(AuthUser $u, string $idOrNumber, string $decision, ?string $comment): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $decision, $comment) {
            $r = $this->find($idOrNumber, true);
            $lines = $this->lines($r);
            $this->refuse_if($this->blockDecide($u, $r, $lines));
            $line = $this->myLine($u, $lines);
            $comment = ($c = trim((string) $comment)) !== '' ? mb_substr($c, 0, 2000) : null;
            // a person of Odoo who decides here is an OPS user from now on
            $line->forceFill(['status' => $decision, 'comment' => $comment, 'decided_at' => now(), 'user_id' => $u->id])->save();
            $this->log->event($r, $decision, $u, $comment);
            $this->log->audit($u, $r, $decision === 'approved' ? 'APPROVE' : 'REFUSE', null, ['approver' => $u->username, 'comment' => $comment]);
            $this->settle($u, $r, $decision === 'refused' ? $comment : null);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** An approver takes his decision back: his line is undecided again and the request is worked out anew. */
    public function withdraw(AuthUser $u, string $idOrNumber, ?string $comment = null): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $comment) {
            $r = $this->find($idOrNumber, true);
            $lines = $this->lines($r);
            $this->refuse_if($this->blockWithdraw($u, $r, $lines));
            $line = $this->myLine($u, $lines);
            $was = $line->status;
            $comment = ($c = trim((string) $comment)) !== '' ? mb_substr($c, 0, 2000) : null;
            $line->forceFill(['status' => 'waiting', 'comment' => null, 'decided_at' => null, 'notified_at' => null, 'user_id' => $u->id])->save();
            $this->log->event($r, 'withdrawn', $u, $comment, ['was' => $was]);
            $this->log->audit($u, $r, 'WITHDRAW', ['decision' => $was], ['approver' => $u->username, 'comment' => $comment]);
            if ($r->status === 'refused') {
                // the refusal is gone: the request waits again
                ApprovalFlow::assert('refused', 'pending');
                $r->forceFill(['status' => 'pending', 'decided_at' => null])->save();
                $this->log->status($u, $r, 'refused', 'pending', $comment);
                $this->log->tell([$this->requesterId($r)], $r, "عاد طلبك {$r->number} إلى الانتظار: سحب {$u->nameAr} رفضه",
                    "Your request {$r->number} is waiting again: {$u->nameEn} took the refusal back", $u);
            }
            $this->settle($u, $r);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    // ───────────── cancelling, recalling ─────────────

    public function cancel(AuthUser $u, string $idOrNumber, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $reason) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockCancel($u, $r));
            $reason = ($x = trim((string) $reason)) !== '' ? mb_substr($x, 0, 1000) : null;
            if ($r->status !== 'draft' && mb_strlen((string) $reason) < 3) {
                throw AppError::validation('APPROVAL_REASON_REQUIRED', 'اكتب سبب الإلغاء (3 أحرف على الأقل)', 'A reason is required to cancel (3 characters at least)', [['path' => 'reason', 'message' => 'required']]);
            }
            $from = $r->status;
            ApprovalFlow::assert($from, 'cancelled');
            $asked = $from === 'pending' ? $this->lines($r)->where('status', 'pending') : collect();
            $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $u->nameAr, 'cancel_reason' => $reason])->save();
            $this->log->event($r, 'cancelled', $u, $reason, ['from' => $from]);
            $this->log->audit($u, $r, 'CANCEL', ['status' => $from], ['status' => 'cancelled', 'reason' => $reason]);
            $this->log->status($u, $r, $from, 'cancelled', $reason);
            if ($from !== 'draft') {
                $because = $reason ? " — {$reason}" : '';
                $this->log->tell([...$asked->map(fn (ApprovalApprover $a) => $this->people->userId($a->user_id, $a->user_login))->all(), $this->requesterId($r)], $r,
                    "أُلغي طلب الاعتماد {$r->number}: {$r->title}{$because}", "Approval request {$r->number} was cancelled: {$r->title}{$because}", $u);
                $this->log->activity($u, $r, "أُلغي طلب الاعتماد {$r->number}: {$r->title}{$because}", "Approval request {$r->number} cancelled: {$r->title}");
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** Back to a draft, to be corrected and sent again: every decision given so far is cleared (the timeline keeps them). */
    public function reopen(AuthUser $u, string $idOrNumber): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockReopen($u, $r));
            $from = $r->status;
            ApprovalFlow::assert($from, 'draft');
            $lines = $this->lines($r);
            $asked = $from === 'pending' ? $lines->where('status', 'pending') : collect();
            ApprovalApprover::where('request_id', $r->id)->where('status', '!=', 'removed')
                ->update(['status' => 'new', 'comment' => null, 'decided_at' => null, 'notified_at' => null, 'updated_at' => now()]);
            $r->forceFill(['status' => 'draft', 'submitted_at' => null, 'decided_at' => null, 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null])->save();
            $this->log->event($r, 'reopened', $u, null, ['from' => $from]);
            $this->log->audit($u, $r, 'REOPEN', ['status' => $from], ['status' => 'draft']);
            $this->log->status($u, $r, $from, 'draft');
            $this->log->tell($asked->map(fn (ApprovalApprover $a) => $this->people->userId($a->user_id, $a->user_login))->all(), $r,
                "سُحب طلب الاعتماد {$r->number} للتعديل — لا قرار مطلوب منك الآن", "Approval request {$r->number} was recalled for changes — no decision is needed from you now", $u);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    // ───────────── approvers of one request ─────────────

    /** @param  array{user:string, required?:mixed, sequence?:mixed}  $dto */
    public function addApprover(AuthUser $u, string $idOrNumber, array $dto): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $dto) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockApprovers($u, $r));
            $category = ApprovalCategory::findOrFail($r->category_id);
            $line = $this->newLine($r, $category, $dto, $this->manages($u) && ! $this->isRequester($u, $r) ? 'manager' : 'requester', $this->lines($r));
            $this->log->event($r, 'approver_added', $u, null, ['approver' => $line->user_name, 'required' => (bool) $line->required]);
            $this->log->audit($u, $r, 'APPROVER.ADD', null, ['approver' => $line->user_name, 'required' => (bool) $line->required, 'sequence' => (int) $line->sequence]);
            if ($r->status === 'pending') {
                $line->forceFill(['status' => 'waiting'])->save();
                $this->settle($u, $r);
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function removeApprover(AuthUser $u, string $idOrNumber, string $lineId, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($u, $idOrNumber, $lineId, $reason) {
            $r = $this->find($idOrNumber, true);
            $this->refuse_if($this->blockApprovers($u, $r));
            $lines = $this->lines($r);
            $line = $lines->firstWhere('id', $lineId) ?? throw AppError::notFound('APPROVAL_APPROVER_NOT_FOUND', 'هذا المعتمد ليس على الطلب', 'This approver is not on the request');
            $reason = ($x = trim((string) $reason)) !== '' ? mb_substr($x, 0, 500) : null;
            if ($r->status === 'draft') {
                if ($line->origin === 'category') {
                    throw AppError::rule('APPROVAL_APPROVER_FROM_CATEGORY', "{$line->user_name} معتمد من نوع الطلب نفسه — يتبع إعداد النوع ولا يُزال من المسودة", "{$line->user_name} is an approver of the category itself — he follows the category and cannot be taken off a draft");
                }
                $line->delete(); // a draft is not a record of anything yet: the line simply goes
            } else {
                if (! in_array($line->status, ApprovalApprover::OPEN, true)) {
                    throw AppError::rule('APPROVAL_ALREADY_DECIDED', "{$line->user_name} قرّر مسبقًا — قراره يبقى في سجل الطلب", "{$line->user_name} has already decided — the decision stays on record");
                }
                if ($lines->filter(fn (ApprovalApprover $a) => ! in_array($a->status, ApprovalApprover::OUT, true))->count() <= 1) {
                    throw AppError::rule('APPROVAL_LAST_APPROVER', 'لا يبقى الطلب بلا معتمد — أضف معتمدًا آخر أولًا أو ألغِ الطلب', 'A request cannot be left without an approver — add another one first, or cancel the request');
                }
                $line->forceFill(['status' => 'removed'])->save();
                $this->log->tell([$this->people->userId($line->user_id, $line->user_login)], $r, "لم يعد قرارك مطلوبًا على طلب الاعتماد {$r->number}", "Your decision is no longer needed on approval request {$r->number}", $u);
            }
            $this->log->event($r, 'approver_removed', $u, $reason, ['approver' => $line->user_name]);
            $this->log->audit($u, $r, 'APPROVER.REMOVE', ['approver' => $line->user_name, 'status' => $line->getOriginal('status')], ['reason' => $reason]);
            if ($r->status === 'pending') {
                $this->settle($u, $r);
            }

            return $r->id;
        });

        return $this->get($u, $id);
    }

    public function comment(AuthUser $u, string $idOrNumber, string $text): array
    {
        $text = mb_substr(trim($text), 0, 4000);
        if ($text === '') {
            throw AppError::validation('APPROVAL_COMMENT_EMPTY', 'اكتب نص التعليق', 'The comment is empty', [['path' => 'text', 'message' => 'required']]);
        }
        $id = DB::transaction(function () use ($u, $idOrNumber, $text) {
            $r = $this->visible($u, $idOrNumber, true);
            $this->log->event($r, 'comment', $u, $text);
            $this->log->audit($u, $r, 'COMMENT', null, mb_substr($text, 0, 500));
            $excerpt = mb_strlen($text) > 120 ? mb_substr($text, 0, 120).'…' : $text;
            $this->log->tell($this->audience($r), $r, "تعليق من {$u->nameAr} على طلب الاعتماد {$r->number}: {$excerpt}", "{$u->nameEn} commented on approval request {$r->number}: {$excerpt}", $u);

            return $r->id;
        });

        return $this->get($u, $id);
    }

    /** One more thing in the timeline, written by another service of the module (attachments). */
    public function note(AuthUser $u, ApprovalRequest $r, string $kind, string $auditAction, array $meta): void
    {
        $this->log->event($r, $kind, $u, null, $meta);
        $this->log->audit($u, $r, $auditAction, null, $meta);
    }

    public function manages(AuthUser $u): bool
    {
        return $u->can('approval.manage');
    }

    /** Is this request Odoo's to change (it came from Odoo and the area still follows Odoo)? */
    public function owned(ApprovalRequest $r): bool
    {
        return $r->fromOdoo() && CategoriesService::odooOwns();
    }

    // ───────────── the mechanics ─────────────

    /** draft → pending: the fields the category requires are there, the approvers are set, the first of them are asked. */
    private function send(AuthUser $u, ApprovalRequest $r, ApprovalCategory $category): void
    {
        ApprovalFlow::assert($r->status, 'pending');
        if ($missing = $this->missing($r, $category)) {
            $names = implode('، ', array_map(fn (array $m) => $m['labelAr'], $missing));
            throw AppError::validation('APPROVAL_FIELDS_REQUIRED', "لا يُرسل الطلب قبل إكمال ما يشترطه نوع «{$category->name_ar}»: {$names}",
                "The request cannot be sent before what category \"{$category->name_en}\" requires is filled in: ".implode(', ', array_map(fn (array $m) => $m['labelEn'], $missing)),
                array_map(fn (array $m) => ['path' => $m['path'], 'message' => 'required'], $missing));
        }
        $unmatched = $this->syncCategoryLines($r, $category);
        // (a request that came from Odoo and was cancelled there carries `cancelled` lines: sent again, they are asked again)
        $lines = $this->lines($r)->filter(fn (ApprovalApprover $a) => $a->status !== 'removed');
        // nobody is asked who cannot answer: a person of Odoo with no OPS user yet (a required approver of the category,
        // or a line the request brought from Odoo)
        $nobody = array_values(array_unique([...$unmatched, ...$lines->filter(fn (ApprovalApprover $a) => $this->people->userId($a->user_id, $a->user_login) === null)
            ->map(fn (ApprovalApprover $a) => (string) ($a->user_name ?: $a->user_login))->all()]));
        if ($nobody) {
            $names = implode('، ', $nobody);
            throw AppError::rule('APPROVAL_APPROVER_UNMATCHED', "معتمد على هذا الطلب ليس له مستخدم في النظام بعد: {$names} — أنشئ له مستخدمًا ببريده في أودو، أو أزله من الطلب (أو من معتمدي النوع إن كان منه)",
                "An approver of this request has no OPS user yet: {$names} — create a user with his Odoo e-mail, or take him off the request (or off the category's approvers)");
        }
        if ($lines->isEmpty()) {
            throw AppError::rule('APPROVAL_NO_APPROVERS', 'لا معتمد لهذا الطلب — أضف معتمدًا واحدًا على الأقل، أو اطلب من مدير الاعتمادات تحديد معتمدي هذا النوع',
                'This request has no approver — add at least one, or ask the approvals manager to set the approvers of this category');
        }
        ApprovalApprover::whereIn('id', $lines->pluck('id')->all())->update(['status' => 'new', 'comment' => null, 'decided_at' => null, 'notified_at' => null, 'updated_at' => now()]);
        $r->forceFill(['status' => 'pending', 'approval_mode' => $category->approval_mode, 'min_approvals' => max(1, (int) $category->min_approvals), 'submitted_at' => now(), 'decided_at' => null,
            'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null])->save();
        $this->log->event($r, 'submitted', $u, null, ['approvers' => $lines->count(), 'mode' => $r->approval_mode, 'minApprovals' => (int) $r->min_approvals]);
        $this->log->audit($u, $r, 'SUBMIT', null, ['approvers' => $lines->pluck('user_name')->values()->all(), 'mode' => $r->approval_mode, 'minApprovals' => (int) $r->min_approvals]);
        $this->log->status($u, $r, 'draft', 'pending');
        $this->log->activity($u, $r, "طلب اعتماد {$r->number} من {$r->requester_name}: {$r->title}", "Approval request {$r->number} from {$r->requester_name}: {$r->title}");
        $this->settle($u, $r);
    }

    /**
     * After anything that changes the decisions of a pending request: who is asked now, what the request has become,
     * and who has to be told (the approvers whose turn it has just become; the requester when it is decided).
     */
    private function settle(AuthUser $by, ApprovalRequest $r, ?string $refusalReason = null): void
    {
        $lines = $this->lines($r);
        $eval = ApprovalFlow::evaluate($r->approval_mode, (int) $r->min_approvals, $lines->map(fn (ApprovalApprover $a) => ['id' => $a->id, 'status' => $a->status, 'required' => (bool) $a->required])->all());
        $asked = [];
        foreach ($lines as $line) {
            $to = $eval['lines'][$line->id] ?? null;
            if ($to !== null && $to !== $line->status) {
                $line->status = $to;
                if ($to === 'pending') {
                    $line->notified_at = now();
                    $asked[] = $this->people->userId($line->user_id, $line->user_login);
                }
                $line->save();
            }
        }
        if ($eval['status'] !== $r->status) {
            $from = $r->status;
            ApprovalFlow::assert($from, $eval['status']);
            $r->forceFill(['status' => $eval['status'], 'decided_at' => now()])->save();
            $this->log->status($by, $r, $from, $eval['status'], $refusalReason);
            if ($eval['status'] === 'approved') {
                $this->log->tell([$this->requesterId($r)], $r, "اعتُمد طلبك {$r->number}: {$r->title}", "Your request {$r->number} was approved: {$r->title}", $by);
                $this->log->activity($by, $r, "اعتُمد طلب الاعتماد {$r->number}: {$r->title}", "Approval request {$r->number} approved: {$r->title}");
            } else {
                $why = $refusalReason ? " — {$refusalReason}" : '';
                $this->log->tell([$this->requesterId($r)], $r, "رفض {$by->nameAr} طلبك {$r->number}: {$r->title}{$why}", "{$by->nameEn} refused your request {$r->number}: {$r->title}{$why}", $by);
                $this->log->activity($by, $r, "رُفض طلب الاعتماد {$r->number}: {$r->title}{$why}", "Approval request {$r->number} refused: {$r->title}");
            }

            return;
        }
        $amount = $r->amount !== null ? ' — '.number_format((float) $r->amount, 2).' '.$r->currency : '';
        $this->log->tell($asked, $r, "طلب اعتماد بانتظار قرارك: {$r->title}{$amount} — من {$r->requester_name}", "An approval request is waiting for your decision: {$r->title}{$amount} — from {$r->requester_name}", $by);
    }

    /**
     * Keeps the approver lines that come from the category in step with it (a draft only): each category approver who
     * is asked at this amount, is an OPS user and is not the requester himself (unless the category allows that).
     *
     * @return string[] names of REQUIRED category approvers who have no OPS user — the request cannot be sent without them
     */
    private function syncCategoryLines(ApprovalRequest $r, ApprovalCategory $category): array
    {
        $requester = $this->requesterId($r);
        $wanted = [];
        $unmatched = [];
        foreach ($category->approvers()->get() as $a) {
            if ($a->min_amount !== null && (float) ($r->amount ?? 0) < (float) $a->min_amount) {
                continue;
            }
            $userId = $this->people->userId($a->user_id, $a->user_login);
            if ($userId === null) {
                if ($a->required) {
                    $unmatched[] = (string) ($a->user_name ?: $a->user_login);
                }

                continue;
            }
            if (! $category->allow_self_approval && $requester !== null && strcasecmp($userId, $requester) === 0) {
                continue; // nobody approves his own request
            }
            $wanted[$userId] ??= ['user_name' => $a->user_name, 'sequence' => (int) $a->sequence, 'required' => (bool) $a->required];
        }
        foreach ($this->lines($r) as $line) {
            $person = (string) ($this->people->userId($line->user_id, $line->user_login) ?? '');
            if ($person !== '' && isset($wanted[$person])) {
                // one line per person, and it is the category's: a line the requester added for him, or one that came
                // from Odoo, becomes that line (and a person of Odoo who has an OPS user by now is linked to it)
                $line->forceFill(['origin' => 'category', 'user_id' => $person, 'sequence' => $wanted[$person]['sequence'], 'required' => $wanted[$person]['required']]
                    + ($line->status === 'removed' ? ['status' => 'new'] : []) + ($line->user_name ? [] : ['user_name' => $wanted[$person]['user_name']]))->save();
                unset($wanted[$person]);
            } elseif ($line->origin === 'category') {
                $line->delete(); // no longer asked by the category (or not at this amount): a draft simply follows
            }
        }
        foreach ($wanted as $userId => $w) {
            ApprovalApprover::create(['request_id' => $r->id, 'user_id' => $userId, 'origin' => 'category', 'status' => 'new'] + $w);
        }
        // the approvers the requester added are asked after the category's own, in the order he added them
        $last = (int) ApprovalApprover::where('request_id', $r->id)->where('origin', 'category')->max('sequence');
        foreach ($this->lines($r)->where('origin', '!=', 'category')->values() as $i => $extra) {
            if ((int) $extra->sequence !== $last + 10 * ($i + 1)) {
                $extra->forceFill(['sequence' => $last + 10 * ($i + 1)])->save();
            }
        }

        return $unmatched;
    }

    /**
     * The approvers the requester adds himself to a draft, replaced as a whole.
     *
     * @param  array<int, array{user:string, required?:mixed, sequence?:mixed}>  $given
     */
    private function saveExtraApprovers(AuthUser $u, ApprovalRequest $r, ApprovalCategory $category, array $given): void
    {
        ApprovalApprover::where('request_id', $r->id)->whereIn('origin', ['requester', 'manager'])->delete();
        $lines = $this->lines($r);
        foreach (ApprovalFlow::rows($given) as $dto) {
            $lines->push($this->newLine($r, $category, $dto, 'requester', $lines));
        }
    }

    /** One more approver line on a request: an active user who can open the section, not on the request already. */
    private function newLine(ApprovalRequest $r, ApprovalCategory $category, array $dto, string $origin, Collection $lines): ApprovalApprover
    {
        $user = $this->people->find((string) ($dto['user'] ?? ''))
            ?? throw AppError::validation('APPROVAL_APPROVER_UNKNOWN', 'المعتمد المحدد ليس مستخدمًا نشطًا في النظام', 'The approver is not an active user', [['path' => 'user', 'message' => 'unknown user']]);
        if (! $this->people->canOpen($user->id)) {
            throw AppError::rule('APPROVAL_APPROVER_NO_ACCESS', "المستخدم {$user->name_ar} لا يملك صلاحية فتح قسم الاعتمادات (page.approvals) فلا يستطيع اتخاذ قرار",
                "User {$user->name_en} cannot open the approvals section (page.approvals), so he cannot decide");
        }
        $requester = $this->requesterId($r);
        if (! $category->allow_self_approval && $requester !== null && strcasecmp($user->id, $requester) === 0) {
            throw AppError::rule('APPROVAL_SELF_DECISION', 'صاحب الطلب لا يعتمد طلبه في هذا النوع — اختر معتمدًا آخر', 'The requester cannot approve his own request in this category — pick another approver');
        }
        $active = $lines->filter(fn (ApprovalApprover $a) => $a->status !== 'removed');
        if ($active->contains(fn (ApprovalApprover $a) => $a->user_id !== null && strcasecmp((string) $a->user_id, $user->id) === 0)) {
            throw AppError::rule('APPROVAL_APPROVER_DUPLICATE', "{$user->name_ar} معتمد على هذا الطلب مسبقًا", "{$user->name_en} is already an approver of this request");
        }
        $sequence = isset($dto['sequence']) && is_numeric($dto['sequence']) ? (int) $dto['sequence'] : ((int) ($active->max('sequence') ?? 0)) + 10;

        return ApprovalApprover::create(['request_id' => $r->id, 'user_id' => $user->id, 'user_name' => $user->name_ar, 'sequence' => $sequence, 'required' => ! empty($dto['required']),
            'status' => 'new', 'origin' => $origin]);
    }

    /** The lines of a draft are replaced as a whole. @param  array<int, array{product?:?string, description?:?string, quantity?:mixed, uom?:?string}>  $items */
    private function saveItems(ApprovalRequest $r, ApprovalCategory $category, array $items): void
    {
        ApprovalItem::where('request_id', $r->id)->delete();
        if (ApprovalFlow::fields($category->fields)['items'] === 'no') {
            return;
        }
        foreach (ApprovalFlow::rows($items) as $i => $it) {
            $product = null;
            if (($key = trim((string) ($it['product'] ?? ''))) !== '') {
                $product = Product::where('id', $key)->orWhere('sku', $key)->first()
                    ?? throw AppError::validation('APPROVAL_PRODUCT_NOT_FOUND', "الصنف {$key} غير موجود", "Product {$key} not found", [['path' => "items.{$i}.product", 'message' => 'unknown product']]);
            }
            $description = trim((string) ($it['description'] ?? '')) ?: (string) ($product?->name_ar ?? '');
            if ($description === '') {
                throw AppError::validation('APPROVAL_ITEM_DESCRIPTION', 'اكتب وصف البند أو اختر صنفًا', 'Describe the line or pick a product', [['path' => "items.{$i}.description", 'message' => 'required']]);
            }
            $qty = is_numeric($it['quantity'] ?? null) ? round((float) $it['quantity'], 3) : 0.0;
            if ($qty <= 0) {
                throw AppError::validation('APPROVAL_ITEM_QUANTITY', 'كمية البند يجب أن تكون أكبر من صفر', 'The quantity of a line must be positive', [['path' => "items.{$i}.quantity", 'message' => 'must be positive']]);
            }
            ApprovalItem::create(['request_id' => $r->id, 'line_no' => $i + 1, 'product_id' => $product?->id, 'description' => mb_substr($description, 0, 500), 'quantity' => $qty,
                'uom' => ($uom = trim((string) ($it['uom'] ?? ''))) !== '' ? mb_substr($uom, 0, 60) : null]);
        }
    }

    /**
     * The columns of a request from the validated payload. A field the category does not use is not kept; on an update
     * only what was sent changes.
     */
    private function values(ApprovalCategory $category, array $dto, ?ApprovalRequest $existing): array
    {
        $rules = ApprovalFlow::fields($category->fields);
        $has = fn (string $k) => array_key_exists($k, $dto);
        $sent = fn (string $k) => ! $existing || $has($k);
        $text = fn (string $k, int $max) => ($s = trim((string) ($dto[$k] ?? ''))) !== '' ? mb_substr($s, 0, $max) : null;
        $off = fn (string $field) => $rules[$field] === 'no';
        $v = [];
        if ($sent('title')) {
            $v['title'] = (string) $text('title', 250);
        }
        if ($sent('amount')) {
            $v['amount'] = $off('amount') || ! is_numeric($dto['amount'] ?? null) ? null : round((float) $dto['amount'], 2);
        }
        if ($sent('quantity')) {
            $v['quantity'] = $off('quantity') || ! is_numeric($dto['quantity'] ?? null) ? null : round((float) $dto['quantity'], 3);
        }
        if ($sent('date')) {
            $v['request_date'] = $off('date') || empty($dto['date']) ? null : Carbon::parse((string) $dto['date'])->toDateString();
        }
        if ($sent('periodStart') || $sent('periodEnd')) {
            $start = $has('periodStart') ? (empty($dto['periodStart']) ? null : Carbon::parse((string) $dto['periodStart'])) : $existing?->period_start;
            $end = $has('periodEnd') ? (empty($dto['periodEnd']) ? null : Carbon::parse((string) $dto['periodEnd'])) : $existing?->period_end;
            if ($off('period')) {
                $start = $end = null;
            }
            if ($start && $end && $end->lt($start)) {
                throw AppError::validation('APPROVAL_PERIOD', 'نهاية الفترة قبل بدايتها', 'The period ends before it starts', [['path' => 'periodEnd', 'message' => 'before the start']]);
            }
            $v['period_start'] = $start;
            $v['period_end'] = $end;
        }
        if ($sent('location')) {
            $v['location'] = $off('location') ? null : $text('location', 250);
        }
        if ($sent('reference')) {
            $v['reference'] = $off('reference') ? null : $text('reference', 120);
        }
        if ($sent('paymentMethod')) {
            $v['payment_method'] = $off('paymentMethod') ? null : $text('paymentMethod', 20);
        }
        if ($sent('reason')) {
            $v['reason'] = $text('reason', 8000);
        }
        if (! $existing || $has('partnerType') || $has('partner') || $has('partnerName')) {
            $v += $off('partner') ? ['partner_type' => null, 'partner_id' => null, 'partner_name' => null] : $this->partner($dto);
        }

        return $v;
    }

    /** The partner a request is about: an OPS customer or supplier (by code or id), or a name typed by the requester. */
    private function partner(array $dto): array
    {
        $type = $dto['partnerType'] ?? null;
        $key = trim((string) ($dto['partner'] ?? ''));
        $name = trim((string) ($dto['partnerName'] ?? ''));
        if (in_array($type, ['customer', 'supplier'], true) && $key !== '') {
            $p = ($type === 'customer' ? Customer::query() : Supplier::query())->where(fn ($w) => $w->where('id', $key)->orWhere('code', $key))->first()
                ?? throw AppError::validation('APPROVAL_PARTNER_NOT_FOUND', $type === 'customer' ? "العميل {$key} غير موجود" : "المورد {$key} غير موجود", ucfirst($type)." {$key} not found", [['path' => 'partner', 'message' => 'unknown']]);

            return ['partner_type' => $type, 'partner_id' => $p->id, 'partner_name' => $p->name_ar];
        }
        if ($name !== '') {
            return ['partner_type' => in_array($type, ApprovalFlow::PARTNER_TYPES, true) ? $type : 'other', 'partner_id' => null, 'partner_name' => mb_substr($name, 0, 250)];
        }

        return ['partner_type' => null, 'partner_id' => null, 'partner_name' => null];
    }

    /** What the category requires and the request does not carry yet. @return array<int, array{path:string, labelAr:string, labelEn:string}> */
    private function missing(ApprovalRequest $r, ApprovalCategory $category): array
    {
        $rules = ApprovalFlow::fields($category->fields);
        $checks = [
            'amount' => ['amount', 'المبلغ', 'amount', fn () => (float) $r->amount > 0],
            'date' => ['date', 'التاريخ', 'date', fn () => $r->request_date !== null],
            'period' => ['periodStart', 'الفترة (من — إلى)', 'period (from — to)', fn () => $r->period_start !== null && $r->period_end !== null],
            'partner' => ['partner', 'الجهة (عميل / مورد)', 'partner', fn () => trim((string) $r->partner_name) !== ''],
            'reference' => ['reference', 'المرجع', 'reference', fn () => trim((string) $r->reference) !== ''],
            'quantity' => ['quantity', 'الكمية', 'quantity', fn () => (float) $r->quantity > 0],
            'items' => ['items', 'بند واحد على الأقل', 'at least one line', fn () => ApprovalItem::where('request_id', $r->id)->exists()],
            'location' => ['location', 'المكان', 'location', fn () => trim((string) $r->location) !== ''],
            'paymentMethod' => ['paymentMethod', 'طريقة الدفع', 'payment method', fn () => trim((string) $r->payment_method) !== ''],
            'document' => ['attachments', 'مرفق واحد على الأقل', 'at least one attachment', fn () => ApprovalAttachment::where('request_id', $r->id)->whereNull('removed_at')->exists()],
        ];
        $missing = [];
        foreach ($checks as $field => [$path, $ar, $en, $ok]) {
            if ($rules[$field] === 'required' && ! $ok()) {
                $missing[] = ['path' => $path, 'labelAr' => $ar, 'labelEn' => $en];
            }
        }

        return $missing;
    }

    // ───────────── who may do what ─────────────

    private function blockOwned(ApprovalRequest $r): ?AppError
    {
        return $this->owned($r) ? AppError::rule('APPROVAL_OWNED_BY_SOURCE',
            "الطلب {$r->number} مصدره أودو وما زال يُدار هناك — يُعرض هنا ولا يُغيَّر. قراره وتعديله في أودو؛ ويمكنك إضافة تعليق أو مرفق هنا.",
            "Request {$r->number} comes from Odoo and is still managed there — it is shown here and not changed. Decide or edit it in Odoo; a comment or an attachment can be added here.") : null;
    }

    private function blockNotYours(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        return $this->isRequester($u, $r) || $this->manages($u) ? null
            : AppError::forbidden('APPROVAL_NOT_YOURS', "الطلب {$r->number} ليس طلبك — يغيّره صاحبه أو مدير الاعتمادات", "Request {$r->number} is not yours — its requester or the approvals manager changes it");
    }

    private function blockEdit(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        return $this->blockOwned($r) ?? $this->blockNotYours($u, $r) ?? ($r->status === 'draft' ? null
            : AppError::rule('APPROVAL_NOT_DRAFT', "الطلب {$r->number} أُرسل — اسحبه للتعديل أولًا ثم عدّله وأعد إرساله", "Request {$r->number} was sent — recall it first, then change it and send it again"));
    }

    private function blockSubmit(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        return $this->blockOwned($r) ?? $this->blockNotYours($u, $r) ?? ($r->status === 'draft' ? null
            : AppError::rule('APPROVAL_TRANSITION', "الطلب {$r->number} ليس مسودة — حالته الآن {$r->status}", "Request {$r->number} is not a draft — it is {$r->status}"));
    }

    private function blockDecide(AuthUser $u, ApprovalRequest $r, Collection $lines): ?AppError
    {
        if ($e = $this->blockOwned($r)) {
            return $e;
        }
        $line = $this->myLine($u, $lines);
        if (! $line || $line->status === 'cancelled') {
            return AppError::forbidden('APPROVAL_NOT_APPROVER', "لست من معتمدي الطلب {$r->number} — القرار لمعتمديه المسمَّين فقط", "You are not an approver of request {$r->number} — only its named approvers decide");
        }
        if ($r->status !== 'pending') {
            return AppError::rule('APPROVAL_NOT_PENDING', "الطلب {$r->number} لا ينتظر قرارًا الآن — حالته {$r->status}", "Request {$r->number} is not waiting for a decision — it is {$r->status}");
        }
        if (in_array($line->status, ['approved', 'refused'], true)) {
            return AppError::rule('APPROVAL_ALREADY_DECIDED', 'سجّلت قرارك على هذا الطلب مسبقًا — اسحب قرارك أولًا إن أردت تغييره', 'You have already decided this request — take your decision back first to change it');
        }
        if ($line->status !== 'pending') {
            return AppError::rule('APPROVAL_NOT_YOUR_TURN', 'لم يحن دورك بعد — الطلب عند معتمد يسبقك في الترتيب', 'It is not your turn yet — the request is with an approver before you');
        }
        if ($this->isRequester($u, $r) && ! ApprovalCategory::where('id', $r->category_id)->value('allow_self_approval')) {
            return AppError::rule('APPROVAL_SELF_DECISION', 'صاحب الطلب لا يعتمد طلبه في هذا النوع', 'The requester cannot decide his own request in this category');
        }

        return null;
    }

    private function blockWithdraw(AuthUser $u, ApprovalRequest $r, Collection $lines): ?AppError
    {
        if ($e = $this->blockOwned($r)) {
            return $e;
        }
        $line = $this->myLine($u, $lines);
        if (! $line) {
            return AppError::forbidden('APPROVAL_NOT_APPROVER', "لست من معتمدي الطلب {$r->number}", "You are not an approver of request {$r->number}");
        }
        if (! in_array($line->status, ['approved', 'refused'], true)) {
            return AppError::rule('APPROVAL_NOTHING_TO_WITHDRAW', 'لم تسجّل قرارًا على هذا الطلب حتى تسحبه', 'You have no decision on this request to take back');
        }
        if ($r->status === 'pending') {
            return null;
        }
        if ($r->status === 'refused' && $line->status === 'refused') {
            // (history that came from Odoo may hold a request two approvers refused: one of them taking his refusal
            // back would leave it refused all the same — its requester takes it back to a draft instead)
            return $lines->where('status', 'refused')->count() === 1 ? null
                : AppError::rule('APPROVAL_FINAL', "الطلب {$r->number} رفضه أكثر من معتمد — سحب رفضك وحده لا يعيده؛ يعيده صاحبه إلى مسودة", "Request {$r->number} was refused by more than one approver — taking your refusal back alone does not reopen it; its requester takes it back to a draft");
        }

        return AppError::rule('APPROVAL_FINAL', "الطلب {$r->number} انتهى ({$r->status}) — لا يُسحب قرار بعد انتهائه", "Request {$r->number} is closed ({$r->status}) — a decision cannot be taken back after that");
    }

    private function blockCancel(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        if ($e = $this->blockOwned($r) ?? $this->blockNotYours($u, $r)) {
            return $e;
        }
        if ($r->status === 'cancelled') {
            return AppError::rule('APPROVAL_TRANSITION', "الطلب {$r->number} ملغى مسبقًا", "Request {$r->number} is already cancelled");
        }
        if (in_array($r->status, ['approved', 'refused'], true) && ! $this->manages($u)) {
            return AppError::rule('APPROVAL_CANCEL_DECIDED', "الطلب {$r->number} صدر قراره — إلغاؤه بعد القرار لمدير الاعتمادات فقط", "Request {$r->number} has been decided — only the approvals manager cancels it after that");
        }

        return null;
    }

    private function blockReopen(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        return $this->blockOwned($r) ?? $this->blockNotYours($u, $r) ?? (ApprovalFlow::can($r->status, 'draft') ? null
            : AppError::rule('APPROVAL_TRANSITION', $r->status === 'approved' ? "الطلب {$r->number} معتمد — لا يُعاد إلى مسودة" : "الطلب {$r->number} مسودة أصلًا", "Request {$r->number} cannot go back to a draft from {$r->status}"));
    }

    /** Changing the approvers: the requester on his own draft, a manager on a draft or a pending request. */
    private function blockApprovers(AuthUser $u, ApprovalRequest $r): ?AppError
    {
        if ($e = $this->blockOwned($r) ?? $this->blockNotYours($u, $r)) {
            return $e;
        }
        if ($r->status === 'draft') {
            return null;
        }
        if ($r->status !== 'pending') {
            return AppError::rule('APPROVAL_FINAL', "الطلب {$r->number} انتهى ({$r->status}) — لا يتغيّر معتمدوه", "Request {$r->number} is closed ({$r->status}) — its approvers no longer change");
        }

        return $this->manages($u) ? null
            : AppError::forbidden('APPROVAL_MANAGE_ONLY', 'تغيير معتمدي طلب مُرسل لمدير الاعتمادات فقط — اسحب الطلب للتعديل إن أردت تغييرهم', 'Only the approvals manager changes the approvers of a request that was sent — recall it to change them yourself');
    }

    private function refuse_if(?AppError $e): void
    {
        if ($e) {
            throw $e;
        }
    }

    // ───────────── finding, people ─────────────

    private function find(string $idOrNumber, bool $lock = false): ApprovalRequest
    {
        $key = trim($idOrNumber);

        return ApprovalRequest::where(fn ($w) => $w->where('id', $key)->orWhere('number', $key))->when($lock, fn ($q) => $q->lockForUpdate())->first()
            ?? throw AppError::notFound('APPROVAL_NOT_FOUND', "طلب الاعتماد {$key} غير موجود", "Approval request {$key} not found");
    }

    private function waiting(AuthUser $u)
    {
        return ApprovalRequest::query()->where('approval_requests.status', 'pending')
            ->whereHas('approvers', fn ($a) => $this->people->mine($a->where('approval_request_approvers.status', 'pending'), $u, 'user', 'approval_request_approvers'));
    }

    /** @return Collection<int, ApprovalApprover> in the order they are asked */
    private function lines(ApprovalRequest $r): Collection
    {
        return ApprovalApprover::where('request_id', $r->id)->orderBy('sequence')->orderBy('created_at')->orderBy('id')->get();
    }

    private function isRequester(AuthUser $u, ApprovalRequest $r): bool
    {
        return $this->people->isMine($u, $r->requester_id, $r->requester_login);
    }

    /** The caller's own line on a request (one that was not taken off it). */
    private function myLine(AuthUser $u, Collection $lines): ?ApprovalApprover
    {
        return $lines->first(fn (ApprovalApprover $a) => $a->status !== 'removed' && $this->people->isMine($u, $a->user_id, $a->user_login));
    }

    private function requesterId(ApprovalRequest $r): ?string
    {
        return $this->people->userId($r->requester_id, $r->requester_login);
    }

    /** Everybody a comment concerns: the requester and the approvers who are, or were, part of the decision. @return array<int, ?string> */
    private function audience(ApprovalRequest $r): array
    {
        return [$this->requesterId($r), ...$this->lines($r)->filter(fn (ApprovalApprover $a) => ! in_array($a->status, ['removed', 'new'], true))
            ->map(fn (ApprovalApprover $a) => $this->people->userId($a->user_id, $a->user_login))->all()];
    }

    /** @param  ApprovalRequest[]  $requests */
    private function loadPeople(array $requests): void
    {
        $ids = [];
        foreach ($requests as $r) {
            $ids[] = $r->requester_id;
            $ids[] = $r->created_by_id;
            foreach ($r->approvers as $a) {
                $ids[] = $a->user_id;
            }
        }
        $this->people->load($ids);
    }

    // ───────────── what the client gets ─────────────

    /** A request as a list shows it. */
    private function row(AuthUser $u, ApprovalRequest $r): array
    {
        $lines = $r->approvers;
        $mine = $this->myLine($u, $lines);
        $eval = ApprovalFlow::evaluate($r->approval_mode, (int) $r->min_approvals, $lines->map(fn (ApprovalApprover $a) => ['id' => $a->id, 'status' => $a->status, 'required' => (bool) $a->required])->all());
        $category = $r->category;

        return [
            'id' => $r->id, 'number' => $r->number, 'title' => $r->title, 'status' => $r->status,
            'category' => $category ? ['id' => $category->id, 'code' => $category->code, 'nameAr' => $category->name_ar, 'nameEn' => $category->name_en] : null,
            'requester' => $this->people->present($r->requester_id, $r->requester_name, $r->requester_login),
            'amount' => $r->amount, 'currency' => $r->currency, 'quantity' => $r->quantity, 'date' => $r->request_date?->toDateString(),
            'reference' => $r->reference, 'partner' => $r->partner_name !== null ? ['type' => $r->partner_type, 'id' => $r->partner_id, 'name' => $r->partner_name] : null,
            'approvalMode' => $r->approval_mode, 'minApprovals' => (int) $r->min_approvals,
            'progress' => ['approved' => $eval['approved'], 'needed' => $eval['needed'], 'total' => $eval['total']],
            'approvers' => $lines->map(fn (ApprovalApprover $a) => [
                'id' => $a->id, 'user' => $this->people->present($a->user_id, $a->user_name, $a->user_login), 'sequence' => (int) $a->sequence, 'required' => (bool) $a->required, 'status' => $a->status,
                'comment' => $a->comment, 'decidedAt' => $a->decided_at?->toJSON(), 'origin' => $a->origin, 'isMe' => $mine !== null && $mine->id === $a->id,
            ])->values()->all(),
            'my' => ['isRequester' => $this->isRequester($u, $r), 'lineId' => $mine?->id, 'lineStatus' => $mine?->status, 'turn' => $r->status === 'pending' && $mine?->status === 'pending'],
            'source' => $r->source_system, 'externalRef' => $r->external_ref, 'owned' => $this->owned($r),
            'createdAt' => $r->created_at?->toJSON(), 'submittedAt' => $r->submitted_at?->toJSON(), 'decidedAt' => $r->decided_at?->toJSON(), 'updatedAt' => $r->updated_at?->toJSON(),
        ];
    }

    /** A request with everything on it and what this user may do with it now. */
    private function detail(AuthUser $u, ApprovalRequest $r): array
    {
        $r->load(['category', 'approvers', 'items.product:id,sku,name_ar,name_en', 'events', 'attachments']);
        $this->loadPeople([$r]);
        $this->people->load([...$r->events->pluck('user_id')->all(), ...$r->attachments->pluck('uploaded_by_id')->all()]);
        $lines = $r->approvers;
        $block = fn (?AppError $e) => $e ? ['code' => $e->errorCode, 'messageAr' => $e->messageAr, 'messageEn' => $e->messageEn ?? $e->messageAr] : null;
        $decide = $this->blockDecide($u, $r, $lines);
        $partner = null;
        if ($r->partner_name !== null) {
            $row = $r->partner_id ? DB::table($r->partner_type === 'supplier' ? 'suppliers' : 'customers')->where('id', $r->partner_id)->first(['code', 'name_ar', 'name_en']) : null;
            $partner = ['type' => $r->partner_type, 'id' => $r->partner_id, 'code' => $row?->code, 'name' => $r->partner_name, 'nameAr' => $row?->name_ar ?? $r->partner_name, 'nameEn' => $row?->name_en ?? $r->partner_name];
        }
        // a file that was attached in Odoo can be opened once its content is kept in the Odoo file archive
        $archived = AttachmentsService::archived($r->attachments->where('status', ApprovalAttachment::ODOO)->pluck('external_ref')->all());
        $manages = $this->manages($u);

        return [...$this->row($u, $r),
            'category' => $r->category ? CategoriesService::brief($r->category) : null,
            'createdBy' => $this->people->present($r->created_by_id, $r->created_by),
            'partner' => $partner, 'periodStart' => $r->period_start?->toJSON(), 'periodEnd' => $r->period_end?->toJSON(), 'location' => $r->location,
            'paymentMethod' => $r->payment_method, 'reason' => $r->reason,
            'cancelledAt' => $r->cancelled_at?->toJSON(), 'cancelledBy' => $r->cancelled_by, 'cancelReason' => $r->cancel_reason, 'sourceMeta' => $r->source_meta,
            'items' => $r->items->map(fn (ApprovalItem $i) => [
                'id' => $i->id, 'lineNo' => (int) $i->line_no, 'description' => $i->description, 'quantity' => $i->quantity, 'uom' => $i->uom, 'warehouse' => $i->warehouse,
                'product' => $i->product ? ['id' => $i->product->id, 'sku' => $i->product->sku, 'nameAr' => $i->product->name_ar, 'nameEn' => $i->product->name_en] : null,
            ])->values()->all(),
            'attachments' => $r->attachments->map(fn (ApprovalAttachment $a) => [
                'id' => $a->id, 'fileName' => $a->file_name, 'mime' => $a->mime, 'size' => (int) $a->size, 'source' => $a->source_system,
                'available' => $a->status === ApprovalAttachment::ODOO ? isset($archived[(string) $a->external_ref]) : $a->storage_key !== null,
                'uploadedBy' => $this->people->present($a->uploaded_by_id, $a->uploaded_by), 'createdAt' => $a->created_at?->toJSON(),
                'removed' => $a->removed_at !== null, 'removedAt' => $a->removed_at?->toJSON(), 'removedBy' => $a->removed_by, 'removeReason' => $a->remove_reason,
                'canRemove' => AttachmentsService::blockRemove($u, $r, $a, $manages, $this->owned($r)) === null,
            ])->values()->all(),
            'timeline' => $r->events->map(fn (ApprovalEvent $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'at' => $e->at?->toJSON(), 'by' => $this->people->present($e->user_id, $e->user_name), 'note' => $e->note, 'meta' => $e->meta, 'source' => $e->source_system,
            ])->values()->all(),
            'can' => [
                'edit' => ! $this->blockEdit($u, $r) && $u->can('approval.request'),
                'submit' => ! $this->blockSubmit($u, $r) && $u->can('approval.request'),
                'approve' => $decide === null, 'refuse' => $decide === null,
                'withdraw' => ! $this->blockWithdraw($u, $r, $lines),
                'cancel' => ! $this->blockCancel($u, $r),
                'reopen' => ! $this->blockReopen($u, $r),
                'approvers' => ! $this->blockApprovers($u, $r),
                'comment' => true, 'attach' => true,
            ],
            // why the caller, being an approver of a request that waits, cannot decide right now (his turn has not come, it is Odoo's…)
            'decisionBlock' => $r->status === 'pending' && $this->myLine($u, $lines) ? $block($decide) : null,
        ];
    }

    /** What the audit trail keeps of a request's own values. */
    private function snapshot(ApprovalRequest $r): array
    {
        return [
            'title' => $r->title, 'amount' => $r->amount !== null ? (float) $r->amount : null, 'quantity' => $r->quantity !== null ? (float) $r->quantity : null,
            'date' => $r->request_date?->toDateString(), 'periodStart' => $r->period_start?->toJSON(), 'periodEnd' => $r->period_end?->toJSON(), 'location' => $r->location, 'reference' => $r->reference,
            'partner' => $r->partner_name, 'paymentMethod' => $r->payment_method, 'reason' => $r->reason,
            'items' => ApprovalItem::where('request_id', $r->id)->orderBy('line_no')->get()->map(fn (ApprovalItem $i) => "{$i->description} × ".(float) $i->quantity)->all(),
            'approvers' => $this->lines($r)->where('status', '!=', 'removed')->pluck('user_name')->values()->all(),
        ];
    }
}
