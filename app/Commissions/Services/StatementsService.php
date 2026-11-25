<?php

namespace App\Commissions\Services;

use App\Commissions\Models\CommissionLine;
use App\Commissions\Models\CommissionStatement;
use App\Commissions\Support\Owned;
use App\Commissions\Support\People;
use App\Commissions\Support\Period;
use App\Commissions\Support\Shape;
use App\Models\StatusHistory;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Support\Sql;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Commission statements: what an earner is owed for a month, and its way to being paid.
 *
 *   draft      calculated and recalculated at will; adjustments (with a reason) and exclusions are made here
 *   submitted  frozen, waiting for the approver — who approves it or sends it back with a reason
 *   approved   owed; the approver releases the payout, or reopens it with a reason
 *   paid       posted through the finance module (one expense voucher); voiding the payout reverses that voucher
 *
 * WHO SEES WHAT. A user sees his own statements; plans, calculation and approval (`commission.manage`,
 * `commission.approve`) see them all. Somebody else's statement answers "not found".
 * NOBODY DECIDES ON HIS OWN MONEY: the earner of a statement neither approves it nor releases its payout.
 * A statement that came from Odoo is read-only while the section follows Odoo (422 COMMISSION_OWNED_BY_SOURCE).
 */
class StatementsService
{
    private const ENTITY = 'CommissionStatement';

    public function __construct(
        private readonly CalculationService $calculation,
        private readonly CommissionPosting $posting,
        private readonly AuditService $audit,
        private readonly NotifyService $notify,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{status?:?string, period?:?string, from?:?string, to?:?string, beneficiary?:?string, plan?:?string, source?:?string, mine?:mixed}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = CommissionStatement::query()->with('plan:id,code,name_ar,name_en');
        $this->scope($q, $user);
        if (! empty($f['mine'])) {
            $q->where('beneficiary_id', $user->id);
        }
        if (! empty($f['status'])) {
            $q->whereIn('status', array_map('trim', explode(',', $f['status'])));
        }
        foreach (['period' => '=', 'from' => '>=', 'to' => '<='] as $key => $op) {
            if (Period::valid($f[$key] ?? null)) {
                $q->where('period', $op, $f[$key]);
            }
        }
        if (! empty($f['beneficiary'])) {
            str_starts_with($f['beneficiary'], 'name:') ? $q->whereNull('beneficiary_id')->where('beneficiary_name', substr($f['beneficiary'], 5)) : $q->where('beneficiary_id', $f['beneficiary']);
        }
        if (! empty($f['plan'])) {
            $q->whereIn('plan_id', fn ($s) => $s->from('commission_plans')->select('id')->where('id', $f['plan'])->orWhere('code', $f['plan']));
        }
        if (($f['source'] ?? null) === 'odoo') {
            $q->where('source_system', 'odoo');
        } elseif (($f['source'] ?? null) === 'ops') {
            $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '\\%_').'%';
            $op = Sql::like();
            $q->where(fn ($w) => $w->where('number', $op, $like)->orWhere('reference', $op, $like)->orWhere('beneficiary_name', $op, $like)->orWhere('payout_reference', $op, $like)
                ->orWhereIn('beneficiary_id', fn ($s) => $s->from('users')->select('id')->where(fn ($u) => $u->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('username', $op, $like))));
        }
        $sums = (clone $q)->toBase()->selectRaw('coalesce(sum(base_total), 0) as base, coalesce(sum(commission_total), 0) as commission, coalesce(sum(adjustments_total), 0) as adjustments, coalesce(sum(total), 0) as amount')->first();
        $column = match ($page->sort) {
            'total' => 'total', 'number' => 'number', 'status' => 'status', 'base' => 'base_total', 'lines' => 'lines_count', default => 'period'
        };
        $q->orderBy($column, $page->order)->orderBy('number', $page->order);
        $out = $page->paginate($q);
        $users = People::byId(array_map(fn (CommissionStatement $s) => $s->beneficiary_id, $out['items']));
        $out['items'] = array_map(fn (CommissionStatement $s) => Shape::statement($s, $users) + ['mine' => $s->beneficiary_id !== null && $s->beneficiary_id === $user->id], $out['items']);
        $out['totals'] = ['base' => Shape::money($sums->base ?? 0), 'commission' => Shape::money($sums->commission ?? 0), 'adjustments' => Shape::money($sums->adjustments ?? 0), 'total' => Shape::money($sums->amount ?? 0)];

