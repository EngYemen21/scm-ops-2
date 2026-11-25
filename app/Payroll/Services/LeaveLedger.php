<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\Leave;
use App\Payroll\Models\LeaveEntry;
use App\Payroll\Models\LeaveType;
use App\Payroll\Support\Access;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ledger of leave days. A balance is never a stored number: it is the sum of the lines of one employee, one type
 * and one allowance year —
 *
 *   allocation   + days granted (by hand, or the yearly allowance of the type — once per year, whatever is retried)
 *   taken        − the days of a leave at the moment it is finally approved
 *   restored     + the same days when that approved leave is cancelled
 *   adjustment   ± a correction, with its reason
 *
 * Lines are only added. `reconcile()` proves the ledger against the leaves themselves: every approved leave has taken
 * exactly its days, and no other leave has taken anything.
 */
class LeaveLedger
{
    public function __construct(private readonly AuditService $audit, private readonly Workforce $workforce) {}

    public function balance(string $employeeId, string $typeId, int $year): float
    {
        return round((float) LeaveEntry::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('year', $year)->sum('days'), 2);
    }

    /** Days of the same type and year that are asked for and not decided yet (they are not in the ledger). */
    public function pending(string $employeeId, string $typeId, int $year, ?string $exceptLeaveId = null): float
    {
        return round((float) Leave::where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('year', $year)->whereIn('state', Leave::WAITING)
            ->when($exceptLeaveId, fn ($q) => $q->where('id', '!=', $exceptLeaveId))->sum('days'), 2);
    }

    /**
     * The balances of one employee for a year, a row per active type (and per type the ledger knows).
     *
     * @return array<int, array<string, mixed>>
     */
    public function balances(string $employeeId, int $year): array
    {
        $sums = LeaveEntry::where('employee_id', $employeeId)->where('year', $year)->selectRaw('leave_type_id, kind, coalesce(sum(days), 0) as days')->groupBy('leave_type_id', 'kind')->get()->groupBy('leave_type_id');
        $pending = Leave::where('employee_id', $employeeId)->where('year', $year)->whereIn('state', Leave::WAITING)->selectRaw('leave_type_id, coalesce(sum(days), 0) as days')->groupBy('leave_type_id')->pluck('days', 'leave_type_id');
        $types = LeaveType::where(fn ($w) => $w->where('active', true)->orWhereIn('id', $sums->keys()->all() ?: ['-']))->orderBy('sequence')->orderBy('name_ar')->get();

        return $types->map(function (LeaveType $t) use ($sums, $pending) {
            $by = collect($sums[$t->id] ?? [])->pluck('days', 'kind');
            $num = fn (string $kind) => round((float) ($by[$kind] ?? 0), 2);
            $balance = round($num('allocation') + $num('taken') + $num('restored') + $num('adjustment'), 2);

            return ['type' => Shape::named($t, ['paid' => 'paid', 'requiresAllocation' => 'requires_allocation', 'color' => 'color']),
                'allocated' => $num('allocation'), 'taken' => round(-($num('taken') + $num('restored')), 2), 'adjusted' => $num('adjustment'),
                'balance' => $balance, 'pending' => round((float) ($pending[$t->id] ?? 0), 2), 'available' => round($balance - (float) ($pending[$t->id] ?? 0), 2)];
        })->values()->all();
    }

    /**
     * The balances a person asks to see: their own by default; somebody else's for a leave or payroll officer, and
     * for that person's direct manager.
     */
    public function balancesFor(AuthUser $user, ?string $employee, int $year): array
    {
        $e = $employee === null || $employee === '' ? Employee::find(Access::needEmployee($user)) : $this->workforce->employee($employee);
        $e ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
        if (! (Access::seesAllLeave($user) || Access::employeeId($user) === $e->id || Access::isManagerOf($user, $e->id))) {
            throw Access::denied();
        }

        return ['employee' => Shape::employee($e), 'year' => $year, 'balances' => $this->balances($e->id, $year)];
    }

