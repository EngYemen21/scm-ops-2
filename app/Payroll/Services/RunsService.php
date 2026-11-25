<?php

namespace App\Payroll\Services;

use App\Finance\Models\BankAccount;
use App\Finance\Services\LedgerService;
use App\Hr\Models\Claim;
use App\Hr\Models\Employee;
use App\Hr\Services\ClaimsService;
use App\Hr\Support\Hr;
use App\Models\StatusHistory;
use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\Run;
use App\Payroll\Models\Sheet;
use App\Payroll\Support\Access;
use App\Payroll\Support\Money;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Services\Core\SettingsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The monthly payroll run.
 *
 *   draft ──submit──▶ review ──approve──▶ approved ──post──▶ posted ──pay──▶ paid
 *     ▲  ◀──return───┘                       │                  │  ◀─unpay─┘
 *     └──cancel──▶ cancelled                 └────reverse───────┴──▶ reversed
 *
 * WHO. `payroll.manage` creates, computes, submits, posts and pays; `payroll.approve` approves — never the person who
 * submitted it (the policy `procurement.allowSelfApproval` switches that off) — returns it with a reason, and reverses.
 * The steps that touch the books ask for the finance permission that guards the same act there: `gl.post` to post and
 * to pay, `gl.approve` to reverse what was posted.
 *
 * WHAT NEVER CHANGES. While a run is a draft its payslips are computed again at will. Submitting computes one last
 * time; approving proves the stored payslips still equal a fresh computation (`PAYRUN_STALE` otherwise). From
 * `approved` on nothing is recomputed, edited or removed: a mistake is undone by REVERSING the run — its payslips
 * release their contracts' month, its journal is reversed by the ledger, its adjustments wait again — or corrected by
 * an ADJUSTMENT that the next run carries.
 *
 * NEVER TWICE. A contract has one standing payslip per month (`pay_payslips.live_key`, unique); a run leaves out a
 * contract another run already pays and says so.
 *
 * The audit trail of a run holds counts and totals of the run — never a person's figure.
 */