        return $out;
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        return $this->present($user, $this->visible($user, $this->find($idOrNumber)));
    }

    /** The lines of one statement, paged (a statement of Odoo's history holds thousands). */
    public function lines(AuthUser $user, string $idOrNumber, Paging $page, array $f): array
    {
        $s = $this->visible($user, $this->find($idOrNumber));

        return $this->pageLines(CommissionLine::where('statement_id', $s->id), $page, $f, 'asc', false);
    }

    /** Every line the user may see, across statements: "was this invoice paid a commission for, to whom, in which statement?" */
    public function allLines(AuthUser $user, Paging $page, array $f): array
    {
        $q = CommissionLine::query();
        $seesAll = People::seesAll($user);
        if (! $seesAll || ! empty($f['period']) || ! empty($f['status']) || ! empty($f['beneficiary'])) {
            $q->whereIn('statement_id', function ($s) use ($user, $seesAll, $f) {
                $s->from('commission_statements')->select('id');
                if (! $seesAll) {
                    $s->where('beneficiary_id', $user->id);
                }
                if (Period::valid($f['period'] ?? null)) {
                    $s->where('period', $f['period']);
                }
                if (! empty($f['status'])) {
                    $s->whereIn('status', array_map('trim', explode(',', $f['status'])));
                }
                if (! empty($f['beneficiary'])) {
                    str_starts_with($f['beneficiary'], 'name:') ? $s->whereNull('beneficiary_id')->where('beneficiary_name', substr($f['beneficiary'], 5)) : $s->where('beneficiary_id', $f['beneficiary']);
                }
            });
        }

        return $this->pageLines($q, $page, $f, 'desc', true);
    }

    // ───────────────────────────── a person's corrections (draft only) ─────────────────────────────

    /** An adjustment line: an amount for or against the earner, with the reason a person gives. */
    public function adjust(AuthUser $user, string $idOrNumber, float $amount, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $amount, $reason) {
            $s = $this->find($idOrNumber, true);
            $this->guard('adjust', $user, $s);
            $amount = round($amount, 2);
            if (abs($amount) < 0.005) {
                throw AppError::validation('COMMISSION_ADJUSTMENT_ZERO', 'مبلغ التسوية لا يكون صفرًا', 'An adjustment cannot be zero', [['path' => 'amount', 'message' => 'مبلغ موجب أو سالب']]);
            }
            $line = CommissionLine::create([
                'statement_id' => $s->id, 'kind' => 'adjustment', 'beneficiary_id' => $s->beneficiary_id, 'plan_id' => $s->plan_id, 'doc_date' => Carbon::today()->toDateString(),
                'base_amount' => 0, 'commission' => $amount, 'reason' => mb_substr(trim($reason), 0, 1000), 'created_by' => $user->username,
            ]);
            $this->retotal($s, false);
            $this->audit->log($user, ['action' => 'COMMISSION.ADJUST', 'entityType' => self::ENTITY, 'entityId' => $s->id, 'entityNumber' => $s->number, 'newValue' => ['line' => $line->id, 'amount' => $amount, 'reason' => $line->reason, 'total' => (string) $s->total]]);

            return $this->present($user, $s);
        });
    }

    /** Takes an adjustment back: the line stays, struck out, and counts no more. */
    public function voidAdjustment(AuthUser $user, string $lineId, string $reason): array
    {
        return DB::transaction(function () use ($user, $lineId, $reason) {
            [$s, $line] = $this->lineForChange($user, $lineId, ['adjustment']);
            if ($line->voided_at) {
                throw AppError::rule('COMMISSION_LINE_STATE', 'هذه التسوية ملغاة مسبقًا', 'This adjustment is already void');
            }
            $line->update(['voided_at' => now(), 'voided_by' => $user->username, 'void_reason' => mb_substr(trim($reason), 0, 1000)]);
            $this->retotal($s, false);
            $this->audit->log($user, ['action' => 'COMMISSION.ADJUST.VOID', 'entityType' => self::ENTITY, 'entityId' => $s->id, 'entityNumber' => $s->number, 'newValue' => ['line' => $line->id, 'amount' => (string) $line->commission, 'reason' => $line->void_reason, 'total' => (string) $s->total]]);

            return $this->present($user, $s);
        });
    }

    /** An invoice that stays on the statement and earns nothing — a person's decision, with its reason. */
    public function exclude(AuthUser $user, string $lineId, string $reason): array
    {
        return $this->setExcluded($user, $lineId, true, $reason);
    }

    public function includeAgain(AuthUser $user, string $lineId): array
    {
        return $this->setExcluded($user, $lineId, false, null);
    }

    private function setExcluded(AuthUser $user, string $lineId, bool $excluded, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $lineId, $excluded, $reason) {
            [$s, $line] = $this->lineForChange($user, $lineId, CommissionLine::GRANTS);
            if ((bool) $line->excluded === $excluded) {
                throw AppError::rule('COMMISSION_LINE_STATE', $excluded ? 'هذه الفاتورة مستبعدة مسبقًا' : 'هذه الفاتورة غير مستبعدة', $excluded ? 'This invoice is already excluded' : 'This invoice is not excluded');
            }
            $line->update(['excluded' => $excluded, 'exclude_reason' => $excluded ? mb_substr(trim((string) $reason), 0, 250) : null]);
            if ($line->rule_id && collect((array) ($s->terms['rules'] ?? []))->contains('id', $line->rule_id)) {
                $this->calculation->rerate($s);   // under a tiered rule the month's total moved, and with it the other lines
            } else {
                // a line of history (no rule of OPS behind it): its own rate is all there is
                $line->update(['commission' => $excluded ? 0 : round((float) $line->base_amount * (float) $line->rate / 100, 4)]);
                $this->retotal($s, true);
            }
            $this->audit->log($user, ['action' => $excluded ? 'COMMISSION.LINE.EXCLUDE' : 'COMMISSION.LINE.INCLUDE', 'entityType' => self::ENTITY, 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['line' => $line->id, 'document' => $line->doc_number, 'reason' => $reason, 'total' => (string) $s->refresh()->total]]);

            return $this->present($user, $s);
        });
    }

    // ───────────────────────────── the way to being paid ─────────────────────────────

    /**
     * draft → submitted. What is submitted is what the documents say NOW: the statement is calculated once more first,
     * and when that changes anything the new figures are kept and the submission is refused — a person looks at them,
     * then submits. (A statement of another system's history has nothing to recalculate.)
     */
    public function submit(AuthUser $user, string $idOrNumber, ?string $note = null): array
    {
        $s = $this->find($idOrNumber);
        $this->guard('submit', $user, $s);
        if ($s->source_system === null && $s->beneficiary_id) {
            $fresh = collect($this->calculation->run($user, $s->period, $s->beneficiary_id)['statements'])->firstWhere('id', $s->id);
            if ($fresh && $fresh['outcome'] === 'removed') {
                throw AppError::rule('COMMISSION_STATEMENT_WITHDRAWN',
                    "الكشف {$s->number} لم يعد له ما يبرره — لا فواتير تخص المستحق ولا خطة فعّالة تشمله — فسُحب ولم يُقدَّم",
                    "Statement {$s->number} has nothing left to justify it — no invoice of the earner, no active plan that names him — so it was withdrawn, not submitted");
            }
            if ($fresh && $fresh['outcome'] !== 'unchanged') {
                throw AppError::rule('COMMISSION_STATEMENT_CHANGED',
                    "تغيّر الكشف {$s->number} عند إعادة احتسابه قبل التقديم (الإجمالي الآن {$fresh['total']}) — راجعه ثم قدّمه",
                    "Statement {$s->number} changed when it was recalculated before submission (it now totals {$fresh['total']}) — review it, then submit",
                    ['total' => $fresh['total'], 'lines' => $fresh['lines'], 'added' => $fresh['added'] ?? 0, 'changed' => $fresh['changed'] ?? 0, 'removed' => $fresh['removed'] ?? 0]);
            }
        }

        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $s = $this->find($idOrNumber, true);
            $this->guard('submit', $user, $s);
            $s->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $user->username]);
            $this->audit->status($user, self::ENTITY, $s->id, $s->number, 'draft', 'submitted', $note);
            $this->notify->activity($user, self::ENTITY, $s->id, $s->number, "قُدّم كشف العمولة {$s->number} عن {$s->period} للاعتماد", "Commission statement {$s->number} for {$s->period} was submitted for approval", People::rolesWith(People::APPROVE));

            return $this->present($user, $s);
        });
    }

    public function approve(AuthUser $user, string $idOrNumber, ?string $note = null): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $s = $this->find($idOrNumber, true);
            $this->guard('approve', $user, $s);
            $s->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $user->username, 'approval_note' => $note ? mb_substr(trim($note), 0, 1000) : null]);
            $this->audit->status($user, self::ENTITY, $s->id, $s->number, 'submitted', 'approved', $note);
            $this->notify->activity($user, self::ENTITY, $s->id, $s->number, "اعتُمد كشف العمولة {$s->number} عن {$s->period}", "Commission statement {$s->number} for {$s->period} was approved");
            People::tell($s->beneficiary_id, $user, $s, "اعتُمد كشف عمولتك عن {$s->period} ({$s->number}) وهو بانتظار الصرف", "Your commission statement for {$s->period} ({$s->number}) was approved and waits for its payout");

            return $this->present($user, $s);
        });
    }

    /** Back to draft, with the reason: by the approver (or the person who submitted it) before approval, by the approver after it. */
    public function sendBack(AuthUser $user, string $idOrNumber, string $reason): array
    {
        People::needAny($user, People::APPROVE, People::MANAGE);   // audited, before the transaction

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = $this->find($idOrNumber, true);
            $this->guard('return', $user, $s);
            $from = $s->status;
            $s->update(['status' => 'draft', 'submitted_at' => null, 'submitted_by' => null, 'approved_at' => null, 'approved_by' => null, 'approval_note' => null,
                'returned_at' => now(), 'returned_by' => $user->username, 'return_reason' => mb_substr(trim($reason), 0, 1000)]);
            $this->audit->status($user, self::ENTITY, $s->id, $s->number, $from, 'draft', $reason);
            $this->notify->activity($user, self::ENTITY, $s->id, $s->number, "أُعيد كشف العمولة {$s->number} عن {$s->period} إلى المسودة: {$reason}", "Commission statement {$s->number} for {$s->period} was returned to draft: {$reason}", People::rolesWith(People::MANAGE));

            return $this->present($user, $s);
        });
    }

    /**
     * Releases the payout: one expense voucher of the finance module, and the statement is paid. A statement that
     * totals zero is closed without a voucher; a negative one is not paid out.
     *
     * @param  array{account?:?string, bankAccount?:?string, reference?:?string, date?:?string}  $o
     */
    public function payout(AuthUser $user, string $idOrNumber, array $o): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $o) {
            $s = $this->find($idOrNumber, true);
            $this->guard('payout', $user, $s);
            $total = (float) $s->total;
            $date = ! empty($o['date']) ? Carbon::parse($o['date'])->startOfDay() : Carbon::today();
            if ($date->gt(Carbon::today())) {
                throw AppError::validation('DATE_FUTURE', 'تاريخ الصرف لا يكون في المستقبل', 'The payout date cannot be in the future', [['path' => 'date', 'message' => 'اليوم أو قبله']]);
            }
            $voucher = null;
            if ($total > 0.004) {
                $missing = [];
                foreach (['account' => 'حساب المصروف الذي تُحمَّل عليه العمولة', 'bankAccount' => 'الحساب البنكي أو الصندوق الذي تُصرف منه', 'reference' => 'مرجع الحوالة أو الشيك'] as $key => $what) {
                    if (trim((string) ($o[$key] ?? '')) === '') {
                        $missing[] = ['path' => $key, 'message' => "مطلوب: {$what}"];
                    }
                }
                if ($missing) {
                    throw AppError::validation('COMMISSION_PAYOUT_DETAILS', 'أكمل بيانات الصرف: حساب المصروف، الحساب البنكي أو الصندوق، ومرجع الحوالة', 'Complete the payout: the expense account, the bank account or cash box, and the transfer reference', $missing);
                }
                $name = $s->beneficiary_id ? (string) (People::byId([$s->beneficiary_id])[$s->beneficiary_id]->name_ar ?? '') : (string) $s->beneficiary_name;
                $voucher = $this->posting->payout($user, $s, $name, ['account' => trim((string) $o['account']), 'bankAccount' => trim((string) $o['bankAccount']), 'reference' => mb_substr(trim((string) $o['reference']), 0, 80), 'date' => $date->toDateString()]);
                if (abs((float) $voucher['total'] - $total) > 0.005) {
                    throw AppError::rule('COMMISSION_PAYOUT_MISMATCH', "سند المصروف يحسب {$voucher['total']} والكشف {$s->total} — لم يُصرف شيء", "The voucher computes {$voucher['total']} while the statement says {$s->total} — nothing was paid");
                }
            }
            $s->update([
                'status' => 'paid', 'paid_at' => now(), 'paid_by' => $user->username, 'payout_date' => $date->toDateString(),
                'payout_reference' => $voucher ? mb_substr(trim((string) $o['reference']), 0, 80) : null, 'payout_bank' => $voucher ? trim((string) $o['bankAccount']) : null,
                'payout_account' => $voucher ? (string) ($voucher['account']['code'] ?? trim((string) $o['account'])) : null,
                'finance_expense_id' => $voucher['id'] ?? null, 'finance_expense_number' => $voucher['number'] ?? null,
            ]);
            $this->audit->log($user, ['action' => 'COMMISSION.PAYOUT', 'entityType' => self::ENTITY, 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['total' => (string) $s->total, 'expense' => $voucher['number'] ?? null, 'journal' => $voucher['journal']['number'] ?? null, 'account' => $s->payout_account, 'bankAccount' => $s->payout_bank, 'reference' => $s->payout_reference, 'date' => $date->toDateString()]]);
            $this->audit->status($user, self::ENTITY, $s->id, $s->number, 'approved', 'paid', $voucher ? "سند {$voucher['number']} — مرجع {$s->payout_reference}" : 'أُقفل بلا صرف: الإجمالي صفر');
            $this->notify->activity($user, self::ENTITY, $s->id, $s->number, $voucher ? "صُرف كشف العمولة {$s->number} عن {$s->period} بسند المصروف {$voucher['number']}" : "أُقفل كشف العمولة {$s->number} عن {$s->period} بلا صرف (الإجمالي صفر)",
                $voucher ? "Commission statement {$s->number} for {$s->period} was paid with expense voucher {$voucher['number']}" : "Commission statement {$s->number} for {$s->period} was closed with nothing to pay", $voucher ? People::rolesWith(People::MANAGE) : []);
            if ($voucher) {
                People::tell($s->beneficiary_id, $user, $s, "صُرفت عمولتك عن {$s->period} ({$s->number})", "Your commission for {$s->period} ({$s->number}) was paid");
            }

            return $this->present($user, $s);
        });
    }

    /** Takes a payout back: the expense voucher is voided by the finance module (its journal reversed), the statement is approved again. */
    public function voidPayout(AuthUser $user, string $idOrNumber, string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = $this->find($idOrNumber, true);
            $this->guard('voidPayout', $user, $s);
            $number = $this->posting->reverse($user, $s, $reason);
            $s->update(['status' => 'approved', 'paid_at' => null, 'paid_by' => null, 'payout_date' => null, 'payout_reference' => null, 'payout_bank' => null, 'payout_account' => null,
                'finance_expense_id' => null, 'finance_expense_number' => null]);
            $this->audit->log($user, ['action' => 'COMMISSION.PAYOUT.VOID', 'entityType' => self::ENTITY, 'entityId' => $s->id, 'entityNumber' => $s->number, 'newValue' => ['expense' => $number, 'reason' => $reason]]);
            $this->audit->status($user, self::ENTITY, $s->id, $s->number, 'paid', 'approved', $reason);
            $this->notify->activity($user, self::ENTITY, $s->id, $s->number, "أُلغي صرف كشف العمولة {$s->number} عن {$s->period}".($number ? " وعُكس السند {$number}" : '').": {$reason}",
                "The payout of commission statement {$s->number} for {$s->period} was voided".($number ? " and voucher {$number} reversed" : '').": {$reason}", People::rolesWith(People::MANAGE));

            return $this->present($user, $s);
        });
    }

    // ───────────────────────────── rules ─────────────────────────────

    public function find(string $idOrNumber, bool $lock = false): CommissionStatement
    {
        $q = CommissionStatement::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber));

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw self::notFound($idOrNumber);
    }

    /** Somebody else's statement does not exist for a user who only sees his own. */
    public function visible(AuthUser $user, CommissionStatement $s): CommissionStatement
    {
        if (! People::seesAll($user) && ($s->beneficiary_id === null || $s->beneficiary_id !== $user->id)) {
            throw self::notFound($s->number);
        }

        return $s;
    }

    private static function notFound(string $what): AppError
    {
        return AppError::notFound('COMMISSION_STATEMENT_NOT_FOUND', "كشف العمولة {$what} غير موجود", "Commission statement {$what} not found");
    }

    private function scope(Builder $q, AuthUser $user): void
    {
        if (! People::seesAll($user)) {
            $q->where('beneficiary_id', $user->id);
        }
    }

    private function guard(string $action, AuthUser $user, CommissionStatement $s): void
    {
        if ($refusal = $this->refusal($action, $user, $s)) {
            throw $refusal;
        }
    }

    /**
     * Why this user cannot do this to the statement now — or null when he can. One place for the rules: the actions
     * throw what it returns, and the statement's page shows it as the reason beside a button that is not offered.
     */
    private function refusal(string $action, AuthUser $user, CommissionStatement $s): ?AppError
    {
        if (Owned::bySource($s)) {
            try {
                Owned::refuse($s, 'statement');
            } catch (AppError $e) {
                return $e;
            }
        }
        $can = fn (string ...$any) => array_filter($any, fn (string $p) => $user->can($p)) ? null
            : AppError::forbidden('FORBIDDEN', "مرفوض — هذا الإجراء يحتاج الصلاحية {$any[0]}", "Forbidden — this action needs the permission {$any[0]}");
        $in = fn (string ...$states) => in_array($s->status, $states, true) ? null
            : AppError::rule('COMMISSION_STATEMENT_STATE', "الكشف {$s->number} حالته «{$s->status}» — هذا الإجراء غير متاح فيها", "Statement {$s->number} is {$s->status} — this action is not available in that state", ['status' => $s->status]);
        $own = fn () => $s->beneficiary_id !== null && $s->beneficiary_id === $user->id
            ? AppError::rule('COMMISSION_SELF_APPROVAL', 'لا يعتمد المستفيد كشف عمولته ولا يصرفه — يقوم بذلك شخص آخر يملك الصلاحية', 'The earner of a statement neither approves it nor releases its payout — another holder of the permission does') : null;

        return match ($action) {
            'recalculate' => $can(People::MANAGE) ?? $in('draft') ?? ($s->source_system !== null || ! $s->beneficiary_id
                ? AppError::rule('COMMISSION_STATEMENT_STATE', "الكشف {$s->number} تاريخ منقول من نظام آخر — لا يُحتسب من فواتير العمليات", "Statement {$s->number} is history from another system — it is not calculated from OPS invoices") : null),
            'adjust' => $can(People::MANAGE) ?? $in('draft'),
            'submit' => $can(People::MANAGE) ?? $in('draft') ?? ((int) $s->lines_count === 0 && ! CommissionLine::where('statement_id', $s->id)->where('kind', 'adjustment')->whereNull('voided_at')->exists()
                ? AppError::rule('COMMISSION_STATEMENT_EMPTY', "الكشف {$s->number} فارغ — لا سطور فيه ولا تسويات", "Statement {$s->number} is empty — it has no line and no adjustment") : null),
            'approve' => $can(People::APPROVE) ?? $in('submitted') ?? $own(),
            'return' => ($s->status === 'approved' ? $can(People::APPROVE) : $can(People::APPROVE, People::MANAGE)) ?? $in('submitted', 'approved') ?? $this->takenBack($s),
            'payout' => $can(People::APPROVE) ?? $in('approved') ?? $own() ?? ((float) $s->total < -0.004
                ? AppError::rule('COMMISSION_NEGATIVE_TOTAL', "إجمالي الكشف {$s->number} سالب — لا يُصرف. أعده إلى المسودة ورحّل الرصيد بتسوية إلى كشف الشهر التالي", "Statement {$s->number} totals below zero — it is not paid out. Reopen it and carry the balance to the next month with an adjustment") : null),
            'voidPayout' => $can(People::APPROVE) ?? $in('paid') ?? ($s->source_system !== null && ! $s->finance_expense_id && (float) $s->total > 0.004
                ? AppError::rule('COMMISSION_PAID_ELSEWHERE', "الكشف {$s->number} صُرف في النظام الذي جاء منه — لا يُعكس صرفه هنا", "Statement {$s->number} was paid in the system it came from — its payout is not reversed here") : null),
            default => AppError::rule('COMMISSION_STATEMENT_STATE', 'إجراء غير معروف', 'Unknown action'),
        };
    }

    /** A line of this statement was taken back in a later one: reopening it would leave that reversal answering nothing. */
    private function takenBack(CommissionStatement $s): ?AppError
    {
        $other = CommissionLine::query()->join('commission_lines as mine', 'mine.id', '=', 'commission_lines.reverses_id')->join('commission_statements as st', 'st.id', '=', 'commission_lines.statement_id')
            ->where('mine.statement_id', $s->id)->where('commission_lines.statement_id', '!=', $s->id)->orderBy('st.number')->value('st.number');

        return $other ? AppError::rule('COMMISSION_HAS_REVERSALS', "سطور من الكشف {$s->number} عُكست في الكشف {$other} — لا يُعاد إلى المسودة. صحّح بتسوية في كشف مفتوح",
            "Lines of statement {$s->number} were taken back in statement {$other} — it cannot return to draft. Correct with an adjustment in an open statement", ['statement' => $other]) : null;
    }

    /** @return array{0: CommissionStatement, 1: CommissionLine} the statement (locked, open to this change) and its line */
    private function lineForChange(AuthUser $user, string $lineId, array $kinds): array
    {
        $line = CommissionLine::whereKey($lineId)->first() ?? throw AppError::notFound('COMMISSION_LINE_NOT_FOUND', 'سطر العمولة غير موجود', 'Commission line not found');
        $s = $this->find((string) $line->statement_id, true);
        $this->guard('adjust', $user, $s);
        if (! in_array($line->kind, $kinds, true)) {
            throw AppError::rule('COMMISSION_LINE_STATE', 'هذا الإجراء لا ينطبق على هذا النوع من السطور', 'This action does not apply to this kind of line', ['kind' => $line->kind]);
        }

        return [$s, CommissionLine::whereKey($lineId)->lockForUpdate()->firstOrFail()];
    }

    /**
     * Totals after a person's change. A statement OPS calculates is summed from its lines. A statement of Odoo keeps
     * Odoo's own base and commission (they are rounded once, on the whole) unless one of its lines was changed here.
     */
    private function retotal(CommissionStatement $s, bool $linesChanged): void
    {
        if ($s->source_system === null || $linesChanged) {
            CalculationService::retotal($s);

            return;
        }
        $adjustments = round((float) CommissionLine::where('statement_id', $s->id)->where('kind', 'adjustment')->whereNull('voided_at')->sum('commission'), 2);
        $s->update(['adjustments_total' => $adjustments, 'total' => round((float) $s->commission_total + $adjustments, 2)]);
    }

    // ───────────────────────────── shaping ─────────────────────────────

    private function present(AuthUser $user, CommissionStatement $s): array
    {
        $s->refresh()->load('plan:id,code,name_ar,name_en');
        $users = People::byId([$s->beneficiary_id]);
        $adjustments = CommissionLine::where('statement_id', $s->id)->where('kind', 'adjustment')->orderBy('created_at')->orderBy('id')->get();
        $breakdown = CommissionLine::where('statement_id', $s->id)->whereIn('kind', CommissionLine::CALCULATED)
            ->selectRaw('rule_id, rule_label, kind, count(*) as n, sum(case when excluded then 1 else 0 end) as excluded_n, coalesce(sum(base_amount), 0) as base, coalesce(sum(commission), 0) as commission, min(rate) as rate_min, max(rate) as rate_max')
            ->groupBy('rule_id', 'rule_label', 'kind')->orderBy('kind')->orderBy('rule_id')->toBase()->get();
        $history = StatusHistory::where('entity_type', self::ENTITY)->where('entity_id', $s->id)->orderBy('at')->orderBy('id')->get();
        $actions = [];
        foreach (['recalculate', 'adjust', 'submit', 'approve', 'return', 'payout', 'voidPayout'] as $action) {
            $e = $this->refusal($action, $user, $s);
            $actions[$action] = ['allowed' => $e === null, 'code' => $e?->errorCode, 'reasonAr' => $e?->messageAr, 'reasonEn' => $e?->messageEn];
        }

        return Shape::statementDetail($s, $users) + [
            'mine' => $s->beneficiary_id !== null && $s->beneficiary_id === $user->id,
            'adjustments' => $adjustments->map(fn (CommissionLine $l) => Shape::line($l))->all(),
            'breakdown' => $breakdown->map(fn ($b) => ['ruleId' => $b->rule_id, 'ruleLabel' => $b->rule_label, 'kind' => $b->kind, 'lines' => (int) $b->n, 'excluded' => (int) $b->excluded_n,
                'base' => Shape::money($b->base), 'commission' => Shape::money($b->commission), 'rateMin' => $b->rate_min === null ? null : (float) $b->rate_min, 'rateMax' => $b->rate_max === null ? null : (float) $b->rate_max])->all(),
            'history' => $history->map(fn (StatusHistory $h) => ['from' => $h->from_status, 'to' => $h->to_status, 'by' => $h->username, 'at' => Shape::at($h->at), 'note' => $h->note])->all(),
            'processedAt' => $s->source_system && ($first = CommissionLine::where('statement_id', $s->id)->min('processed_at')) ? Carbon::parse($first)->format(DATE_ATOM) : null,
            'actions' => $actions,
        ];
    }

    private function pageLines(Builder $q, Paging $page, array $f, string $defaultOrder, bool $withStatement): array
    {
        if (! empty($f['kind'])) {
            $q->whereIn('kind', array_map('trim', explode(',', $f['kind'])));
        }
        if (isset($f['excluded']) && $f['excluded'] !== '' && $f['excluded'] !== null) {
            $q->where('excluded', in_array($f['excluded'], [true, 1, '1', 'true'], true));
        }
        if (! empty($f['customer'])) {
            $q->where('customer_id', $f['customer']);
        }
        if (! empty($f['salesperson'])) {
            str_starts_with($f['salesperson'], 'name:') ? $q->whereNull('salesperson_id')->where('salesperson_name', substr($f['salesperson'], 5)) : $q->where('salesperson_id', $f['salesperson']);
        }
        if (! empty($f['from'])) {
            $q->whereDate('doc_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('doc_date', '<=', $f['to']);
        }
        if ($page->q) {
            $like = '%'.addcslashes($page->q, '\\%_').'%';
            $op = Sql::like();
            $q->where(fn ($w) => $w->where('doc_number', $op, $like)->orWhere('customer_name', $op, $like)->orWhere('salesperson_name', $op, $like)->orWhere('reason', $op, $like)
                ->orWhereIn('customer_id', fn ($c) => $c->from('customers')->select('id')->where(fn ($x) => $x->where('name_ar', $op, $like)->orWhere('name_en', $op, $like)->orWhere('code', $op, $like))));
        }
        $sums = (clone $q)->toBase()->selectRaw('coalesce(sum(case when excluded then 0 else base_amount end), 0) as base, coalesce(sum(case when voided_at is null then commission else 0 end), 0) as commission, coalesce(sum(invoice_total), 0) as invoiced')->first();
        $order = $page->sort ? $page->order : $defaultOrder;
        $column = match ($page->sort) {
            'base' => 'base_amount', 'commission' => 'commission', 'docNumber' => 'doc_number', 'invoiceTotal' => 'invoice_total', 'rate' => 'rate', default => 'doc_date'
        };
        $q->orderBy($column, $order)->orderBy('doc_number', $order)->orderBy('id', $order);
        $out = $page->paginate($q);
        /** @var CommissionLine[] $rows */
        $rows = $out['items'];
        $users = People::byId(array_map(fn (CommissionLine $l) => $l->salesperson_id, $rows));
        $customerIds = array_values(array_unique(array_filter(array_map(fn (CommissionLine $l) => $l->customer_id, $rows))));
        $customers = $customerIds ? DB::table('customers')->whereIn('id', $customerIds)->get(['id', 'code', 'name_ar', 'name_en'])->keyBy('id')->all() : [];
        $statements = [];
        if ($withStatement && $rows) {
            $found = CommissionStatement::whereIn('id', array_values(array_unique(array_map(fn (CommissionLine $l) => $l->statement_id, $rows))))->get(['id', 'number', 'period', 'status', 'beneficiary_id', 'beneficiary_name']);
            $earners = People::byId($found->pluck('beneficiary_id')->all());
            foreach ($found as $s) {
                $statements[$s->id] = ['id' => $s->id, 'number' => $s->number, 'period' => $s->period, 'status' => $s->status, 'beneficiary' => People::shape($s->beneficiary_id, $earners[$s->beneficiary_id] ?? null, $s->beneficiary_name)];
            }
        }
        $out['items'] = array_map(fn (CommissionLine $l) => Shape::line($l, $users, $customers) + ($withStatement ? ['statement' => $statements[$l->statement_id] ?? null] : []), $rows);
        $out['totals'] = ['base' => Shape::money($sums->base ?? 0), 'commission' => Shape::money($sums->commission ?? 0, 4), 'invoiced' => Shape::money($sums->invoiced ?? 0)];

        return $out;
    }
}
