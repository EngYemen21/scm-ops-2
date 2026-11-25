<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Hr\Support\Hr;
use App\Models\StatusHistory;
use App\Payroll\Models\Leave;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Run;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Time off.
 *
 *   draft ──submit──▶ submitted ──approve──▶ (manager_approved ──approve──▶) approved ──cancel──▶ cancelled
 *     ▲  ◀─withdraw──┘     └──refuse──▶ refused
 *     └──cancel──▶ cancelled
 *
 * WHO. An employee (`leave.request`) asks for their own leave, withdraws it while it waits and cancels a draft; a
 * holder of `leave.approve` (or payroll.manage) may enter one for somebody else. WHO DECIDES depends on the type:
 * `none` — nobody, it is approved as it is sent; `manager` — the direct manager or a holder of leave.approve; `hr` — a
 * holder of leave.approve; `both` — the manager first (or a holder of leave.approve), then ANOTHER person who holds
 * leave.approve. Nobody decides their own leave.
 *
 * DAYS are the working days of the person's own schedule inside the dates, public holidays out. A type that needs
 * days granted is refused beyond the balance (what is already waiting for a decision counts against it). The final
 * approval writes the days into the ledger; cancelling an approved leave gives them back. An unpaid leave that a
 * standing approved payroll run already deducted cannot be cancelled — the correction is an adjustment in the next run.
 *
 * Every move locks the row, refuses what the state table does not allow (`LEAVE_TRANSITION`), and is written to the
 * status history and the audit trail — without the reason, which may be a person's medical matter.
 */