class RunsService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly SettingsService $settings,
        private readonly RunBuilder $builder,
        private readonly RunPosting $posting,
        private readonly LedgerService $ledger,
        private readonly ClaimsService $claims,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{state?:?string, period?:?string, year?:?string, department?:?string, source?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        Access::needPay($user);
        $q = Run::query()->with('department');
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Run::STATES) ?: ['-']);
        }
        if (! empty($f['period'])) {
            $q->where('period', $f['period']);
        }
        if (! empty($f['year'])) {
            $q->where('period', 'like', ((int) $f['year']).'-%');
        }
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        if (! empty($f['source'])) {
            $f['source'] === Owned::ODOO ? $q->where('source_system', Owned::ODOO) : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('name', 'like', $like)->orWhere('period', 'like', $like));
        }
        $totals = (clone $q)->toBase()->whereIn('state', Run::LIVE)->selectRaw('count(*) as n, coalesce(sum(net), 0) as net, coalesce(sum(gross), 0) as gross')->first();
        $column = ['number' => 'number', 'net' => 'net', 'state' => 'state', 'created' => 'created_at'][$page->sort ?? 'period'] ?? 'period';
        $q->orderBy($column, $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (Run $r) => $this->row($r, $user)) + ['totals' => ['standing' => (int) $totals->n, 'net' => number_format((float) $totals->net, 2, '.', ''), 'gross' => number_format((float) $totals->gross, 2, '.', '')]];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        Access::needPay($user);
        $r = $this->find($idOrNumber)->load('department');
        $slips = Payslip::where('run_id', $r->id)->with(['employee', 'department'])->orderBy('number')->get();

        return $this->row($r, $user) + [
            'skipped' => $r->skipped ?? [], 'warnings' => $r->warnings ?? [], 'approvalNote' => $r->approval_note, 'closeReason' => $r->close_reason,
            'postedBy' => $r->posted_by, 'paidBy' => $r->paid_by, 'paymentRef' => $r->payment_ref, 'paymentAccount' => $r->payment_account, 'paymentDate' => Shape::day($r->payment_date),
            'accountingDate' => Shape::day($r->accounting_date), 'sourceState' => $r->source_state, 'externalRef' => $r->external_ref,
            'journals' => RunPosting::journals($r),
            'payslips' => $slips->map(fn (Payslip $p) => PayslipsService::row($p, $user))->all(),
            'history' => StatusHistory::where('entity_type', 'PayrollRun')->where('entity_id', $r->id)->orderBy('at')->orderBy('id')->get()
                ->map(fn (StatusHistory $h) => ['at' => Shape::at($h->at), 'from' => $h->from_status, 'to' => $h->to_status, 'by' => $h->username, 'note' => $h->note])->all(),
        ];
    }

    /** The journal posting would write, for a person to read first. */
    public function journal(AuthUser $user, string $idOrNumber): array
    {
        Access::needPay($user);

        return $this->posting->shown($this->find($idOrNumber));
    }

    // ───────────────────────────── a draft ─────────────────────────────

    /** @param  array{period:string, department?:?string, name?:?string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $period = Shape::period($dto['period']);
            $from = Carbon::parse("{$period}-01");
            $department = ! empty($dto['department']) ? (DB::table('hr_departments')->where('id', $dto['department'])->value('id') ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found')) : null;
            // one open run per month and scope: a second draft beside it would only compete for the same contracts
            $open = Run::where('period', $period)->whereIn('state', ['draft', 'review'])->where(fn ($w) => $department ? $w->where('department_id', $department)->orWhereNull('department_id') : $w)->lockForUpdate()->first();
            if ($open) {
                throw AppError::rule('PAYRUN_OPEN_EXISTS', "للشهر {$period} مسير لم يُعتمد بعد ({$open->number}) — أكمِله أو ألغِه أولًا", "Month {$period} already has a run that is not approved yet ({$open->number}) — finish or cancel it first", ['run' => $open->number]);
            }
            $r = Run::create(['number' => $this->numbering->next('PYR'), 'name' => Shape::text($dto['name'] ?? null, 190) ?? "رواتب {$period}", 'period' => $period, 'date_from' => $from->format('Y-m-d'),
                'date_to' => $from->copy()->endOfMonth()->format('Y-m-d'), 'department_id' => $department, 'state' => 'draft', 'currency' => 'SAR', 'created_by' => $user->username]);
            $this->audit->status($user, 'PayrollRun', $r->id, $r->number, null, 'draft');
            $this->builder->write($r, $this->builder->compute($r), $user, $this->numbering);
            $this->audit->log($user, ['action' => 'PAYRUN.CREATE', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => $this->figures($r->refresh())]);

            return $this->get($user, $r->id);
        });
    }

    public function compute(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $r = $this->locked($idOrNumber);
            $this->assertState($r, Run::OPEN, 'يُعاد حساب المسير وهو مسودة فقط', 'A run is computed again only as a draft');
            $this->builder->write($r, $this->builder->compute($r), $user, $this->numbering);
            $this->audit->log($user, ['action' => 'PAYRUN.COMPUTE', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => $this->figures($r->refresh())]);

            return $this->get($user, $r->id);
        });
    }

    /** draft → review. The payslips are computed one last time, so what is reviewed is what the inputs say now. */
    public function submit(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['draft'], 'review');
            $this->builder->write($r, $this->builder->compute($r), $user, $this->numbering);
            $r->refresh();
            if ($r->slip_count === 0) {
                throw AppError::rule('PAYRUN_EMPTY', 'لا قسيمة في المسير — لا عقد سارٍ في هذا الشهر ضمن نطاقه', 'The run has no payslip — no contract is in force this month within its scope');
            }
            $negative = Payslip::where('run_id', $r->id)->where('net', '<', 0)->orderBy('number')->pluck('number')->all();
            if ($negative) {
                throw AppError::rule('PAYRUN_NEGATIVE_NET', 'في المسير قسائم صافيها سالب ('.implode('، ', array_slice($negative, 0, 8)).') — راجِع الاستقطاعات أو أجِّل بعضها بتسوية',
                    'Some payslips have a negative net ('.implode(', ', array_slice($negative, 0, 8)).') — review the deductions', ['payslips' => $negative]);
            }
            $this->move($user, $r, 'review', null, ['submitted_by' => $user->username, 'submitted_by_id' => $user->id, 'submitted_at' => now(), 'approved_by' => null, 'approved_by_id' => null, 'approved_at' => null, 'approval_note' => null]);
            Hr::tellRoles('payroll.approve', "مسير الرواتب {$r->number} ({$r->period}) بانتظار الاعتماد", "Payroll run {$r->number} ({$r->period}) waits for approval", 'PayrollRun', $r->id, $r->number);

            return $this->get($user, $r->id);
        });
    }

    /** review → draft, with the reason. */
    public function sendBack(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = Shape::reason($reason, 'إعادة المسير', 'returning the run');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['review'], 'draft');
            $this->move($user, $r, 'draft', "أُعيد للتصحيح: {$reason}", ['submitted_by' => null, 'submitted_by_id' => null, 'submitted_at' => null]);

            return $this->get($user, $r->id);
        });
    }

    public function cancel(AuthUser $user, string $idOrNumber, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['draft', 'review'], 'cancelled');
            $this->release($r);
            $this->move($user, $r, 'cancelled', Shape::text($reason, 500), ['closed_by' => $user->username, 'closed_at' => now(), 'close_reason' => Shape::text($reason, 500)]);

            return $this->get($user, $r->id);
        });
    }

    // ───────────────────────────── approval ─────────────────────────────

    public function approve(AuthUser $user, string $idOrNumber, ?string $note): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['review'], 'approved');
            if ($this->selfApproval($user, $r)) {
                throw AppError::rule('PAYRUN_SELF_APPROVAL', "لا يعتمد مُرسِل المسير {$r->number} مسيره — فصل المسؤوليات؛ يعتمده شخص آخر بصلاحية اعتماد الرواتب", "The person who submitted run {$r->number} cannot approve it — another holder of payroll.approve does");
            }
            if ($changed = $this->builder->differences($r)) {
                throw AppError::rule('PAYRUN_STALE', 'تغيّرت مدخلات المسير بعد إرساله ('.implode('، ', array_slice($changed, 0, 8)).') — أعِده للتصحيح وأعِد حسابه قبل الاعتماد',
                    'The inputs of the run changed after it was submitted ('.implode(', ', array_slice($changed, 0, 8)).') — return it, compute it again, then approve', ['payslips' => $changed]);
            }
            $this->move($user, $r, 'approved', Shape::text($note, 500), ['approved_by' => $user->username, 'approved_by_id' => $user->id, 'approved_at' => now(), 'approval_note' => Shape::text($note, 500), 'books' => null]);
            // what the payslips carry is now spent: adjustments are applied, sheets belong to their payslip
            foreach (Payslip::where('run_id', $r->id)->get() as $slip) {
                $adjustments = PayslipLine::where('payslip_id', $slip->id)->whereNotNull('adjustment_id')->pluck('adjustment_id')->all();
                if ($adjustments) {
                    Adjustment::whereIn('id', $adjustments)->update(['state' => 'applied', 'payslip_id' => $slip->id]);
                }
                if ($sheetIds = (array) ($slip->explain['sheetIds'] ?? [])) {
                    Sheet::whereIn('id', $sheetIds)->update(['payslip_id' => $slip->id]);
                }
                Hr::tellUser(Employee::whereKey($slip->employee_id)->value('user_id'), "قسيمة راتبك عن {$r->period} متاحة ({$slip->number})", "Your payslip for {$r->period} is available ({$slip->number})", 'Payslip', $slip->id, $slip->number);
            }
            $this->audit->log($user, ['action' => 'PAYRUN.APPROVE', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => $this->figures($r)]);

            return $this->get($user, $r->id);
        });
    }

    // ───────────────────────────── the books ─────────────────────────────

    /** approved → posted: one balanced journal through the finance ledger. @param array{date?:?string} $dto */
    public function post(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['approved'], 'posted');
            $date = Hr::date($dto['date'] ?? null) ?? Shape::day($r->date_to);
            $this->assertClaimsDue($r);
            $journal = $this->posting->post($user, $r, $date);
            $this->move($user, $r, 'posted', "قيد {$journal->number}", ['books' => 'ops', 'posted_by' => $user->username, 'posted_at' => now(), 'accounting_date' => $date, 'journal_id' => $journal->id, 'journal_number' => $journal->number]);
            $this->audit->log($user, ['action' => 'PAYRUN.POST', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => ['journal' => $journal->number, 'date' => $date, 'debit' => (string) $journal->total_debit]]);

            return $this->get($user, $r->id);
        });
    }

    /**
     * posted → paid: the net pay left the bank account or the cash box; the reimbursed claims are marked paid in
     * People & expenses with the run as their payment reference.
     *
     * @param  array{bankAccount:string, reference:string, date?:?string}  $dto
     */
    public function pay(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['posted'], 'paid');
            $reference = Shape::text($dto['reference'] ?? null, 80) ?? throw AppError::validation('PAYMENT_REF_REQUIRED', 'مرجع الصرف (رقم ملف التحويل أو الحوالة) مطلوب', 'The payment reference is required');
            $date = Hr::date($dto['date'] ?? null) ?? Carbon::today()->toDateString();
            if ($date > Carbon::today()->toDateString()) {
                throw AppError::validation('DATE_FUTURE', 'تاريخ الصرف لا يكون في المستقبل', 'The payment date cannot be in the future');
            }
            $bank = BankAccount::where('code', (string) ($dto['bankAccount'] ?? ''))->orWhere('id', (string) ($dto['bankAccount'] ?? ''))->first() ?? throw AppError::notFound('BANK_ACCOUNT_NOT_FOUND', 'الحساب البنكي أو الصندوق غير موجود', 'Bank account not found');
            if (! $bank->active) {
                throw AppError::rule('BANK_ACCOUNT_INACTIVE', "الحساب {$bank->code} غير نشط", "Bank account {$bank->code} is inactive");
            }
            $this->assertClaimsDue($r);
            $journal = Money::h($r->net) > 0 ? $this->posting->pay($user, $r, $bank, $date, $reference) : null;
            foreach ($this->claimIds($r) as $claimId) {
                $this->claims->pay($user, $claimId, ['reference' => $r->number, 'date' => $date, 'bankAccount' => $bank->code]);
            }
            $this->move($user, $r, 'paid', "مرجع {$reference}".($journal ? " · قيد {$journal->number}" : ''), ['paid_by' => $user->username, 'paid_at' => now(), 'payment_date' => $date, 'payment_ref' => $reference,
                'payment_account' => $bank->code, 'payment_journal_id' => $journal?->id, 'payment_journal_number' => $journal?->number]);
            $this->audit->log($user, ['action' => 'PAYRUN.PAY', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => ['journal' => $journal?->number, 'account' => $bank->code, 'reference' => $reference, 'net' => (string) $r->net]]);

            return $this->get($user, $r->id);
        });
    }

    /** A payment recorded by mistake is taken back: its journal is reversed, the claims wait for payment again. */
    public function unpay(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = Shape::reason($reason, 'التراجع عن الصرف', 'taking the payment back');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $r = $this->locked($idOrNumber);
            $this->assertFrom($r, ['paid'], 'posted');
            if ($r->payment_journal_id) {
                $this->ledger->reverse($user, $r->payment_journal_id, "التراجع عن صرف {$r->number}: {$reason}");
            }
            foreach (Claim::whereIn('id', $this->claimIds($r) ?: ['-'])->where('state', 'paid')->where('payment_ref', $r->number)->pluck('id') as $claimId) {
                $this->claims->unpay($user, $claimId, "التراجع عن صرف المسير {$r->number}: {$reason}");
            }
            $this->move($user, $r, 'posted', "تراجع عن الصرف: {$reason}", ['paid_by' => null, 'paid_at' => null, 'payment_date' => null, 'payment_ref' => null, 'payment_account' => null, 'payment_journal_id' => null, 'payment_journal_number' => null]);

            return $this->get($user, $r->id);
        });
    }

    /**
     * approved | posted → reversed: the run never happened as far as the month is concerned. A posted run's journal is
     * reversed by the ledger (that needs `gl.approve`); a PAID run is first un-paid.
     */
    public function reverse(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = Shape::reason($reason, 'عكس المسير', 'reversing the run');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $r = $this->locked($idOrNumber);
            if ($r->state === 'paid') {
                throw AppError::rule('PAYRUN_PAID', "صُرف المسير {$r->number} (مرجع {$r->payment_ref}) — تراجع عن الصرف أولًا ثم اعكسه", "Run {$r->number} was paid (reference {$r->payment_ref}) — take the payment back first");
            }
            $this->assertFrom($r, ['approved', 'posted'], 'reversed');
            $reversal = null;
            if ($r->state === 'posted' && $r->journal_id) {
                if (! $user->can('gl.approve')) {
                    throw AppError::forbidden('FORBIDDEN', 'عكس مسير مرحَّل يعكس قيده في الدفاتر — يحتاج صلاحية gl.approve', 'Reversing a posted run reverses its journal — it needs gl.approve');
                }
                $reversal = $this->ledger->reverse($user, $r->journal_id, "عكس مسير الرواتب {$r->number}: {$reason}");
            }
            $slipIds = Payslip::where('run_id', $r->id)->pluck('id')->all();
            Adjustment::whereIn('payslip_id', $slipIds ?: ['-'])->where('state', 'applied')->update(['state' => 'pending', 'payslip_id' => null]);
            Sheet::whereIn('payslip_id', $slipIds ?: ['-'])->update(['payslip_id' => null]);
            $this->release($r);
            $this->move($user, $r, 'reversed', $reason.($reversal ? " · قيد عكسي {$reversal->number}" : ''), ['closed_by' => $user->username, 'closed_at' => now(), 'close_reason' => $reason, 'reversal_journal_number' => $reversal?->number]);
            $this->audit->log($user, ['action' => 'PAYRUN.REVERSE', 'entityType' => 'PayrollRun', 'entityId' => $r->id, 'entityNumber' => $r->number, 'newValue' => ['reason' => $reason, 'reversal' => $reversal?->number]]);

            return $this->get($user, $r->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** The payslips of a run that ends without having stood let go of their contracts' month. */
    private function release(Run $r): void
    {
        Payslip::where('run_id', $r->id)->update(['live_key' => null]);
    }

    /** @return string[] */
    private function claimIds(Run $r): array
    {
        return PayslipLine::whereIn('payslip_id', Payslip::where('run_id', $r->id)->select('id'))->whereNotNull('claim_id')->pluck('claim_id')->unique()->values()->all();
    }

    /** Every claim the run reimburses must still be owed, for the amount the payslip carries. */
    private function assertClaimsDue(Run $r): void
    {
        $lines = PayslipLine::whereIn('payslip_id', Payslip::where('run_id', $r->id)->select('id'))->whereNotNull('claim_id')->get(['claim_id', 'total']);
        if ($lines->isEmpty()) {
            return;
        }
        $claims = Claim::whereIn('id', $lines->pluck('claim_id')->all())->get(['id', 'number', 'state', 'posted_in', 'employee_due'])->keyBy('id');
        $changed = [];
        foreach ($lines as $l) {
            $c = $claims[$l->claim_id] ?? null;
            if (! $c || $c->state !== 'posted' || $c->posted_in !== 'ops' || Money::h($c->employee_due) !== Money::h($l->total)) {
                $changed[] = $c?->number ?? (string) $l->claim_id;
            }
        }
        if ($changed) {
            throw AppError::rule('PAYRUN_CLAIM_CHANGED', 'مطالبات مصروفات في هذا المسير لم تعد مستحقة بالمبلغ نفسه ('.implode('، ', $changed).') — سُدِّدت أو عُكس ترحيلها خارج المسير. اعكس المسير وأعِد إنشاءه',
                'Expense claims of this run are no longer owed for the same amount ('.implode(', ', $changed).') — they were paid or un-posted outside the run. Reverse the run and make it again', ['claims' => $changed]);
        }
    }

    private function selfApproval(AuthUser $user, Run $r): bool
    {
        return $r->submitted_by_id === $user->id && ! (bool) $this->settings->get('procurement.allowSelfApproval');
    }

    private function figures(Run $r): array
    {
        return ['period' => $r->period, 'payslips' => $r->slip_count, 'gross' => (string) $r->gross, 'net' => (string) $r->net, 'skipped' => count($r->skipped ?? [])];
    }

    private function find(string $idOrNumber): Run
    {
        return Run::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('PAYRUN_NOT_FOUND', 'مسير الرواتب غير موجود', 'Payroll run not found');
    }

    /** Under a row lock; a run that came from Odoo is history and is never moved here. Must run inside a transaction. */
    private function locked(string $idOrNumber): Run
    {
        $r = Run::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('PAYRUN_NOT_FOUND', 'مسير الرواتب غير موجود', 'Payroll run not found');
        Owned::refuse($r, 'payrun', "مسير الرواتب {$r->number}", "Payroll run {$r->number}");
        if ($r->source_system !== null && ! in_array($r->state, ['draft', 'review'], true)) {
            throw AppError::rule('PAYRUN_HISTORY', "المسير {$r->number} تاريخ منقول من {$r->source_system} — دفاتره هناك ولا يُحرَّك هنا", "Run {$r->number} is history brought from {$r->source_system} — its books are there and it is not moved here");
        }

        return $r;
    }

    /** @param string[] $states */
    private function assertState(Run $r, array $states, string $ar, string $en): void
    {
        if (! in_array($r->state, $states, true)) {
            throw AppError::rule('PAYRUN_STATE', "{$ar} (حالة {$r->number}: ".self::stateAr($r->state).')', "{$en} ({$r->number} is {$r->state})", ['state' => $r->state]);
        }
    }

    /** An act starts from its own state(s) only, and the state table must allow where it goes. @param string[] $from */
    private function assertFrom(Run $r, array $from, string $to): void
    {
        if (! in_array($r->state, $from, true) || ! Run::can($r->state, $to)) {
            throw AppError::rule('PAYRUN_TRANSITION', "انتقال غير مسموح للمسير {$r->number}: ".self::stateAr($r->state).' ← '.self::stateAr($to), "Invalid transition for run {$r->number}: {$r->state} -> {$to}", ['from' => $r->state, 'to' => $to]);
        }
    }

    /** One step of the state table: the run and its payslips move together, with a line in the history. */
    private function move(AuthUser $user, Run $r, string $to, ?string $note, array $extra = []): void
    {
        $from = $r->state;
        if (! Run::can($from, $to)) {
            throw AppError::rule('PAYRUN_TRANSITION', "انتقال غير مسموح للمسير {$r->number}: ".self::stateAr($from).' ← '.self::stateAr($to), "Invalid transition for run {$r->number}: {$from} -> {$to}", ['from' => $from, 'to' => $to]);
        }
        $r->update(['state' => $to] + $extra);
        Payslip::where('run_id', $r->id)->update(['state' => $to]);
        $this->audit->status($user, 'PayrollRun', $r->id, $r->number, $from, $to, $note);
    }

    public static function stateAr(?string $state): string
    {
        return ['draft' => 'مسودة', 'review' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'posted' => 'مرحَّل', 'paid' => 'مصروف', 'reversed' => 'معكوس', 'cancelled' => 'ملغى'][$state ?? ''] ?? (string) $state;
    }

    private function row(Run $r, AuthUser $user): array
    {
        $free = ! Owned::locked($r) && ($r->source_system === null || in_array($r->state, ['draft', 'review'], true));
        $manage = $free && $user->can('payroll.manage');
        $approve = $free && $user->can('payroll.approve');
        $books = $manage && $user->can('gl.post');
        $self = $r->state === 'review' && $this->selfApproval($user, $r);

        return [
            'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'period' => $r->period, 'dateFrom' => Shape::day($r->date_from), 'dateTo' => Shape::day($r->date_to),
            'department' => Shape::department($r->department), 'state' => $r->state, 'currency' => $r->currency, 'slipCount' => $r->slip_count, 'gross' => $r->gross, 'deductions' => $r->deductions,
            'reimbursements' => $r->reimbursements, 'net' => $r->net, 'employerCost' => $r->employer_cost, 'skippedCount' => count($r->skipped ?? []), 'warningCount' => array_sum(array_column($r->warnings ?? [], 'count')),
            'computedAt' => Shape::at($r->computed_at), 'submittedBy' => $r->submitted_by, 'submittedAt' => Shape::at($r->submitted_at), 'approvedBy' => $r->approved_by, 'approvedAt' => Shape::at($r->approved_at),
            'postedAt' => Shape::at($r->posted_at), 'journalNumber' => $r->journal_number, 'paidAt' => Shape::at($r->paid_at), 'paymentJournalNumber' => $r->payment_journal_number,
            'closedBy' => $r->closed_by, 'closedAt' => Shape::at($r->closed_at), 'reversalJournalNumber' => $r->reversal_journal_number, 'books' => $r->books,
            'source' => $r->source_system, 'locked' => ! $free, 'createdBy' => $r->created_by, 'createdAt' => Shape::at($r->created_at),
            'can' => [
                'compute' => $manage && $r->state === 'draft', 'submit' => $manage && $r->state === 'draft' && $r->slip_count > 0, 'cancel' => $manage && in_array($r->state, ['draft', 'review'], true),
                'sendBack' => ($approve || $manage) && $r->state === 'review', 'approve' => $approve && $r->state === 'review' && ! $self, 'selfApproval' => $approve && $self,
                'post' => $books && $r->state === 'approved', 'pay' => $books && $r->state === 'posted', 'unpay' => $books && $r->state === 'paid',
                'reverse' => $approve && ($r->state === 'approved' || ($r->state === 'posted' && $user->can('gl.approve'))),
                'transfer' => $user->can('payroll.manage') && in_array($r->state, Run::FINAL, true),
            ],
        ];
    }
}
