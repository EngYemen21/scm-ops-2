<?php

namespace App\Hr\Services;

use App\Finance\Models\BankAccount;
use App\Finance\Models\Expense;
use App\Finance\Models\Journal;
use App\Hr\Models\Claim;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\Employee;
use App\Hr\Models\ExpenseCategory;
use App\Hr\Models\HrFile;
use App\Hr\Support\ClaimAccess;
use App\Hr\Support\Hr;
use App\Hr\Support\Source;
use App\Models\StatusHistory;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expense claims.
 *
 *   draft ──submit──▶ submitted ──approve──▶ approved ──post──▶ posted ──pay──▶ paid
 *     ▲  ◀─withdraw / return──┘  └─refuse──▶ refused      ◀─reopen─┘  ◀─unpost─┘  ◀─unpay─┘
 *     └─cancel──▶ cancelled
 *
 * WHO. The claimant (`expense.claim`) writes, submits, withdraws and cancels their own claim; a reviewer
 * (`expense.review`) approves, refuses with a reason or returns it for correction — never their own claim nor one they
 * entered (maker ≠ checker; the existing policy `procurement.allowSelfApproval` switches that off). A reviewer may
 * enter a claim on behalf of an employee who has no login. Posting to the books and paying are finance acts: the routes
 * ask for `expense.create` on top (and `gl.approve` to take a posting back), the same permissions that guard these
 * acts in the finance section.
 *
 * BOOKS. Posting makes one expense voucher per line through the finance module (ClaimPosting). A claim with nothing
 * owed to the employee — every line paid by the company, or reimbursed on the spot from the cash box — is `paid` the
 * moment it is posted. A claim that came from Odoo carries Odoo's state and figures and is history: it is not posted
 * again here (`posted_in = odoo`), and while the section follows Odoo it is not changed at all.
 *
 * Every move locks the claim row, refuses what the state table does not allow, and leaves a line in the status
 * history and the audit trail.
 */