    /** @param  array{employee?:?string, type?:?string, year?:?string, kind?:?string}  $f */
    public function entries(AuthUser $user, Paging $page, array $f): array
    {
        $q = LeaveEntry::query();
        if (! Access::seesAllLeave($user)) {
            $q->whereIn('employee_id', array_values(array_filter(array_merge([Access::employeeId($user)], Access::team($user)))) ?: ['-']);
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['type'])) {
            $q->where('leave_type_id', $f['type']);
        }
        if (! empty($f['year'])) {
            $q->where('year', (int) $f['year']);
        }
        if (! empty($f['kind'])) {
            $q->whereIn('kind', array_intersect(explode(',', (string) $f['kind']), LeaveEntry::KINDS) ?: ['-']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('note', 'like', $like)->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')
                ->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $total = (clone $q)->toBase()->getCountForPagination();
        $sum = round((float) (clone $q)->sum('days'), 2);
        $column = ['days' => 'days', 'year' => 'year'][$page->sort ?? ''] ?? 'created_at';
        $rows = $q->orderBy($column, $page->order)->orderBy('id', $page->order)->forPage($page->page, $page->pageSize)->get();
        $employees = Employee::whereIn('id', $rows->pluck('employee_id')->unique()->all())->get()->keyBy('id');
        $types = LeaveType::whereIn('id', $rows->pluck('leave_type_id')->unique()->all())->get()->keyBy('id');
        $leaves = Leave::whereIn('id', $rows->pluck('leave_id')->filter()->unique()->all())->pluck('number', 'id');

        return $page->wrap($rows->map(fn (LeaveEntry $e) => ['id' => $e->id, 'employee' => Shape::employee($employees[$e->employee_id] ?? null), 'type' => Shape::named($types[$e->leave_type_id] ?? null),
            'year' => $e->year, 'kind' => $e->kind, 'days' => (float) $e->days, 'leave' => $e->leave_id ? ['id' => $e->leave_id, 'number' => $leaves[$e->leave_id] ?? null] : null,
            'note' => $e->note, 'createdBy' => $e->created_by, 'createdAt' => Shape::at($e->created_at), 'source' => $e->source_system])->all(), $total) + ['totals' => ['days' => $sum]];
    }

    /**
     * Days granted or corrected by hand.
     *
     * @param  array{employee:string, type:string, year:int|string, days:float|int|string, kind?:?string, note?:?string}  $dto
     */
    public function allocate(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $employee = $this->workforce->employee((string) $dto['employee']) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            $type = self::type((string) $dto['type']);
            $kind = ($dto['kind'] ?? 'allocation') === 'adjustment' ? 'adjustment' : 'allocation';
            $days = round((float) $dto['days'], 2);
            if ($days == 0.0 || ($kind === 'allocation' && $days < 0)) {
                throw AppError::validation('LEAVE_DAYS', $kind === 'allocation' ? 'عدد الأيام الممنوحة يكون أكبر من صفر — للخصم استخدم «تسوية»' : 'عدد أيام التسوية لا يكون صفرًا',
                    $kind === 'allocation' ? 'Granted days must be positive — use an adjustment to take days away' : 'An adjustment cannot be zero');
            }
            $note = Shape::text($dto['note'] ?? null, 500);
            if ($kind === 'adjustment') {
                $note = Shape::reason($note, 'التسوية', 'the adjustment');
            }
            $year = (int) $dto['year'];
            if ($kind === 'adjustment' && $days < 0 && ! $type->allow_negative && $this->balance($employee->id, $type->id, $year) + $days < -0.001) {
                throw AppError::rule('LEAVE_BALANCE', 'التسوية تجعل الرصيد سالبًا وهذا النوع لا يسمح بذلك', 'The adjustment would make the balance negative, which this type does not allow');
            }
            $entry = LeaveEntry::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => $kind, 'days' => $days, 'note' => $note, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'LEAVE.'.strtoupper($kind), 'entityType' => 'LeaveEntry', 'entityId' => $entry->id, 'entityNumber' => $employee->code,
                'newValue' => ['type' => $type->code, 'year' => $year, 'days' => $days]]);

