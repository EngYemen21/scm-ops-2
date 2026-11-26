<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\AttendanceChange;
use App\Payroll\Models\Sheet;
use App\Payroll\Support\Access;
use App\Payroll\Support\Owned;
use App\Payroll\Support\Shape;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Check in / check out and the daily board.
 *
 * An employee checks in and out for themselves (one open presence at a time). A holder of `attendance.manage` enters
 * a presence somebody forgot, corrects one, or voids one that should not exist — always with a reason: the record
 * keeps what it said first, every changed value gets a line in the change log (who, what, why), and nothing is removed.
 * A day that already stands in a confirmed or approved attendance sheet is not touched here: the sheet is reopened
 * first, so that what was confirmed and what the records say never part ways unseen.
 *
 * The audit trail names the employee by code and the fields that changed — never the times themselves.
 */
class AttendanceService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WorkCalendar $calendar,
        private readonly Workforce $workforce,
        private readonly DayBook $book,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{scope?:?string, employee?:?string, department?:?string, from?:?string, to?:?string, status?:?string, source?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = Attendance::query();
        if (($f['scope'] ?? 'mine') === 'all') {
            if (! Access::seesAllAttendance($user)) {
                throw AppError::forbidden('FORBIDDEN', 'حضور الجميع يراه مسؤولو الحضور والرواتب', 'Only attendance and payroll officers see everybody\'s attendance');
            }
        } else {
            $q->where('employee_id', Access::employeeId($user) ?? '-');
        }
        if (! empty($f['employee'])) {
            $q->where('employee_id', $f['employee']);
        }
        if (! empty($f['department'])) {
            $q->whereIn('employee_id', Employee::where('department_id', $f['department'])->select('id'));
        }
        if (! empty($f['from'])) {
            $q->whereDate('work_date', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('work_date', '<=', $f['to']);
        }
        match ($f['status'] ?? null) {
            'open' => $q->where('status', 'ok')->whereNull('check_out'),
            'closed' => $q->where('status', 'ok')->whereNotNull('check_out'),
            'void' => $q->where('status', 'void'),
            'corrected' => $q->where('corrected', true),
            'all' => null,
            default => $q->where('status', 'ok'),
        };
        if (! empty($f['source'])) {
            $f['source'] === Owned::ODOO ? $q->where('source_system', Owned::ODOO) : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->whereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like)));
        }
        $totals = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(worked_minutes), 0) as minutes')->first();
        $column = ['worked' => 'worked_minutes', 'date' => 'check_in'][$page->sort ?? 'date'] ?? 'check_in';
        $rows = $q->with('employee')->orderBy($column, $page->order)->orderBy('id', $page->order)->forPage($page->page, $page->pageSize)->get();
        $blocked = $this->sheetDays($rows->all());

        return $page->wrap($rows->map(fn (Attendance $a) => $this->row($a, $user, $blocked))->all(), (int) $totals->n) + ['totals' => ['workedMinutes' => (int) $totals->minutes]];
    }

    public function get(AuthUser $user, string $id): array
    {
        $a = Attendance::with('employee')->find($id) ?? throw AppError::notFound('ATTENDANCE_NOT_FOUND', 'سجل الحضور غير موجود', 'Attendance record not found');
        if (! Access::seesAllAttendance($user) && Access::employeeId($user) !== $a->employee_id) {
            throw Access::denied();
        }

        return $this->row($a, $user, $this->sheetDays([$a])) + ['changes' => self::changes('attendance', [$a->id])[$a->id] ?? []];
    }

    /** The caller's own state right now: are they in, since when, and what today plans. */
    public function mine(AuthUser $user): array
    {
        $employeeId = Access::employeeId($user);
        if (! $employeeId) {
            return ['linked' => false];
        }
        $schedule = $this->calendar->scheduleOf($employeeId);
        $today = $this->calendar->dateOf($schedule, now());
        $planned = $this->calendar->planned($schedule, $today);
        $open = $this->openOf($employeeId, $today);
        $done = (int) Attendance::where('employee_id', $employeeId)->where('status', 'ok')->whereDate('work_date', $today)->sum('worked_minutes');

        return ['linked' => true, 'date' => $today, 'timezone' => $this->calendar->timezone($schedule), 'working' => $planned['working'], 'plannedIn' => Shape::clock($planned['in']), 'plannedOut' => Shape::clock($planned['out']),
            'in' => $open !== null, 'since' => Shape::at($open?->check_in), 'openId' => $open?->id, 'workedMinutesToday' => $done];
    }

    /**
     * The daily board: everyone at work on one date — what the day planned, when they came and left, late, early
     * leave and overtime as they stand (the actual minutes; the policy is applied on the period sheet).
     *
     * @param  array{department?:?string, state?:?string}  $f
     */
    public function daily(AuthUser $user, string $date, Paging $page, array $f): array
    {
        if (! Access::seesAllAttendance($user)) {
            throw AppError::forbidden('FORBIDDEN', 'لوحة الحضور اليومية لمسؤولي الحضور والرواتب', 'The daily board is for attendance and payroll officers');
        }
        $employees = $this->workforce->active(($f['department'] ?? null) ?: null);
        if ($page->q) {
            $needle = mb_strtolower($page->q);
            $employees = $employees->filter(fn (Employee $e) => str_contains(mb_strtolower("{$e->code} {$e->name_ar} {$e->name_en}"), $needle))->values();
        }
        $this->book->load($employees->pluck('id')->all(), $date, $date);
        $rows = [];
        $totals = ['present' => 0, 'absent' => 0, 'late' => 0, 'onLeave' => 0, 'open' => 0, 'off' => 0];
        foreach ($employees as $e) {
            $day = $this->book->day($e, $date);
            $calc = SheetCalculator::day($day);
            $state = match (true) {
                $calc['open'] => 'open',
                $calc['actual_in'] !== null => 'present',
                $day['kind'] === 'work' => 'absent',
                $day['kind'] === 'future' => 'expected',
                default => $day['kind'],
            };
            $totals['present'] += in_array($state, ['present', 'open'], true) ? 1 : 0;
            $totals['open'] += $state === 'open' ? 1 : 0;
            $totals['absent'] += $state === 'absent' ? 1 : 0;
            $totals['onLeave'] += $state === 'leave' ? 1 : 0;
            $totals['off'] += in_array($state, ['weekend', 'holiday', 'off'], true) ? 1 : 0;
            $totals['late'] += $calc['late_actual'] > 0 ? 1 : 0;
            $rows[] = ['employee' => Shape::employee($e), 'departmentId' => $e->department_id, 'kind' => $day['kind'], 'state' => $state,
                'plannedIn' => Shape::clock($calc['planned_in']), 'plannedOut' => Shape::clock($calc['planned_out']), 'plannedMinutes' => $calc['planned_minutes'],
                'actualIn' => Shape::clock($calc['actual_in']), 'actualOut' => Shape::clock($calc['actual_out']), 'workedMinutes' => $calc['worked_minutes'],
                'lateMinutes' => $calc['late_actual'], 'earlyMinutes' => $calc['open'] ? 0 : $calc['early_actual'], 'overtimeMinutes' => $calc['overtime_actual'], 'records' => $day['records'],
                'leave' => $day['leave'] ? Shape::named($day['leave']->type) : null, 'holiday' => $day['holiday'] ? Shape::named($day['holiday']) : null];
        }
        if (! empty($f['state'])) {
            $wanted = explode(',', (string) $f['state']);
            $rows = array_values(array_filter($rows, fn (array $r) => in_array($r['state'], $wanted, true) || (in_array('late', $wanted, true) && $r['lateMinutes'] > 0)));
        }
        $sort = ['late' => 'lateMinutes', 'worked' => 'workedMinutes', 'overtime' => 'overtimeMinutes'][$page->sort ?? ''] ?? null;
        if ($sort) {
            usort($rows, fn (array $a, array $b) => ($page->order === 'asc' ? 1 : -1) * ($a[$sort] <=> $b[$sort]));
        }

        return $page->wrap(array_slice($rows, ($page->page - 1) * $page->pageSize, $page->pageSize), count($rows)) + ['date' => $date, 'totals' => $totals];
    }

    // ───────────────────────────── the employee's own clock ─────────────────────────────

    public function checkIn(AuthUser $user): array
    {
        return DB::transaction(function () use ($user) {
            $employee = $this->self($user);
            $schedule = $this->calendar->scheduleOf($employee->id);
            $now = now();
            $today = $this->calendar->dateOf($schedule, $now);
            if ($open = $this->openOf($employee->id, $today)) {
                throw AppError::rule('ATTENDANCE_ALREADY_IN', 'أنت مسجَّل حضورًا بالفعل — سجِّل الانصراف أولًا', 'You are already checked in — check out first', ['since' => Shape::at($open->check_in)]);
            }
            $a = Attendance::create(['employee_id' => $employee->id, 'work_date' => $today, 'check_in' => $now, 'in_mode' => 'self', 'status' => 'ok', 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'ATTENDANCE.CHECK_IN', 'entityType' => 'Attendance', 'entityId' => $a->id, 'entityNumber' => $employee->code]);

            return $this->mine($user);
        });
    }

    public function checkOut(AuthUser $user): array
    {
        return DB::transaction(function () use ($user) {
            $employee = $this->self($user);
            $schedule = $this->calendar->scheduleOf($employee->id);
            $now = now();
            $open = $this->openOf($employee->id, $this->calendar->dateOf($schedule, $now));
            if (! $open) {
                throw AppError::rule('ATTENDANCE_NOT_IN', 'لا حضور مفتوح لتسجيل الانصراف منه — سجِّل الحضور أولًا، أو اطلب تصحيح سجل الأمس', 'There is no open check-in to check out of — check in first, or ask for yesterday\'s record to be corrected');
            }
            $open->update(['check_out' => $now, 'out_mode' => 'self', 'worked_minutes' => self::minutes($open->check_in, $now)]);
            $this->audit->log($user, ['action' => 'ATTENDANCE.CHECK_OUT', 'entityType' => 'Attendance', 'entityId' => $open->id, 'entityNumber' => $employee->code]);

            return $this->mine($user);
        });
    }

    // ───────────────────────────── the officer's hand ─────────────────────────────

    /** @param  array{employee:string, checkIn:string, checkOut?:?string, reason:string}  $dto */
    public function create(AuthUser $user, array $dto): array
    {
        $reason = Shape::reason($dto['reason'] ?? null, 'إدخال الحضور يدويًا', 'entering attendance by hand');

        return DB::transaction(function () use ($user, $dto, $reason) {
            $employee = $this->workforce->employee((string) $dto['employee']) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            Employee::whereKey($employee->id)->lockForUpdate()->first();
            $schedule = $this->calendar->scheduleOf($employee->id);
            [$in, $out] = $this->times($schedule, $dto['checkIn'], $dto['checkOut'] ?? null);
            $date = $this->calendar->dateOf($schedule, $in);
            $this->assertFree($employee->id, $date);
            $this->assertNoOverlap($employee->id, $in, $out, null);
            $a = Attendance::create(['employee_id' => $employee->id, 'work_date' => $date, 'check_in' => $in, 'check_out' => $out, 'worked_minutes' => $out ? self::minutes($in, $out) : 0,
                'in_mode' => 'manual', 'out_mode' => $out ? 'manual' : null, 'status' => 'ok', 'note' => $reason, 'created_by' => $user->username]);
            $this->change($user, 'attendance', $a->id, $employee->id, 'created', null, 'manual', $reason);
            $this->audit->log($user, ['action' => 'ATTENDANCE.CREATE', 'entityType' => 'Attendance', 'entityId' => $a->id, 'entityNumber' => $employee->code, 'newValue' => ['mode' => 'manual', 'date' => $date]]);

            return $this->get($user, $a->id);
        });
    }

    /** @param  array{checkIn?:?string, checkOut?:?string, reason:string}  $dto */
    public function correct(AuthUser $user, string $id, array $dto): array
    {
        $reason = Shape::reason($dto['reason'] ?? null, 'تصحيح الحضور', 'the correction');

        return DB::transaction(function () use ($user, $id, $dto, $reason) {
            $a = $this->locked($id);
            $schedule = $this->calendar->scheduleOf($a->employee_id);
            [$in, $out] = $this->times($schedule, $dto['checkIn'] ?? $a->check_in, array_key_exists('checkOut', $dto) ? $dto['checkOut'] : $a->check_out);
            $date = $this->calendar->dateOf($schedule, $in);
            $this->assertFree($a->employee_id, Shape::day($a->work_date));
            $this->assertFree($a->employee_id, $date);
            $this->assertNoOverlap($a->employee_id, $in, $out, $a->id);
            $changed = [];
            foreach (['check_in' => $in, 'check_out' => $out] as $field => $new) {
                $old = $a->getAttribute($field);
                if (($old?->format('Y-m-d H:i') ?? null) !== ($new?->format('Y-m-d H:i') ?? null)) {
                    $changed[$field] = [$old, $new];
                }
            }
            if (! $changed) {
                throw AppError::rule('ATTENDANCE_NO_CHANGE', 'لم يتغيّر شيء في السجل', 'Nothing changed');
            }
            // what the record said before its FIRST correction stays on it; later corrections are in the change log
            $original = $a->corrected ? [] : ['original_check_in' => $a->check_in, 'original_check_out' => $a->check_out];
            foreach ($changed as $field => [$old, $new]) {
                $this->change($user, 'attendance', $a->id, $a->employee_id, $field, $old?->toJSON(), $new?->toJSON(), $reason);
            }
            $a->update(['check_in' => $in, 'check_out' => $out, 'work_date' => $date, 'worked_minutes' => $out ? self::minutes($in, $out) : 0, 'corrected' => true,
                'out_mode' => $out ? (isset($changed['check_out']) ? 'manual' : $a->out_mode) : null] + $original);
            $this->audit->log($user, ['action' => 'ATTENDANCE.CORRECT', 'entityType' => 'Attendance', 'entityId' => $a->id, 'entityNumber' => Employee::whereKey($a->employee_id)->value('code'),
                'newValue' => ['fields' => array_keys($changed), 'reason' => $reason]]);

            return $this->get($user, $a->id);
        });
    }

    public function void(AuthUser $user, string $id, string $reason): array
    {
        $reason = Shape::reason($reason, 'إلغاء سجل الحضور', 'voiding the record');

        return DB::transaction(function () use ($user, $id, $reason) {
            $a = $this->locked($id);
            $this->assertFree($a->employee_id, Shape::day($a->work_date));
            $this->change($user, 'attendance', $a->id, $a->employee_id, 'status', 'ok', 'void', $reason);
            $a->update(['status' => 'void', 'voided_by' => $user->username, 'voided_at' => now(), 'void_reason' => $reason]);
            $this->audit->log($user, ['action' => 'ATTENDANCE.VOID', 'entityType' => 'Attendance', 'entityId' => $a->id, 'entityNumber' => Employee::whereKey($a->employee_id)->value('code'), 'newValue' => ['reason' => $reason]]);

            return $this->get($user, $a->id);
        });
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** One corrected value, for good. */
    public function change(AuthUser $user, string $targetType, string $targetId, ?string $employeeId, string $field, ?string $old, ?string $new, string $reason): void
    {
        AttendanceChange::create(['target_type' => $targetType, 'target_id' => $targetId, 'employee_id' => $employeeId, 'field' => $field, 'old_value' => $old, 'new_value' => $new,
            'reason' => $reason, 'changed_by' => $user->username, 'changed_by_id' => $user->id, 'changed_at' => now()]);
    }

    /** @param  string[]  $ids  @return array<string, array<int, array<string, mixed>>> target id → its changes, oldest first */
    public static function changes(string $targetType, array $ids): array
    {
        $out = [];
        foreach (AttendanceChange::where('target_type', $targetType)->whereIn('target_id', $ids ?: ['-'])->orderBy('changed_at')->orderBy('id')->get() as $c) {
            $out[$c->target_id][] = ['field' => $c->field, 'old' => $c->old_value, 'new' => $c->new_value, 'reason' => $c->reason, 'by' => $c->changed_by, 'at' => Shape::at($c->changed_at)];
        }

        return $out;
    }

    private function self(AuthUser $user): Employee
    {
        $employee = Employee::whereKey(Access::needEmployee($user))->lockForUpdate()->first();
        if (! $employee || $employee->status !== 'active') {
            throw AppError::rule('EMPLOYEE_NOT_ACTIVE', 'سجلّك الوظيفي غير نشط — لا يُسجَّل له حضور', 'Your employee record is not active — attendance cannot be recorded for it');
        }

        return $employee;
    }

    /** The open presence of today — or one that started less than sixteen hours ago (a shift past midnight). */
    private function openOf(string $employeeId, string $today): ?Attendance
    {
        return Attendance::where('employee_id', $employeeId)->where('status', 'ok')->whereNull('check_out')
            ->where(fn ($w) => $w->whereDate('work_date', $today)->orWhere('check_in', '>=', now()->subHours(16)))->orderByDesc('check_in')->first();
    }

    private function locked(string $id): Attendance
    {
        $a = Attendance::whereKey($id)->lockForUpdate()->first() ?? throw AppError::notFound('ATTENDANCE_NOT_FOUND', 'سجل الحضور غير موجود', 'Attendance record not found');
        Owned::refuse($a, 'attendance', 'سجل الحضور', 'The attendance record');
        if ($a->status === 'void') {
            throw AppError::rule('ATTENDANCE_VOID', 'السجل ملغى — لا يُعدَّل', 'The record is void — it cannot be changed');
        }

        return $a;
    }

    /**
     * A check-in and a check-out as moments. A time without a zone is on the schedule's clock.
     *
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function times(mixed $schedule, mixed $in, mixed $out): array
    {
        $tz = $this->calendar->timezone($schedule);
        $read = fn (mixed $v) => $v instanceof \DateTimeInterface ? Carbon::instance($v)->utc() : Carbon::parse((string) $v, $tz)->utc();
        $in = $read($in);
        $out = $out === null || $out === '' ? null : $read($out);
        if ($in->greaterThan(now()->addMinutes(5)) || ($out && $out->greaterThan(now()->addMinutes(5)))) {
            throw AppError::validation('ATTENDANCE_FUTURE', 'وقت الحضور أو الانصراف لا يكون في المستقبل', 'A check-in or check-out cannot be in the future');
        }
        if ($out && ($out->lessThanOrEqualTo($in) || self::minutes($in, $out) > 24 * 60)) {
            throw AppError::validation('ATTENDANCE_TIMES', 'الانصراف يكون بعد الحضور وخلال 24 ساعة منه', 'The check-out must be after the check-in and within 24 hours of it');
        }

        return [$in, $out];
    }

    private function assertNoOverlap(string $employeeId, Carbon $in, ?Carbon $out, ?string $exceptId): void
    {
        $end = $out ?? $in;
        $clash = Attendance::where('employee_id', $employeeId)->where('status', 'ok')->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('check_in', '<=', $end)->where(fn ($w) => $w->where('check_out', '>', $in)->orWhere(fn ($x) => $x->whereNull('check_out')->where('check_in', '>=', $in)))->exists();
        if ($clash) {
            throw AppError::rule('ATTENDANCE_OVERLAP', 'للموظف سجل حضور آخر يتقاطع مع هذا الوقت', 'The employee has another attendance record overlapping this time');
        }
    }

    /** A day inside a confirmed or approved sheet is the sheet's: reopen the sheet first. */
    private function assertFree(string $employeeId, string $date): void
    {
        $sheet = Sheet::where('employee_id', $employeeId)->whereIn('state', ['confirmed', 'approved'])->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)->first();
        if ($sheet) {
            throw AppError::rule('ATTENDANCE_IN_SHEET', "يوم {$date} داخل كشف حضور ".($sheet->state === 'approved' ? 'معتمد' : 'مؤكَّد')." ({$sheet->number}) — أعِد فتح الكشف أولًا ثم صحِّح",
                "{$date} is inside a {$sheet->state} attendance sheet ({$sheet->number}) — reopen the sheet first", ['sheet' => $sheet->number, 'state' => $sheet->state]);
        }
    }

    /** @param  Attendance[]  $rows  @return array<string, true> "employee|date" keys that stand in a confirmed / approved sheet */
    private function sheetDays(array $rows): array
    {
        $out = [];
        $ids = array_values(array_unique(array_map(fn (Attendance $a) => $a->employee_id, $rows)));
        if (! $ids) {
            return $out;
        }
        $sheets = Sheet::whereIn('employee_id', $ids)->whereIn('state', ['confirmed', 'approved'])->get(['employee_id', 'date_from', 'date_to']);
        foreach ($rows as $a) {
            $date = Shape::day($a->work_date);
            foreach ($sheets as $s) {
                if ($s->employee_id === $a->employee_id && Shape::day($s->date_from) <= $date && Shape::day($s->date_to) >= $date) {
                    $out["{$a->employee_id}|{$date}"] = true;
                    break;
                }
            }
        }

        return $out;
    }

    public static function minutes(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }

    private function row(Attendance $a, AuthUser $user, array $blocked): array
    {
        $date = Shape::day($a->work_date);
        $free = ! Owned::locked($a) && $a->status === 'ok' && ! isset($blocked["{$a->employee_id}|{$date}"]);
        $officer = $user->can('attendance.manage');

        return ['id' => $a->id, 'employee' => Shape::employee($a->employee), 'workDate' => $date, 'checkIn' => Shape::at($a->check_in), 'checkOut' => Shape::at($a->check_out),
            'workedMinutes' => $a->worked_minutes, 'inMode' => $a->in_mode, 'outMode' => $a->out_mode, 'status' => $a->status, 'open' => $a->status === 'ok' && $a->check_out === null,
            'corrected' => $a->corrected, 'originalCheckIn' => Shape::at($a->original_check_in), 'originalCheckOut' => Shape::at($a->original_check_out), 'note' => $a->note,
            'voidedBy' => $a->voided_by, 'voidedAt' => Shape::at($a->voided_at), 'voidReason' => $a->void_reason, 'source' => $a->source_system, 'locked' => Owned::locked($a),
            'inSheet' => isset($blocked["{$a->employee_id}|{$date}"]), 'can' => ['correct' => $officer && $free, 'void' => $officer && $free]];
    }
}