class ClaimsService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly FilesService $files,
        private readonly ClaimPosting $posting,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{scope?:?string, state?:?string, employee?:?string, department?:?string, source?:?string, from?:?string, to?:?string, order?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $scope = in_array($f['scope'] ?? '', ['review', 'all'], true) ? $f['scope'] : 'mine';
        if ($scope !== 'mine' && ! $user->can('expense.review')) {
            throw AppError::forbidden('FORBIDDEN', 'مطالبات الآخرين يراها مراجعو المصروفات فقط', 'Only expense reviewers see other people\'s claims');
        }
        $q = Claim::query();
        if ($scope === 'mine') {
            $this->mine($q, $user);
        } elseif ($scope === 'review') {
            // what waits for a person here: a decision, the posting, the payment. Odoo's own claims are decided in Odoo while it owns them
            $q->whereIn('state', ['submitted', 'approved', 'posted'])->when(Source::follows(), fn ($x) => $x->whereNull('source_system'));
        }
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Claim::STATES) ?: ['-']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        if (! empty($f['source'])) {
            $f['source'] === Source::ODOO ? $q->where('source_system', Source::ODOO) : $q->whereNull('source_system');
        }
        if (! empty($f['from'])) {
            $q->whereDate('created_at', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('created_at', '<=', $f['to']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('title', 'like', $like)
                ->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        // the figures of everything the filters match, not of the page on screen
        $totals = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(total), 0) as total, coalesce(sum(employee_due), 0) as due')->first();
        $column = ['total' => 'total', 'number' => 'number', 'state' => 'state', 'created' => 'created_at'][$page->sort ?? 'created'] ?? 'created_at';
        $rows = $q->with(['employee:id,code,name_ar,name_en', 'department:id,name_ar,name_en'])->withCount('lines')
            ->orderBy($column, $page->order)->orderBy('id', $page->order)->forPage($page->page, $page->pageSize)->get();
        $extra = $this->rowExtras($rows->all());

        return $page->wrap($rows->map(fn (Claim $c) => $this->row($c, $user, $extra))->all(), (int) $totals->n)
            + ['totals' => ['total' => number_format((float) $totals->total, 2, '.', ''), 'employeeDue' => number_format((float) $totals->due, 2, '.', '')]];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $c = $this->find($idOrNumber)->load(['employee:id,code,name_ar,name_en', 'department:id,name_ar,name_en'])->loadCount('lines');
        ClaimAccess::assertSees($user, $c);
        $lines = $c->lines()->with('category:id,code,name_ar,name_en,account_code,vat_pct')->get();
        $receipts = $this->files->shapedFor('claim_line', $lines->pluck('id')->all());
        $reviewer = $user->can('expense.review');
        $vouchers = $reviewer ? $this->vouchers($lines->pluck('finance_expense_id')->filter()->all()) : [];

        return $this->row($c, $user, $this->rowExtras([$c])) + [
            'notes' => $c->notes, 'decisionNote' => $c->decision_note, 'decidedBy' => $c->decided_by, 'postedBy' => $c->posted_by, 'postedIn' => $c->posted_in,
            'accountingDate' => Hr::day($c->accounting_date), 'settlement' => $c->settlement, 'paidBy' => $c->paid_by, 'paymentRef' => $c->payment_ref,
            'paymentMethod' => $c->payment_method, 'paymentAccount' => $c->payment_account, 'sourceState' => $c->source_state, 'externalRef' => $c->external_ref,
            'lines' => $lines->map(fn (ClaimLine $l) => [
                'id' => $l->id, 'lineNo' => $l->line_no, 'date' => Hr::day($l->expense_date),
                'category' => $l->category ? ['id' => $l->category->id, 'code' => $l->category->code, 'nameAr' => $l->category->name_ar, 'nameEn' => $l->category->name_en] : null,
                'description' => $l->description, 'amount' => $l->amount, 'vatPct' => (float) $l->vat_pct, 'vatAmount' => $l->vat_amount, 'total' => $l->total, 'paidBy' => $l->paid_by,
                'receipts' => $receipts[$l->id] ?? [], 'sourceState' => $l->source_state,
            ] + ($reviewer ? [ // what the line is charged to is the reviewers' business
                'accountCode' => $l->account_code ?? $l->category?->account_code, 'sourceAccount' => $l->source_account,
                'voucher' => $l->finance_expense_id ? ($vouchers[$l->finance_expense_id] ?? ['id' => $l->finance_expense_id, 'number' => $l->finance_expense_number, 'status' => null, 'journal' => null]) : null,
            ] : []))->all(),
            'files' => $this->files->shapedFor('claim', [$c->id])[$c->id] ?? [],
            'history' => $this->history($c),
            'can' => $this->can($user, $c),
        ];
    }

    /** Claims that are the person's own: their expenses (by login or by their employee record) or entered by them. */
    private function mine($q, AuthUser $user): void
    {
        $employeeId = ClaimAccess::employeeId($user);
        $q->where(fn ($w) => $w->where('claimant_user_id', $user->id)->orWhere('created_by_id', $user->id)->when($employeeId, fn ($x) => $x->orWhere('employee_id', $employeeId)));
    }

    /** Figures for the summary cards. */
    public function counts(AuthUser $user): array
    {
        $out = [];
        if ($user->can('expense.claim') || $user->can('expense.review')) {
            $q = Claim::query();
            $this->mine($q, $user);
            $by = (clone $q)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
            $out['mine'] = ['draft' => (int) ($by['draft'] ?? 0), 'submitted' => (int) ($by['submitted'] ?? 0), 'approved' => (int) ($by['approved'] ?? 0) + (int) ($by['posted'] ?? 0),
                'paid' => (int) ($by['paid'] ?? 0), 'refused' => (int) ($by['refused'] ?? 0),
                'toReceive' => number_format((float) (clone $q)->whereIn('state', ['approved', 'posted'])->sum('employee_due'), 2, '.', '')];
        }
        if ($user->can('expense.review')) {
            $q = Claim::query()->when(Source::follows(), fn ($x) => $x->whereNull('source_system'));
            $by = (clone $q)->selectRaw('state, count(*) as n, coalesce(sum(total), 0) as total, coalesce(sum(employee_due), 0) as due')->groupBy('state')->get()->keyBy('state');
            $out['review'] = ['waiting' => (int) ($by['submitted']->n ?? 0), 'waitingTotal' => number_format((float) ($by['submitted']->total ?? 0), 2, '.', ''),
                'toPost' => (int) ($by['approved']->n ?? 0), 'toPay' => (int) ($by['posted']->n ?? 0), 'toPayTotal' => number_format((float) ($by['posted']->due ?? 0), 2, '.', '')];
        }

        return $out;
    }

    // ───────────────────────────── writing a claim ─────────────────────────────

    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            [$employee, $claimantId] = $this->whose($user, $dto['employee'] ?? null);
            $c = Claim::create([
                'number' => $this->numbering->next('CLM'), 'title' => Hr::text($dto['title']), 'employee_id' => $employee?->id, 'claimant_user_id' => $claimantId,
                'department_id' => $employee?->department_id, 'state' => 'draft', 'currency' => 'SAR', 'notes' => Hr::text($dto['notes'] ?? null, 2000),
                'created_by' => $user->username, 'created_by_id' => $user->id,
            ]);
            $this->writeLines($user, $c, $dto['lines'] ?? []);
            $this->audit->log($user, ['action' => 'CLAIM.CREATE', 'entityType' => 'ExpenseClaim', 'entityId' => $c->id, 'entityNumber' => $c->number,
                'newValue' => ['employee' => $employee?->code, 'total' => (float) $c->total, 'lines' => $c->lines()->count()]]);
            $this->audit->status($user, 'ExpenseClaim', $c->id, $c->number, null, 'draft');

            return $this->get($user, $c->id);
        });
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $c = $this->own($user, $idOrNumber);
            $this->assertState($c, ['draft'], 'تُعدَّل المطالبة وهي مسودة فقط — اسحبها أولًا إن كانت بانتظار المراجعة', 'A claim is edited only as a draft — withdraw it first if it waits for review');
            $new = array_key_exists('notes', $dto) ? ['notes' => Hr::text($dto['notes'], 2000)] : [];
            if (array_key_exists('title', $dto) && ($title = Hr::text($dto['title'])) !== null) {
                $new['title'] = $title;
            }
            if ($changes = Hr::diff($c, $new)) {
                $c->update($changes);
            }
            if (array_key_exists('lines', $dto) && is_array($dto['lines'])) {
                $this->writeLines($user, $c, $dto['lines']);
            }
            $this->audit->log($user, ['action' => 'CLAIM.UPDATE', 'entityType' => 'ExpenseClaim', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['total' => (float) $c->refresh()->total, 'lines' => $c->lines()->count()]]);

            return $this->get($user, $c->id);
        });
    }

    /** A receipt on a line: while the claim is a draft, or still waiting for the reviewer. @param array{fileName:string, base64:string} $file */
    public function addReceipt(AuthUser $user, string $idOrNumber, string $lineId, array $file): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $lineId, $file) {
            $c = $this->own($user, $idOrNumber);
            $this->assertState($c, ['draft', 'submitted'], 'يُرفق الإيصال قبل اعتماد المطالبة', 'A receipt is attached before the claim is decided');
            $line = ClaimLine::where('claim_id', $c->id)->whereKey($lineId)->first() ?? throw AppError::notFound('CLAIM_LINE_NOT_FOUND', 'سطر المطالبة غير موجود', 'Claim line not found');
            $this->files->store($user, 'claim_line', $line->id, $file);

            return $this->get($user, $c->id);
        });
    }

    public function removeReceipt(AuthUser $user, string $idOrNumber, string $fileId): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $fileId) {
            $c = $this->own($user, $idOrNumber);
            $this->assertState($c, ['draft'], 'يُحذف الإيصال والمطالبة مسودة فقط', 'A receipt is removed only while the claim is a draft');
            $file = HrFile::whereKey($fileId)->where('owner_type', 'claim_line')->whereIn('owner_id', ClaimLine::where('claim_id', $c->id)->select('id'))->first()
                ?? throw AppError::notFound('HR_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
            $this->files->remove($user, $file);

            return $this->get($user, $c->id);
        });
    }

    // ───────────────────────────── the claimant's moves ─────────────────────────────

    public function submit(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $c = $this->own($user, $idOrNumber);
            $this->assertFrom($c, ['draft'], 'submitted');
            if ($c->lines()->count() === 0 || (float) $c->total <= 0) {
                throw AppError::rule('CLAIM_EMPTY', 'أضف سطرًا واحدًا على الأقل قبل الإرسال', 'Add at least one line before submitting');
            }
            $this->move($user, $c, 'submitted', null, ['submitted_at' => now(), 'decided_by' => null, 'decided_by_id' => null, 'decided_at' => null, 'decision_note' => null]);
            Hr::tellRoles('expense.review', "مطالبة مصروفات {$c->number} بانتظار المراجعة — ".number_format((float) $c->total, 2).' ر.س', "Expense claim {$c->number} waits for review — SAR ".number_format((float) $c->total, 2), 'ExpenseClaim', $c->id, $c->number);

            return $this->get($user, $c->id);
        });
    }

    /** The claimant takes a claim back before it is decided. */
    public function withdraw(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $c = $this->own($user, $idOrNumber);
            $this->assertState($c, ['submitted'], 'تُسحب المطالبة وهي بانتظار المراجعة فقط', 'Only a claim that waits for review can be withdrawn');
            $this->move($user, $c, 'draft', 'سحبها صاحبها', ['submitted_at' => null]);

            return $this->get($user, $c->id);
        });
    }

    public function cancel(AuthUser $user, string $idOrNumber, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->own($user, $idOrNumber);
            $this->assertFrom($c, ['draft'], 'cancelled');
            $this->move($user, $c, 'cancelled', Hr::text($reason, 500));

            return $this->get($user, $c->id);
        });
    }

    // ───────────────────────────── the reviewer's moves ─────────────────────────────

    public function approve(AuthUser $user, string $idOrNumber, ?string $note): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $c = $this->forReview($user, $idOrNumber, 'approved');
            $this->move($user, $c, 'approved', Hr::text($note, 500), ['decided_by' => $user->username, 'decided_by_id' => $user->id, 'decided_at' => now(), 'decision_note' => Hr::text($note, 500)]);
            $this->tellClaimant($c, "اعتُمدت مطالبتك {$c->number} — بانتظار الترحيل والسداد", "Your claim {$c->number} was approved — it now waits for posting and payment");

            return $this->get($user, $c->id);
        });
    }

    public function refuse(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = $this->reason($reason, 'الرفض', 'the refusal');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->forReview($user, $idOrNumber, 'refused');
            $this->move($user, $c, 'refused', $reason, ['decided_by' => $user->username, 'decided_by_id' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $this->tellClaimant($c, "رُفضت مطالبتك {$c->number}: {$reason}", "Your claim {$c->number} was refused: {$reason}");

            return $this->get($user, $c->id);
        });
    }

    /** Back to the claimant for correction: it becomes a draft again and carries the reviewer's words. */
    public function sendBack(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = $this->reason($reason, 'الإعادة للتصحيح', 'sending it back');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->forReview($user, $idOrNumber, 'draft');
            $this->move($user, $c, 'draft', "أُعيدت للتصحيح: {$reason}", ['submitted_at' => null, 'decided_by' => $user->username, 'decided_by_id' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $this->tellClaimant($c, "أُعيدت مطالبتك {$c->number} للتصحيح: {$reason}", "Your claim {$c->number} was returned for correction: {$reason}");

            return $this->get($user, $c->id);
        });
    }

    /** An approval taken back before anything was posted: the claim waits for a decision again. */
    public function reopen(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = $this->reason($reason, 'التراجع عن الاعتماد', 'taking the approval back');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->locked($idOrNumber);
            $this->assertState($c, ['approved'], 'يُتراجع عن الاعتماد قبل الترحيل فقط', 'An approval is taken back only before posting');
            $this->move($user, $c, 'submitted', "تراجع عن الاعتماد: {$reason}", ['decided_by' => null, 'decided_by_id' => null, 'decided_at' => null, 'decision_note' => null]);

            return $this->get($user, $c->id);
        });
    }

    // ───────────────────────────── the books ─────────────────────────────

    /**
     * approved → posted: one expense voucher per line, made by the finance module.
     *
     * @param  array{date?:?string, bankAccount?:?string, reimburse?:?string, reference?:?string, costCenter?:?string, accounts?:array<string,?string>}  $dto
     */
    public function post(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $c = $this->locked($idOrNumber);
            $this->assertFrom($c, ['approved'], 'posted');
            if ($c->lines()->count() === 0) {
                throw AppError::rule('CLAIM_EMPTY', 'المطالبة بلا سطور', 'The claim has no lines');
            }
            $date = Hr::date($dto['date'] ?? null);
            if ($date !== null && $date > Carbon::today()->toDateString()) {
                throw AppError::validation('DATE_FUTURE', 'تاريخ الترحيل لا يكون في المستقبل', 'The posting date cannot be in the future');
            }
            $due = (float) $c->employee_due;
            $now = $due > 0.004 && ($dto['reimburse'] ?? 'later') === 'now';
            $reference = Hr::text($dto['reference'] ?? null, 80);
            if ($now && $reference === null) {
                throw AppError::validation('PAYMENT_REF_REQUIRED', 'مرجع السداد (رقم السند أو الحوالة) مطلوب عند صرف التعويض الآن', 'A payment reference is required when the reimbursement is paid now');
            }
            $bank = ! empty($dto['bankAccount']) ? $this->bank((string) $dto['bankAccount']) : null;
            $numbers = $this->posting->post($user, $c, ['date' => $date, 'bankAccount' => $bank?->code, 'reimburseNow' => $now, 'costCenter' => $dto['costCenter'] ?? null, 'accounts' => (array) ($dto['accounts'] ?? [])]);
            $settlement = $due <= 0.004 ? 'none' : ($now ? 'bank' : 'accrued');
            $this->move($user, $c, 'posted', 'سندات: '.implode('، ', $numbers), ['posted_by' => $user->username, 'posted_at' => now(), 'posted_in' => 'ops', 'accounting_date' => $date, 'settlement' => $settlement]);
            if ($settlement !== 'accrued') { // nothing is owed any more: the vouchers themselves are the payment
                $this->move($user, $c, 'paid', $settlement === 'none' ? 'لا مبلغ مستحق للموظف — دفعت الشركة كل السطور' : "صُرف التعويض عند الترحيل — مرجع {$reference}",
                    ['paid_by' => $user->username, 'paid_at' => now(), 'payment_ref' => $reference, 'payment_method' => $now ? ($bank?->kind === 'cash' ? 'cash' : 'bank') : null, 'payment_account' => $now ? $bank?->code : null]);
            }
            $this->audit->log($user, ['action' => 'CLAIM.POST', 'entityType' => 'ExpenseClaim', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['vouchers' => $numbers, 'settlement' => $settlement, 'total' => (float) $c->total]]);
            $this->tellClaimant($c, $settlement === 'accrued' ? "رُحِّلت مطالبتك {$c->number} — بانتظار صرف ".number_format($due, 2).' ر.س' : "أُقفلت مطالبتك {$c->number}".($now ? ' — صُرف التعويض' : ''),
                $settlement === 'accrued' ? "Your claim {$c->number} was posted — SAR ".number_format($due, 2).' to be paid' : "Your claim {$c->number} is settled");

            return $this->get($user, $c->id);
        });
    }

    /** Takes the posting back: the vouchers are voided by the finance module and the claim is `approved` again. */
    public function unpost(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = $this->reason($reason, 'عكس الترحيل', 'reversing the posting');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->locked($idOrNumber);
            $this->assertState($c, ['posted', 'paid'], 'لا ترحيل يُعكس: المطالبة لم تُرحَّل', 'There is no posting to reverse');
            if ($c->posted_in !== 'ops') {
                throw AppError::rule('CLAIM_BOOKS_ELSEWHERE', "المطالبة {$c->number} مرحَّلة في دفاتر أودو لا في دفاتر هذا النظام — لا قيد هنا ليُعكس", "Claim {$c->number} was posted in Odoo's books, not here — there is nothing to reverse");
            }
            if ($c->state === 'paid' && $c->settlement === 'accrued') {
                throw AppError::rule('CLAIM_PAID', "سُجِّل سداد المطالبة {$c->number} (مرجع {$c->payment_ref}) — تراجع عن السداد أولًا ثم اعكس الترحيل", "The payment of claim {$c->number} is on record (reference {$c->payment_ref}) — take the payment back first");
            }
            $numbers = $this->posting->reverse($user, $c, $reason);
            $this->move($user, $c, 'approved', mb_substr("عُكس الترحيل ({$reason}) — سندات ملغاة: ".implode('، ', $numbers), 0, 900), ['posted_by' => null, 'posted_at' => null, 'posted_in' => null,
                'accounting_date' => null, 'settlement' => null, 'paid_by' => null, 'paid_at' => null, 'payment_ref' => null, 'payment_method' => null, 'payment_account' => null]);
            $this->audit->log($user, ['action' => 'CLAIM.UNPOST', 'entityType' => 'ExpenseClaim', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['voided' => $numbers, 'reason' => $reason]]);

            return $this->get($user, $c->id);
        });
    }

    /**
     * posted → paid: the reimbursement left the bank or the cash box. Recorded with its reference; see ClaimPosting::settle
     * for what this does (not yet) do in the books.
     *
     * @param  array{reference:string, date?:?string, method?:?string, bankAccount?:?string}  $dto
     */
    public function pay(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $c = $this->locked($idOrNumber);
            $this->assertFrom($c, ['posted'], 'paid');
            $reference = Hr::text($dto['reference'] ?? null, 80) ?? throw AppError::validation('PAYMENT_REF_REQUIRED', 'مرجع السداد (رقم الحوالة / السند) مطلوب', 'The payment reference is required');
            $date = Hr::date($dto['date'] ?? null) ?? Carbon::today()->toDateString();
            if ($date > Carbon::today()->toDateString()) {
                throw AppError::validation('DATE_FUTURE', 'تاريخ السداد لا يكون في المستقبل', 'The payment date cannot be in the future');
            }
            $bank = ! empty($dto['bankAccount']) ? $this->bank((string) $dto['bankAccount']) : null;
            $method = in_array($dto['method'] ?? null, ['bank', 'cash', 'cheque'], true) ? $dto['method'] : ($bank?->kind === 'cash' ? 'cash' : 'bank');
            $this->posting->settle($user, $c, ['reference' => $reference, 'date' => $date, 'bankAccount' => $bank?->code]);
            $this->move($user, $c, 'paid', "مرجع السداد {$reference}", ['paid_by' => $user->username, 'paid_at' => Carbon::parse($date)->setTimeFrom(now()), 'payment_ref' => $reference, 'payment_method' => $method, 'payment_account' => $bank?->code]);
            $this->audit->log($user, ['action' => 'CLAIM.PAY', 'entityType' => 'ExpenseClaim', 'entityId' => $c->id, 'entityNumber' => $c->number, 'newValue' => ['reference' => $reference, 'amount' => (float) $c->employee_due, 'method' => $method, 'account' => $bank?->code]]);
            $this->tellClaimant($c, "صُرفت مطالبتك {$c->number} — ".number_format((float) $c->employee_due, 2)." ر.س · مرجع {$reference}", "Your claim {$c->number} was paid — SAR ".number_format((float) $c->employee_due, 2)." · reference {$reference}");

            return $this->get($user, $c->id);
        });
    }

    /** A payment recorded by mistake is taken back: the claim waits for payment again. */
    public function unpay(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = $this->reason($reason, 'التراجع عن السداد', 'taking the payment back');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $c = $this->locked($idOrNumber);
            $this->assertState($c, ['paid'], 'لا سداد مسجَّل ليُتراجع عنه', 'There is no recorded payment to take back');
            if (in_array($c->settlement, ['bank', 'none'], true)) {
                throw AppError::rule('CLAIM_SETTLED_BY_POSTING', "المطالبة {$c->number} أُقفلت بالترحيل نفسه (السندات هي السداد) — اعكس الترحيل بدلًا من ذلك", "Claim {$c->number} was settled by its posting (the vouchers are the payment) — reverse the posting instead");
            }
            $this->move($user, $c, 'posted', "تراجع عن السداد: {$reason}", ['paid_by' => null, 'paid_at' => null, 'payment_ref' => null, 'payment_method' => null, 'payment_account' => null]);

            return $this->get($user, $c->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    private function find(string $idOrNumber): Claim
    {
        return Claim::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('CLAIM_NOT_FOUND', 'المطالبة غير موجودة', 'Claim not found');
    }

    /** The claim under a row lock, refused when it is Odoo's right now. Must run inside a transaction. */
    private function locked(string $idOrNumber): Claim
    {
        $c = Claim::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('CLAIM_NOT_FOUND', 'المطالبة غير موجودة', 'Claim not found');
        Source::assertFree($c->source_system, 'claim', "المطالبة {$c->number}", "Claim {$c->number}");

        return $c;
    }

    /** …and it must be the caller's own. */
    private function own(AuthUser $user, string $idOrNumber): Claim
    {
        $c = Claim::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('CLAIM_NOT_FOUND', 'المطالبة غير موجودة', 'Claim not found');
        if (! ClaimAccess::isMine($user, $c)) {
            throw AppError::forbidden('CLAIM_NOT_YOURS', 'هذه المطالبة ليست لك — يعدّلها صاحبها أو من أدخلها', 'This claim is not yours — its owner or whoever entered it changes it');
        }
        Source::assertFree($c->source_system, 'claim', "المطالبة {$c->number}", "Claim {$c->number}");

        return $c;
    }

    /** A claim a reviewer is about to decide: it waits for review, and the reviewer is neither its claimant nor its maker. */
    private function forReview(AuthUser $user, string $idOrNumber, string $to): Claim
    {
        $c = $this->locked($idOrNumber);
        $this->assertState($c, ['submitted'], 'القرار يكون على مطالبة بانتظار المراجعة', 'A decision is taken on a claim that waits for review');
        $this->assertMove($c, $to);
        if ($this->selfReview($user, $c)) {
            throw AppError::rule('CLAIM_SELF_REVIEW', "لا تراجع مطالبة أدخلتها أو تخصّك ({$c->number}) — فصل المسؤوليات؛ يقرّرها مراجع آخر", "You cannot decide a claim that is yours or that you entered ({$c->number}) — another reviewer decides it");
        }

        return $c;
    }

    private function selfReview(AuthUser $user, Claim $c): bool
    {
        return ClaimAccess::isMine($user, $c) && ! (bool) $this->settings->get('procurement.allowSelfApproval');
    }

    private function assertMove(Claim $c, string $to): void
    {
        if (! Claim::can($c->state, $to)) {
            throw AppError::rule('CLAIM_TRANSITION', "انتقال غير مسموح للمطالبة {$c->number}: {$this->stateAr($c->state)} ← {$this->stateAr($to)}", "Invalid transition for claim {$c->number}: {$c->state} -> {$to}", ['from' => $c->state, 'to' => $to]);
        }
    }

    /**
     * An act starts from its OWN state(s) only. The state table alone is not enough: it also holds the way back
     * (approved → submitted takes an approval back, paid → posted takes a payment back), and "submit" must never be
     * that way back for a claimant, nor "post" a second posting of a claim that is already paid.
     *
     * @param  string[]  $from
     */
    private function assertFrom(Claim $c, array $from, string $to): void
    {
        if (! in_array($c->state, $from, true) || ! Claim::can($c->state, $to)) {
            throw AppError::rule('CLAIM_TRANSITION', "انتقال غير مسموح للمطالبة {$c->number}: {$this->stateAr($c->state)} ← {$this->stateAr($to)}", "Invalid transition for claim {$c->number}: {$c->state} -> {$to}", ['from' => $c->state, 'to' => $to]);
        }
    }

    /** @param string[] $states */
    private function assertState(Claim $c, array $states, string $ar, string $en): void
    {
        if (! in_array($c->state, $states, true)) {
            throw AppError::rule('CLAIM_STATE', "{$ar} (حالة {$c->number}: {$this->stateAr($c->state)})", "{$en} ({$c->number} is {$c->state})", ['state' => $c->state]);
        }
    }

    /** One step of the state table, with its line in the history. */
    private function move(AuthUser $user, Claim $c, string $to, ?string $note, array $extra = []): void
    {
        $from = $c->state;
        $this->assertMove($c, $to);
        $c->update(['state' => $to] + $extra);
        $this->audit->status($user, 'ExpenseClaim', $c->id, $c->number, $from, $to, $note);
    }

    private function reason(string $reason, string $forAr, string $forEn): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw AppError::validation('REASON_REQUIRED', "اكتب سبب {$forAr} (5 أحرف على الأقل)", "A reason for {$forEn} is required (at least 5 characters)");
        }

        return mb_substr($reason, 0, 500);
    }

    private function stateAr(?string $state): string
    {
        return ['draft' => 'مسودة', 'submitted' => 'بانتظار المراجعة', 'approved' => 'معتمدة', 'posted' => 'مرحَّلة', 'paid' => 'مسدَّدة', 'refused' => 'مرفوضة', 'cancelled' => 'ملغاة'][$state ?? ''] ?? (string) $state;
    }

    private function bank(string $code): BankAccount
    {
        $b = BankAccount::where('code', $code)->orWhere('id', $code)->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي أو الصندوق غير موجود', 'Bank account not found');
        if (! $b->active) {
            throw AppError::rule('BANK_ACCOUNT_INACTIVE', "الحساب {$b->code} غير نشط", "Bank account {$b->code} is inactive");
        }

        return $b;
    }

    /**
     * Whose expenses a new claim holds: the caller's own, or — a reviewer entering it for someone without a login —
     * a named employee's.
     *
     * @return array{0: ?Employee, 1: ?string} the employee record (when there is one) and the claimant's login id
     */
    private function whose(AuthUser $user, ?string $employee): array
    {
        $own = Employee::where('user_id', $user->id)->first();
        if ($employee === null || $employee === '') {
            if (! $user->can('expense.claim')) {
                throw AppError::forbidden('FORBIDDEN', 'تقديم مطالبة لنفسك يحتاج صلاحية expense.claim', 'Filing your own claim needs expense.claim');
            }

            return [$own, $user->id];
        }
        $e = Employee::where('id', $employee)->orWhere('code', $employee)->first() ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
        if ($own && $e->id === $own->id) {
            return [$own, $user->id];
        }
        if (! $user->can('expense.review')) {
            throw AppError::forbidden('CLAIM_ON_BEHALF', 'إدخال مطالبة عن موظف آخر يحتاج صلاحية مراجعة المصروفات', 'Entering a claim for another employee needs expense.review');
        }

        return [$e, $e->user_id];
    }

    /**
     * The lines of a draft as the client sends them: a line with an `id` is changed, one without is added, one that is
     * no longer sent is removed (with its receipts). Figures are recomputed here — nothing the client computed is kept.
     */
    private function writeLines(AuthUser $user, Claim $c, array $lines): void
    {
        $existing = ClaimLine::where('claim_id', $c->id)->get()->keyBy('id');
        $today = Carbon::today()->toDateString();
        $errors = [];
        $kept = [];
        $no = 0;
        foreach (array_values($lines) as $i => $l) {
            $no++;
            $line = ! empty($l['id']) ? ($existing[$l['id']] ?? null) : null;
            if (! empty($l['id']) && ! $line) {
                $errors[] = ['path' => "lines.{$i}.id", 'message' => 'سطر غير موجود في هذه المطالبة'];

                continue;
            }
            $date = Hr::date($l['date'] ?? null);
            if ($date === null || $date > $today) {
                $errors[] = ['path' => "lines.{$i}.date", 'message' => $date === null ? 'تاريخ المصروف مطلوب' : 'تاريخ المصروف لا يكون في المستقبل'];
            }
            $category = ! empty($l['category']) ? ExpenseCategory::where('id', $l['category'])->orWhere('code', $l['category'])->first() : null;
            if (! $category || (! $category->active && $line?->category_id !== $category->id)) {
                $errors[] = ['path' => "lines.{$i}.category", 'message' => 'اختر فئة المصروف'];
            }
            $description = Hr::text($l['description'] ?? null, 500);
            if ($description === null || mb_strlen($description) < 2) {
                $errors[] = ['path' => "lines.{$i}.description", 'message' => 'اكتب وصف المصروف'];
            }
            $amount = round((float) ($l['amount'] ?? 0), 2);
            if ($amount <= 0) {
                $errors[] = ['path' => "lines.{$i}.amount", 'message' => 'المبلغ يجب أن يكون أكبر من صفر'];
            }
            $pct = array_key_exists('vatPct', $l) && $l['vatPct'] !== null && $l['vatPct'] !== '' ? (float) $l['vatPct'] : (float) ($category?->vat_pct ?? 0);
            if ($pct < 0 || $pct > 100) {
                $errors[] = ['path' => "lines.{$i}.vatPct", 'message' => 'نسبة الضريبة بين 0 و100'];
            }
            if ($errors) {
                continue;
            }
            $values = ['line_no' => $no, 'expense_date' => $date, 'category_id' => $category->id, 'description' => $description,
                'paid_by' => in_array($l['paidBy'] ?? null, ClaimLine::PAID_BY, true) ? $l['paidBy'] : 'employee']
                + self::figures($amount, $pct, filter_var($l['amountIncludesVat'] ?? false, FILTER_VALIDATE_BOOLEAN));
            if ($line) {
                $line->update($values);
                $kept[$line->id] = true;
            } else {
                $kept[ClaimLine::create(['claim_id' => $c->id] + $values)->id] = true;
            }
        }
        if ($errors) {
            throw AppError::validation('INVALID_INPUT', 'راجع سطور المطالبة', 'Check the lines of the claim', $errors);
        }
        foreach ($existing as $id => $line) {
            if (! isset($kept[$id])) {
                foreach ($this->files->of('claim_line', $id) as $file) {
                    $this->files->remove($user, $file);
                }
                $line->delete(); // a line of a draft, taken out by its author before anyone decided on it
            }
        }
        $this->recalc($c);
    }

    /**
     * The figures of a line the way the finance voucher will compute them: amount before VAT, VAT = round(amount × %),
     * total = amount + VAT. When the person typed the total of the receipt, the amount is the one whose VAT brings the
     * total back to what the receipt says (to the cent, whenever such an amount exists).
     *
     * @return array{amount: float, vat_pct: float, vat_amount: float, total: float}
     */
    public static function figures(float $amount, float $pct, bool $includesVat): array
    {
        $vatOf = fn (float $net) => round($net * $pct / 100, 2);
        $net = round($amount, 2);
        if ($includesVat && $pct > 0) {
            $gross = $net;
            $net = round($gross / (1 + $pct / 100), 2);
            foreach ([0.0, 0.01, -0.01] as $shift) {
                $try = round($net + $shift, 2);
                if (abs($try + $vatOf($try) - $gross) < 0.005) {
                    $net = $try;
                    break;
                }
            }
        }
        $vat = $vatOf($net);

        return ['amount' => $net, 'vat_pct' => $pct, 'vat_amount' => $vat, 'total' => round($net + $vat, 2)];
    }

    /** The totals of a claim are the sums of its lines. */
    public function recalc(Claim $c): void
    {
        $s = ClaimLine::where('claim_id', $c->id)->selectRaw("coalesce(sum(amount), 0) as amount, coalesce(sum(vat_amount), 0) as vat, coalesce(sum(total), 0) as total,
            coalesce(sum(case when paid_by = 'company' then total else 0 end), 0) as company")->first();
        $c->update(['amount' => round((float) $s->amount, 2), 'vat_amount' => round((float) $s->vat, 2), 'total' => round((float) $s->total, 2),
            'company_paid' => round((float) $s->company, 2), 'employee_due' => round((float) $s->total - (float) $s->company, 2)]);
    }

    private function tellClaimant(Claim $c, string $ar, string $en): void
    {
        Hr::tellUser($c->claimant_user_id ?: ($c->employee_id ? Employee::whereKey($c->employee_id)->value('user_id') : null) ?: $c->created_by_id, $ar, $en, 'ExpenseClaim', $c->id, $c->number);
    }

    /** What the rows of a page need beyond the claim itself, read once. @param Claim[] $claims */
    private function rowExtras(array $claims): array
    {
        $ids = array_map(fn (Claim $c) => $c->id, $claims);
        $userIds = array_values(array_unique(array_filter(array_map(fn (Claim $c) => $c->claimant_user_id, $claims))));
        $withReceipt = fn ($q) => $q->select('owner_id')->from('hr_files')->where('owner_type', 'claim_line')->whereNull('removed_at');

        return [
            'users' => $userIds ? User::whereIn('id', $userIds)->get(['id', 'username', 'name_ar', 'name_en'])->keyBy('id') : collect(),
            'noReceipt' => $ids ? ClaimLine::whereIn('claim_id', $ids)->whereNotIn('id', $withReceipt)->selectRaw('claim_id, count(*) as n')->groupBy('claim_id')->pluck('n', 'claim_id') : collect(),
        ];
    }

    private function row(Claim $c, AuthUser $user, array $extra): array
    {
        $login = $c->claimant_user_id ? ($extra['users'][$c->claimant_user_id] ?? null) : null;

        return [
            'id' => $c->id, 'number' => $c->number, 'title' => $c->title, 'state' => $c->state,
            'employee' => $c->employee ? ['id' => $c->employee->id, 'code' => $c->employee->code, 'nameAr' => $c->employee->name_ar, 'nameEn' => $c->employee->name_en] : null,
            'claimant' => $login ? ['id' => $login->id, 'username' => $login->username, 'nameAr' => $login->name_ar, 'nameEn' => $login->name_en] : null,
            'department' => $c->department ? ['id' => $c->department->id, 'nameAr' => $c->department->name_ar, 'nameEn' => $c->department->name_en] : null,
            'currency' => $c->currency, 'amount' => $c->amount, 'vatAmount' => $c->vat_amount, 'total' => $c->total, 'employeeDue' => $c->employee_due, 'companyPaid' => $c->company_paid,
            'lineCount' => (int) ($c->lines_count ?? 0), 'linesWithoutReceipt' => (int) ($extra['noReceipt'][$c->id] ?? 0),
            'submittedAt' => Hr::at($c->submitted_at), 'decidedAt' => Hr::at($c->decided_at), 'postedAt' => Hr::at($c->posted_at), 'paidAt' => Hr::at($c->paid_at),
            'createdBy' => $c->created_by, 'createdAt' => Hr::at($c->created_at), 'mine' => ClaimAccess::isMine($user, $c),
            'source' => $c->source_system, 'locked' => Source::locked($c->source_system),
        ];
    }

    /** The vouchers of the lines as the finance module holds them now. @param string[] $ids */
    private function vouchers(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = Expense::whereIn('id', $ids)->get(['id', 'number', 'status', 'settlement', 'journal_id']);
        $journals = Journal::whereIn('id', $rows->pluck('journal_id')->filter()->all())->pluck('number', 'id');

        return $rows->mapWithKeys(fn (Expense $e) => [$e->id => ['id' => $e->id, 'number' => $e->number, 'status' => $e->status, 'settlement' => $e->settlement, 'journal' => $journals[$e->journal_id] ?? null]])->all();
    }

    /** The story of the claim, oldest first: every move OPS recorded; for a claim that came from Odoo, what Odoo's dates tell. */
    private function history(Claim $c): array
    {
        $rows = StatusHistory::where('entity_type', 'ExpenseClaim')->where('entity_id', $c->id)->orderBy('at')->orderBy('id')->get()
            ->map(fn (StatusHistory $h) => ['at' => Hr::at($h->at), 'from' => $h->from_status, 'to' => $h->to_status, 'by' => $h->username, 'note' => $h->note, 'origin' => 'ops'])->all();
        if ($c->source_system !== Source::ODOO) {
            return $rows;
        }
        $odoo = [['at' => Hr::at($c->created_at), 'from' => null, 'to' => 'draft', 'by' => null, 'note' => null, 'origin' => 'odoo']];
        if ($c->decided_at) {
            $odoo[] = ['at' => Hr::at($c->decided_at), 'from' => 'submitted', 'to' => $c->state === 'refused' ? 'refused' : 'approved', 'by' => $c->decided_by, 'note' => null, 'origin' => 'odoo'];
        }
        if ($c->posted_in === 'odoo' && $c->posted_at) {
            $odoo[] = ['at' => Hr::at($c->posted_at), 'from' => 'approved', 'to' => 'posted', 'by' => null, 'note' => null, 'origin' => 'odoo'];
        }

        return array_merge($odoo, $rows);
    }

    /** What the person may do with the claim now — the client shows these buttons and no others; the server checks again on every call. */
    private function can(AuthUser $user, Claim $c): array
    {
        $free = ! Source::locked($c->source_system);
        $mine = $free && ClaimAccess::isMine($user, $c);
        $review = $free && $user->can('expense.review');
        $books = $review && $user->can('expense.create');
        $self = $review && $this->selfReview($user, $c);
        $decide = $review && $c->state === 'submitted' && ! $self;
        $settledByPosting = in_array($c->settlement, ['bank', 'none'], true);

        return [
            'edit' => $mine && $c->state === 'draft', 'submit' => $mine && $c->state === 'draft', 'cancel' => $mine && $c->state === 'draft', 'withdraw' => $mine && $c->state === 'submitted',
            'addReceipt' => $mine && in_array($c->state, ['draft', 'submitted'], true),
            'approve' => $decide, 'refuse' => $decide, 'sendBack' => $decide, 'selfReview' => $self && $c->state === 'submitted',
            'reopen' => $review && $c->state === 'approved',
            'post' => $books && $c->state === 'approved', 'pay' => $books && $c->state === 'posted',
            'unpay' => $books && $c->state === 'paid' && ! $settledByPosting,
            'unpost' => $review && $user->can('gl.approve') && $c->posted_in === 'ops' && ($c->state === 'posted' || ($c->state === 'paid' && $settledByPosting)),
        ];
    }
}