class LeaveService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly WorkCalendar $calendar,
        private readonly LeaveLedger $ledger,
        private readonly Workforce $workforce,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{scope?:?string, state?:?string, type?:?string, employee?:?string, department?:?string, from?:?string, to?:?string, source?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = Leave::query();
        $this->scope($q, $user, (string) ($f['scope'] ?? 'mine'));
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Leave::STATES) ?: ['-']);
        }
        if (! empty($f['type'])) {
            $q->where('leave_type_id', $f['type']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        if (! empty($f['from'])) {
            $q->whereDate('date_to', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('date_from', '<=', $f['to']);
        }
        if (! empty($f['source'])) {
            $f['source'] === Owned::ODOO ? $q->where('source_system', Owned::ODOO) : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')
                ->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $totals = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(days), 0) as days')->first();
        $column = ['from' => 'date_from', 'days' => 'days', 'number' => 'number', 'state' => 'state', 'created' => 'created_at'][$page->sort ?? 'from'] ?? 'date_from';
        $rows = $q->with(['employee', 'department', 'type'])->orderBy($column, $page->order)->orderBy('id', $page->order)->forPage($page->page, $page->pageSize)->get();

        return $page->wrap($rows->map(fn (Leave $l) => $this->row($l, $user))->all(), (int) $totals->n) + ['totals' => ['days' => round((float) $totals->days, 2)]];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $l = $this->find($idOrNumber)->load(['employee', 'department', 'type']);
        $this->assertSees($user, $l);

        return $this->row($l, $user) + [
            'reason' => $l->reason, 'decisionNote' => $l->decision_note, 'sourceState' => $l->source_state, 'externalRef' => $l->external_ref,
            'balance' => $l->type?->requires_allocation ? ['balance' => $this->ledger->balance($l->employee_id, $l->leave_type_id, $l->year), 'pending' => $this->ledger->pending($l->employee_id, $l->leave_type_id, $l->year, $l->id)] : null,
            'history' => StatusHistory::where('entity_type', 'Leave')->where('entity_id', $l->id)->orderBy('at')->orderBy('id')->get()
                ->map(fn (StatusHistory $h) => ['at' => Shape::at($h->at), 'from' => $h->from_status, 'to' => $h->to_status, 'by' => $h->username, 'note' => $h->note])->all(),
        ];
    }

    /**
     * Who is off, day by day, between two dates — among the people the caller may see. A leave that still waits for
     * a decision is marked as such.
     *
     * @return array{from:string, to:string, items: array<int, array<string, mixed>>}
     */
    public function calendar(AuthUser $user, string $from, string $to, ?string $departmentId = null): array
    {
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 92) {
            throw AppError::validation('RANGE_TOO_LONG', 'التقويم يعرض ثلاثة أشهر على الأكثر', 'The calendar shows at most three months');
        }
        $q = Leave::query()->whereIn('state', ['submitted', 'manager_approved', 'approved'])->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from);
        $this->scope($q, $user, Access::seesAllLeave($user) ? 'all' : 'visible');
        if ($departmentId) {
            $q->where('department_id', $departmentId);
        }

        return ['from' => $from, 'to' => $to, 'items' => $q->with(['employee', 'type'])->orderBy('date_from')->orderBy('id')->limit(1000)->get()->map(fn (Leave $l) => [
            'id' => $l->id, 'number' => $l->number, 'employee' => Shape::employee($l->employee), 'type' => Shape::named($l->type, ['color' => 'color', 'paid' => 'paid']),
            'dateFrom' => Shape::day($l->date_from), 'dateTo' => Shape::day($l->date_to), 'halfDay' => $l->half_day, 'days' => (float) $l->days, 'state' => $l->state,
        ])->all()];
    }

    /** What the caller sees of the summary cards. */
    public function counts(AuthUser $user): array
    {
        $out = [];
        if ($mine = Access::employeeId($user)) {
            $by = Leave::where('employee_id', $mine)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
            $out['mine'] = ['waiting' => (int) ($by['submitted'] ?? 0) + (int) ($by['manager_approved'] ?? 0), 'approved' => (int) ($by['approved'] ?? 0), 'draft' => (int) ($by['draft'] ?? 0)];
        }
        if (Access::seesAllLeave($user) || Access::team($user)) {
            $q = Leave::query()->whereIn('state', Leave::WAITING)->when(Owned::following(), fn ($x) => $x->whereNull('source_system'));
            if (! Access::seesAllLeave($user)) {
                $q->whereIn('employee_id', Access::team($user));
            }
            $today = Carbon::today()->toDateString();
            $off = Leave::query()->where('state', 'approved')->whereDate('date_from', '<=', $today)->whereDate('date_to', '>=', $today);
            if (! Access::seesAllLeave($user)) {
                $off->whereIn('employee_id', Access::team($user));
            }
            $out['decide'] = ['waiting' => $q->count(), 'offToday' => $off->count()];
        }

        return $out;
    }

    // ───────────────────────────── asking ─────────────────────────────

    /** @param  array{employee?:?string, type:string, dateFrom:string, dateTo?:?string, halfDay?:bool, reason?:?string, submit?:bool}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $employee = $this->whose($user, $dto['employee'] ?? null);
            $type = LeaveLedger::type((string) $dto['type']);
            if (! $type->active) {
                throw AppError::rule('LEAVE_TYPE_INACTIVE', "نوع الإجازة «{$type->name_ar}» موقوف", "Leave type '{$type->name_en}' is inactive");
            }
            $values = $this->figures($employee, $type, Hr::date($dto['dateFrom']), Hr::date($dto['dateTo'] ?? null) ?? Hr::date($dto['dateFrom']), (bool) ($dto['halfDay'] ?? false), null);
            $l = Leave::create($values + ['number' => $this->numbering->next('LVE'), 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'department_id' => $employee->department_id,
                'reason' => Shape::text($dto['reason'] ?? null, 2000), 'state' => 'draft', 'requested_by_id' => $user->id, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'LEAVE.CREATE', 'entityType' => 'Leave', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => ['type' => $type->code, 'days' => (float) $l->days]]);
            $this->audit->status($user, 'Leave', $l->id, $l->number, null, 'draft');
            if (! empty($dto['submit'])) {
                $this->send($user, $l, $type);
            }

            return $this->get($user, $l->id);
        });
    }

    /** @param  array{type?:?string, dateFrom?:?string, dateTo?:?string, halfDay?:bool, reason?:?string}  $dto */
    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $l = $this->own($user, $idOrNumber);
            $this->assertState($l, ['draft'], 'يُعدَّل الطلب وهو مسودة فقط — اسحبه أولًا إن كان بانتظار القرار', 'A request is edited only as a draft — withdraw it first if it waits for a decision');
            $type = ! empty($dto['type']) ? LeaveLedger::type((string) $dto['type']) : LeaveType::findOrFail($l->leave_type_id);
            $employee = Employee::findOrFail($l->employee_id);
            $from = array_key_exists('dateFrom', $dto) && $dto['dateFrom'] ? Hr::date($dto['dateFrom']) : Shape::day($l->date_from);
            $to = array_key_exists('dateTo', $dto) && $dto['dateTo'] ? Hr::date($dto['dateTo']) : Shape::day($l->date_to);
            $half = array_key_exists('halfDay', $dto) ? (bool) $dto['halfDay'] : $l->half_day;
            $l->update($this->figures($employee, $type, $from, $to, $half, $l->id) + ['leave_type_id' => $type->id]
                + (array_key_exists('reason', $dto) ? ['reason' => Shape::text($dto['reason'], 2000)] : []));
            $this->audit->log($user, ['action' => 'LEAVE.UPDATE', 'entityType' => 'Leave', 'entityId' => $l->id, 'entityNumber' => $l->number, 'newValue' => ['type' => $type->code, 'days' => (float) $l->days]]);

            return $this->get($user, $l->id);
        });
    }

    public function submit(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $l = $this->own($user, $idOrNumber);
            $this->assertState($l, ['draft'], 'يُرسَل الطلب وهو مسودة', 'Only a draft is sent');
            $this->send($user, $l, LeaveType::findOrFail($l->leave_type_id));

            return $this->get($user, $l->id);
        });
    }

    /** The person takes a request back before it is decided. */
    public function withdraw(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $l = $this->own($user, $idOrNumber);
            $this->assertState($l, ['submitted'], 'يُسحب الطلب وهو بانتظار القرار الأول فقط', 'A request is withdrawn only while it waits for its first decision');
            $this->move($user, $l, 'draft', 'سحبه صاحبه', ['submitted_at' => null]);

            return $this->get($user, $l->id);
        });
    }

    // ───────────────────────────── deciding ─────────────────────────────

    public function approve(AuthUser $user, string $idOrNumber, ?string $note): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $note) {
            $l = $this->locked($idOrNumber);
            $this->assertState($l, Leave::WAITING, 'القرار يكون على طلب بانتظار الاعتماد', 'A decision is taken on a request that waits for one');
            $type = LeaveType::findOrFail($l->leave_type_id);
            $step = $this->step($user, $l, $type);
            if ($step === null) {
                throw $this->notYours($user, $l, $type);
            }
            $note = Shape::text($note, 500);
            if ($step === 'first') {
                $this->move($user, $l, 'manager_approved', $note, ['first_approved_by' => $user->username, 'first_approved_by_id' => $user->id, 'first_approved_at' => now()]);
                Hr::tellRoles('leave.approve', "طلب إجازة {$l->number} اعتمده المدير المباشر وينتظر اعتماد الموارد البشرية", "Leave request {$l->number} was approved by the manager and waits for HR", 'Leave', $l->id, $l->number);
            } else {
                $this->assertBalance($l, $type);
                $this->move($user, $l, 'approved', $note, ['decided_by' => $user->username, 'decided_by_id' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
                $this->ledger->take($l, $user->username);
                $this->tellEmployee($l, "اعتُمدت إجازتك {$l->number}", "Your leave {$l->number} was approved");
            }

            return $this->get($user, $l->id);
        });
    }

    public function refuse(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = Shape::reason($reason, 'الرفض', 'the refusal');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $l = $this->locked($idOrNumber);
            $this->assertState($l, Leave::WAITING, 'القرار يكون على طلب بانتظار الاعتماد', 'A decision is taken on a request that waits for one');
            $type = LeaveType::findOrFail($l->leave_type_id);
            if ($this->step($user, $l, $type) === null) {
                throw $this->notYours($user, $l, $type);
            }
            $this->move($user, $l, 'refused', null, ['decided_by' => $user->username, 'decided_by_id' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $this->tellEmployee($l, "رُفض طلب إجازتك {$l->number}", "Your leave request {$l->number} was refused");

            return $this->get($user, $l->id);
        });
    }

    /**
     * A draft is cancelled by its owner; a request that waits, by its owner or a decider; an APPROVED leave by a
     * holder of leave.approve with a reason — its days go back into the ledger.
     */
    public function cancel(AuthUser $user, string $idOrNumber, ?string $reason): array
    {
        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $l = $this->locked($idOrNumber);
            if (! Leave::can($l->state, 'cancelled')) {
                throw $this->transition($l, 'cancelled');
            }
            $mine = $this->isOwn($user, $l);
            if ($l->state === 'approved') {
                if (! $user->can('leave.approve')) {
                    throw AppError::forbidden('FORBIDDEN', 'إلغاء إجازة معتمدة يحتاج صلاحية اعتماد الإجازات', 'Cancelling an approved leave needs leave.approve');
                }
                $reason = Shape::reason($reason, 'إلغاء الإجازة المعتمدة', 'cancelling the approved leave');
                $this->assertNotPaid($l);
            } elseif (! $mine && ! $user->can('leave.approve')) {
                throw AppError::forbidden('LEAVE_NOT_YOURS', 'هذا الطلب ليس لك', 'This request is not yours');
            }
            $wasApproved = $l->state === 'approved';
            $this->move($user, $l, 'cancelled', null, ['decision_note' => Shape::text($reason, 500) ?? $l->decision_note]);
            if ($wasApproved) {
                $this->ledger->restore($l, $user->username);
                $this->tellEmployee($l, "أُلغيت إجازتك المعتمدة {$l->number} وأُعيدت أيامها إلى رصيدك", "Your approved leave {$l->number} was cancelled and its days returned to your balance");
            }

            return $this->get($user, $l->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** draft → submitted, or straight to approved for a type nobody has to approve. */
    private function send(AuthUser $user, Leave $l, LeaveType $type): void
    {
        if ((float) $l->days <= 0) {
            throw AppError::rule('LEAVE_NO_WORKING_DAYS', 'لا أيام عمل في المدة المطلوبة (عطلة أسبوعية أو رسمية)', 'There is no working day in the requested dates (weekend or public holiday)');
        }
        $this->assertBalance($l, $type);
        if ($type->approval === 'none') {
            $this->move($user, $l, 'approved', 'نوع لا يحتاج اعتمادًا', ['submitted_at' => now(), 'decided_at' => now(), 'decided_by' => 'system']);
            $this->ledger->take($l, $user->username);

            return;
        }
        $this->move($user, $l, 'submitted', null, ['submitted_at' => now(), 'first_approved_by' => null, 'first_approved_by_id' => null, 'first_approved_at' => null,
            'decided_by' => null, 'decided_by_id' => null, 'decided_at' => null, 'decision_note' => null]);
        $manager = $this->managerLogin($l->employee_id);
        if ($manager && in_array($type->approval, ['manager', 'both'], true)) {
            Hr::tellUser($manager, "طلب إجازة {$l->number} من أحد موظفيك بانتظار قرارك", "Leave request {$l->number} of one of your reports waits for your decision", 'Leave', $l->id, $l->number);
        } else {
            Hr::tellRoles('leave.approve', "طلب إجازة {$l->number} بانتظار الاعتماد", "Leave request {$l->number} waits for approval", 'Leave', $l->id, $l->number);
        }
    }

    /**
     * Which step of the approval the caller may take now: `first` (the manager's), `final`, or null (none).
     */
    private function step(AuthUser $user, Leave $l, LeaveType $type): ?string
    {
        if (Owned::locked($l) || $this->isOwn($user, $l)) {
            return null;
        }
        $hr = $user->can('leave.approve');
        $manager = Access::isManagerOf($user, $l->employee_id);
        if ($l->state === 'submitted') {
            return match ($type->approval) {
                'both' => $manager || $hr ? 'first' : null,
                'hr' => $hr ? 'final' : null,
                default => $manager || $hr ? 'final' : null, // manager — and a type that was `none` when the request is older than that setting
            };
        }
        if ($l->state === 'manager_approved') {
            return $hr && $l->first_approved_by_id !== $user->id ? 'final' : null;
        }

        return null;
    }

    private function notYours(AuthUser $user, Leave $l, LeaveType $type): AppError
    {
        if ($this->isOwn($user, $l)) {
            return AppError::rule('LEAVE_SELF_APPROVAL', "لا يعتمد الموظف إجازته ({$l->number}) — يقرّرها مديره أو الموارد البشرية", "Nobody decides their own leave ({$l->number})");
        }
        if ($l->state === 'manager_approved' && $l->first_approved_by_id === $user->id) {
            return AppError::rule('LEAVE_SECOND_APPROVER', "اعتمدتَ الخطوة الأولى للطلب {$l->number} — الاعتماد النهائي لشخص آخر يحمل صلاحية اعتماد الإجازات", "You took the first step on {$l->number} — the final approval belongs to another holder of leave.approve");
        }

        return AppError::forbidden('LEAVE_NOT_YOURS_TO_DECIDE', "قرار هذا الطلب ({$l->number}) ليس لك: نوعه «{$type->name_ar}» يعتمده ".(['manager' => 'المدير المباشر أو الموارد البشرية', 'hr' => 'الموارد البشرية', 'both' => 'المدير المباشر ثم الموارد البشرية'][$type->approval] ?? 'المخوَّل'),
            "This request ({$l->number}) is not yours to decide");
    }

    /** A type that needs days granted is refused beyond what is left, counting what already waits for a decision. */
    private function assertBalance(Leave $l, LeaveType $type): void
    {
        if (! $type->requires_allocation || $type->allow_negative) {
            return;
        }
        $available = round($this->ledger->balance($l->employee_id, $type->id, $l->year) - $this->ledger->pending($l->employee_id, $type->id, $l->year, $l->id), 2);
        if ((float) $l->days > $available + 0.001) {
            throw AppError::rule('LEAVE_BALANCE', "الرصيد المتاح من «{$type->name_ar}» لسنة {$l->year} هو {$available} يومًا والمطلوب {$l->days}", "Only {$available} day(s) of '{$type->name_en}' are available for {$l->year}; {$l->days} requested",
                ['available' => $available, 'requested' => (float) $l->days, 'year' => $l->year]);
        }
    }

    /** An unpaid leave already deducted by a standing approved run cannot simply vanish. */
    private function assertNotPaid(Leave $l): void
    {
        if (LeaveType::whereKey($l->leave_type_id)->value('paid')) {
            return;
        }
        $slip = Payslip::where('employee_id', $l->employee_id)->whereIn('state', Run::FINAL)->whereDate('date_from', '<=', Shape::day($l->date_to))->whereDate('date_to', '>=', Shape::day($l->date_from))->first();
        if ($slip) {
            throw AppError::rule('LEAVE_IN_PAYROLL', "هذه الإجازة غير المدفوعة خُصمت في قسيمة معتمدة ({$slip->number}) — تُصحَّح بتسوية في المسير التالي لا بإلغاء الإجازة",
                "This unpaid leave was deducted in an approved payslip ({$slip->number}) — correct it with an adjustment in the next run", ['payslip' => $slip->number]);
        }
    }

    /**
     * The dates of a request as figures: validated, counted on the person's schedule, and free of any other standing
     * request of theirs.
     *
     * @return array{date_from:string, date_to:string, half_day:bool, days:float, year:int}
     */
    private function figures(Employee $employee, LeaveType $type, ?string $from, ?string $to, bool $half, ?string $exceptId): array
    {
        if ($from === null || $to === null || $to < $from) {
            throw AppError::validation('LEAVE_DATES', 'تاريخ نهاية الإجازة لا يسبق بدايتها', 'The leave cannot end before it starts');
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            throw AppError::validation('LEAVE_DATES', 'مدة الإجازة لا تتجاوز سنة', 'A leave cannot be longer than a year');
        }
        if ($half && ($from !== $to || ! $type->allow_half_day)) {
            throw AppError::validation('LEAVE_HALF_DAY', $from !== $to ? 'نصف اليوم يكون لطلب من يوم واحد' : 'هذا النوع لا يقبل نصف يوم', $from !== $to ? 'A half day is a request of one single day' : 'This type does not allow half days');
        }
        $clash = Leave::where('employee_id', $employee->id)->whereIn('state', Leave::STANDING)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->first();
        if ($clash) {
            throw AppError::rule('LEAVE_OVERLAP', "للموظف طلب آخر في هذه المدة ({$clash->number})", "The employee already has a request in these dates ({$clash->number})", ['leave' => $clash->number]);
        }

        return ['date_from' => $from, 'date_to' => $to, 'half_day' => $half, 'days' => $this->calendar->leaveDays($employee, $from, $to, $half)['days'], 'year' => LeaveLedger::yearOf($from)];
    }

    /** Whose leave a new request is: the caller's own, or — entered by a decider — a named employee's. */
    private function whose(AuthUser $user, ?string $employee): Employee
    {
        $own = Access::employeeId($user);
        if ($employee === null || $employee === '' || $employee === $own) {
            if (! $user->can('leave.request')) {
                throw AppError::forbidden('FORBIDDEN', 'طلب إجازة لنفسك يحتاج صلاحية leave.request', 'Asking for your own leave needs leave.request');
            }

            return Employee::findOrFail(Access::needEmployee($user));
        }
        $e = $this->workforce->employee($employee) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
        if ($e->id === $own) {
            return $e;
        }
        if (! $user->can('leave.approve') && ! $user->can('payroll.manage')) {
            throw AppError::forbidden('LEAVE_ON_BEHALF', 'إدخال إجازة عن موظف آخر يحتاج صلاحية اعتماد الإجازات', 'Entering a leave for another employee needs leave.approve');
        }

        return $e;
    }

    private function scope($q, AuthUser $user, string $scope): void
    {
        $mine = Access::employeeId($user);
        $team = Access::team($user);
        match ($scope) {
            'all' => Access::seesAllLeave($user) ? null : throw AppError::forbidden('FORBIDDEN', 'إجازات الجميع يراها مسؤولو الإجازات والرواتب', 'Only leave and payroll officers see everybody\'s time off'),
            'team' => $q->whereIn('employee_id', $team ?: ['-']),
            // what waits for the caller: everything for a holder of leave.approve, the team's first step for a manager
            'waiting' => $q->whereIn('state', Leave::WAITING)->when(Owned::following(), fn ($x) => $x->whereNull('source_system'))
                ->when(! $user->can('leave.approve'), fn ($x) => $x->where('state', 'submitted')->whereIn('employee_id', $team ?: ['-'])),
            'visible' => $q->whereIn('employee_id', array_values(array_filter(array_merge([$mine], $team))) ?: ['-']),
            default => $q->where('employee_id', $mine ?? '-'),
        };
    }

    private function isOwn(AuthUser $user, Leave $l): bool
    {
        return Access::employeeId($user) === $l->employee_id;
    }

    private function assertSees(AuthUser $user, Leave $l): void
    {
        if (! (Access::seesAllLeave($user) || $this->isOwn($user, $l) || Access::isManagerOf($user, $l->employee_id) || $l->requested_by_id === $user->id)) {
            throw Access::denied();
        }
    }

    private function find(string $idOrNumber): Leave
    {
        return Leave::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('LEAVE_NOT_FOUND', 'طلب الإجازة غير موجود', 'Leave request not found');
    }

    /** Under a row lock, refused when it is Odoo's right now. Must run inside a transaction. */
    private function locked(string $idOrNumber): Leave
    {
        $l = Leave::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('LEAVE_NOT_FOUND', 'طلب الإجازة غير موجود', 'Leave request not found');
        Owned::refuse($l, 'leave', "طلب الإجازة {$l->number}", "Leave request {$l->number}");

        return $l;
    }

    /** …and it must be the caller's own, or one they entered, or the caller decides leave. */
    private function own(AuthUser $user, string $idOrNumber): Leave
    {
        $l = $this->locked($idOrNumber);
        if (! ($this->isOwn($user, $l) || $l->requested_by_id === $user->id || $user->can('leave.approve'))) {
            throw AppError::forbidden('LEAVE_NOT_YOURS', 'هذا الطلب ليس لك — يعدّله صاحبه أو من أدخله', 'This request is not yours — its owner or whoever entered it changes it');
        }

        return $l;
    }

    /** @param string[] $states */
    private function assertState(Leave $l, array $states, string $ar, string $en): void
    {
        if (! in_array($l->state, $states, true)) {
            throw AppError::rule('LEAVE_STATE', "{$ar} (حالة {$l->number}: ".self::stateAr($l->state).')', "{$en} ({$l->number} is {$l->state})", ['state' => $l->state]);
        }
    }

    private function transition(Leave $l, string $to): AppError
    {
        return AppError::rule('LEAVE_TRANSITION', "انتقال غير مسموح للطلب {$l->number}: ".self::stateAr($l->state).' ← '.self::stateAr($to), "Invalid transition for {$l->number}: {$l->state} -> {$to}", ['from' => $l->state, 'to' => $to]);
    }

    /** One step of the state table, with its line in the history. The note is what the trail keeps — never the reason of the leave. */
    private function move(AuthUser $user, Leave $l, string $to, ?string $note, array $extra = []): void
    {
        $from = $l->state;
        if (! Leave::can($from, $to)) {
            throw $this->transition($l, $to);
        }
        $l->update(['state' => $to] + $extra);
        $this->audit->status($user, 'Leave', $l->id, $l->number, $from, $to, $note);
    }

    public static function stateAr(?string $state): string
    {
        return ['draft' => 'مسودة', 'submitted' => 'بانتظار الاعتماد', 'manager_approved' => 'اعتمده المدير', 'approved' => 'معتمدة', 'refused' => 'مرفوضة', 'cancelled' => 'ملغاة'][$state ?? ''] ?? (string) $state;
    }

    private function managerLogin(string $employeeId): ?string
    {
        $managerId = Employee::whereKey($employeeId)->value('manager_id');

        return $managerId ? Employee::whereKey($managerId)->value('user_id') : null;
    }

    private function tellEmployee(Leave $l, string $ar, string $en): void
    {
        Hr::tellUser(Employee::whereKey($l->employee_id)->value('user_id'), $ar, $en, 'Leave', $l->id, $l->number);
    }

    private function row(Leave $l, AuthUser $user): array
    {
        $type = $l->type;
        $own = $this->isOwn($user, $l);
        $free = ! Owned::locked($l);
        $step = $type && in_array($l->state, Leave::WAITING, true) ? $this->step($user, $l, $type) : null;
        $editor = $free && ($own || $l->requested_by_id === $user->id || $user->can('leave.approve'));

        return [
            'id' => $l->id, 'number' => $l->number, 'employee' => Shape::employee($l->employee), 'department' => Shape::department($l->department),
            'type' => Shape::named($type, ['paid' => 'paid', 'color' => 'color', 'approval' => 'approval']),
            'dateFrom' => Shape::day($l->date_from), 'dateTo' => Shape::day($l->date_to), 'halfDay' => $l->half_day, 'days' => (float) $l->days, 'year' => $l->year, 'state' => $l->state,
            'submittedAt' => Shape::at($l->submitted_at), 'firstApprovedBy' => $l->first_approved_by, 'firstApprovedAt' => Shape::at($l->first_approved_at),
            'decidedBy' => $l->decided_by, 'decidedAt' => Shape::at($l->decided_at), 'createdBy' => $l->created_by, 'createdAt' => Shape::at($l->created_at),
            'mine' => $own, 'source' => $l->source_system, 'locked' => ! $free,
            'can' => [
                'edit' => $editor && $l->state === 'draft', 'submit' => $editor && $l->state === 'draft', 'withdraw' => $editor && $l->state === 'submitted',
                'approve' => $step !== null, 'refuse' => $step !== null, 'step' => $step,
                'cancel' => $free && (($l->state === 'approved' && $user->can('leave.approve')) || (in_array($l->state, ['draft', 'submitted', 'manager_approved'], true) && ($own || $user->can('leave.approve')))),
            ],
        ];
    }
}