            return ['id' => $entry->id, 'balances' => $this->balances($employee->id, $year)];
        });
    }

    /**
     * The yearly allowance of every type that has one, for everyone at work — once per employee, type and year: a
     * second call adds nothing.
     *
     * @return array{year:int, created:int, existing:int}
     */
    public function grantYear(AuthUser $user, int $year, ?string $typeId = null): array
    {
        return DB::transaction(function () use ($user, $year, $typeId) {
            $types = LeaveType::where('active', true)->where('yearly_days', '>', 0)->when($typeId, fn ($q) => $q->where('id', $typeId))->get();
            $created = $existing = 0;
            $reference = "yearly:{$year}";
            foreach ($types as $type) {
                $have = LeaveEntry::where('leave_type_id', $type->id)->where('reference', $reference)->pluck('employee_id')->flip();
                foreach ($this->workforce->active() as $employee) {
                    if (isset($have[$employee->id])) {
                        $existing++;

                        continue;
                    }
                    LeaveEntry::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year, 'kind' => 'allocation', 'days' => $type->yearly_days, 'reference' => $reference,
                        'note' => "الرصيد السنوي {$year}", 'created_by' => $user->username]);
                    $created++;
                }
            }
            $this->audit->log($user, ['action' => 'LEAVE.GRANT_YEAR', 'entityType' => 'LeaveEntry', 'entityNumber' => (string) $year, 'newValue' => ['types' => $types->pluck('code')->all(), 'created' => $created, 'existing' => $existing]]);

            return ['year' => $year, 'created' => $created, 'existing' => $existing];
        });
    }

    /** The days of a leave leave the balance (it was finally approved). Inside the caller's transaction; never twice. */
    public function take(Leave $leave, string $by, ?string $source = null): void
    {
        if ($this->netOf($leave->id) != 0.0) {
            return;
        }
        LeaveEntry::create(['employee_id' => $leave->employee_id, 'leave_type_id' => $leave->leave_type_id, 'year' => $leave->year, 'kind' => 'taken', 'days' => -1 * (float) $leave->days,
            'leave_id' => $leave->id, 'note' => $leave->number, 'created_by' => $by, 'source_system' => $source]);
    }

    /** …and come back (the approved leave was cancelled). */
    public function restore(Leave $leave, string $by, ?string $source = null): void
    {
        $net = $this->netOf($leave->id);
        if ($net == 0.0) {
            return;
        }
        LeaveEntry::create(['employee_id' => $leave->employee_id, 'leave_type_id' => $leave->leave_type_id, 'year' => $leave->year, 'kind' => 'restored', 'days' => -1 * $net,
            'leave_id' => $leave->id, 'note' => $leave->number, 'created_by' => $by, 'source_system' => $source]);
    }

    /** What the ledger holds for one leave, net (0 when nothing was taken, or everything came back). */
    public function netOf(string $leaveId): float
    {
        return round((float) LeaveEntry::where('leave_id', $leaveId)->sum('days'), 2);
    }

    /**
     * Proves the ledger against the leaves: every approved leave has taken exactly its days; no other leave holds any.
     *
     * @return array{ok: bool, checked: int, problems: array<int, array{leave: ?string, problem: string}>}
     */
    public function reconcile(): array
    {
        $problems = [];
        $net = LeaveEntry::whereNotNull('leave_id')->selectRaw('leave_id, coalesce(sum(days), 0) as days')->groupBy('leave_id')->pluck('days', 'leave_id');
        $checked = 0;
        foreach (Leave::query()->where(fn ($w) => $w->where('state', 'approved')->orWhereIn('id', $net->keys()->all() ?: ['-']))->get(['id', 'number', 'state', 'days']) as $leave) {
            $checked++;
            $held = round((float) ($net[$leave->id] ?? 0), 2);
            $due = $leave->state === 'approved' ? round(-1 * (float) $leave->days, 2) : 0.0;
            if (abs($held - $due) > 0.001) {
                $problems[] = ['leave' => $leave->number, 'problem' => "ledger holds {$held} day(s), the leave ({$leave->state}) calls for {$due}"];
            }
            $net->forget($leave->id);
        }
        foreach ($net as $leaveId => $days) {
            $problems[] = ['leave' => null, 'problem' => "ledger lines of {$days} day(s) point at a leave that does not exist ({$leaveId})"];
        }

        return ['ok' => $problems === [], 'checked' => $checked, 'problems' => $problems];
    }

    public static function type(string $idOrCode): LeaveType
    {
        return LeaveType::where('id', $idOrCode)->orWhere('code', $idOrCode)->first() ?? throw AppError::notFound('LEAVE_TYPE_NOT_FOUND', 'نوع الإجازة غير موجود', 'Leave type not found');
    }

    public static function yearOf(string $date): int
    {
        return (int) Carbon::parse($date)->format('Y');
    }
}
