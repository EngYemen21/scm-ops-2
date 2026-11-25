<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Run;
use App\Payroll\Models\Sheet;
use App\Payroll\Models\SheetBatch;
use App\Payroll\Models\SheetLine;
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
use Illuminate\Support\Str;

/**
 * Attendance sheets: the period sheet of one employee, built from the presences and the work schedule, turned into
 * deductible and payable time by the attendance policy.
 *
 *   draft ──confirm──▶ confirmed ──approve──▶ approved          (reopen: back to draft, with a reason)
 *
 * WHO. A holder of `attendance.manage` generates, recomputes, corrects and confirms; `payroll.manage` /
 * `payroll.approve` approves — the approved totals are what the month's payslip takes (overtime paid; lateness, early
 * leave and absence deducted). A sheet whose payslip stands in an approved run cannot be reopened.
 *
 * A CORRECTION of a line changes the figures after the policy (late, early leave, overtime) and needs a note; the
 * value it replaced, who changed it and why are kept (pay_attendance_changes), and a recompute keeps the correction.
 *
 * BATCHES act on the sheets of a department (or everyone) at once: draft → generated → submitted → closed.
 */
class SheetService
{
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly WorkCalendar $calendar,
        private readonly Workforce $workforce,
        private readonly DayBook $book,
        private readonly AttendanceService $attendance,
    ) {}

    // ───────────────────────────── reads ─────────────────────────────

    /** @param  array{scope?:?string, state?:?string, employee?:?string, department?:?string, period?:?string, batch?:?string, source?:?string}  $f */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $q = Sheet::query();
        if (($f['scope'] ?? 'mine') === 'all') {
            if (! Access::seesAllAttendance($user)) {
                throw AppError::forbidden('FORBIDDEN', 'كشوف حضور الجميع يراها مسؤولو الحضور والرواتب', 'Only attendance and payroll officers see everybody\'s sheets');
            }
        } else {
            $q->where('employee_id', Access::employeeId($user) ?? '-');
        }
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), Sheet::STATES) ?: ['-']);
        }
        foreach (['employee' => 'employee_id', 'department' => 'department_id', 'period' => 'period', 'batch' => 'batch_id'] as $key => $column) {
            if (! empty($f[$key])) {
                $q->where($column, $f[$key]);
            }
        }
        if (! empty($f['source'])) {
            $f['source'] === Owned::ODOO ? $q->where('source_system', Owned::ODOO) : $q->whereNull('source_system');
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhereIn('employee_id', fn ($s) => $s->select('id')->from('hr_employees')
                ->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_ar', 'like', $like)->orWhere('name_en', 'like', $like))));
        }
        $totals = (clone $q)->toBase()->selectRaw('count(*) as n, coalesce(sum(late_minutes), 0) as late, coalesce(sum(early_minutes), 0) as early, coalesce(sum(overtime_minutes), 0) as overtime, coalesce(sum(absence_count), 0) as absences')->first();
        $column = ['from' => 'date_from', 'number' => 'number', 'late' => 'late_minutes', 'overtime' => 'overtime_minutes', 'absence' => 'absence_count', 'state' => 'state'][$page->sort ?? 'from'] ?? 'date_from';
        $rows = $q->with(['employee', 'department', 'batch:id,number'])->orderBy($column, $page->order)->orderBy('id', $page->order)->forPage($page->page, $page->pageSize)->get();
        $slips = $this->slips($rows->all());

        return $page->wrap($rows->map(fn (Sheet $s) => $this->row($s, $user, $slips))->all(), (int) $totals->n)
            + ['totals' => ['lateMinutes' => (int) $totals->late, 'earlyMinutes' => (int) $totals->early, 'overtimeMinutes' => (int) $totals->overtime, 'absenceDays' => (int) $totals->absences]];
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $s = $this->find($idOrNumber)->load(['employee', 'department', 'batch:id,number', 'policy']);
        if (! Access::seesAllAttendance($user) && Access::employeeId($user) !== $s->employee_id) {
            throw Access::denied();
        }
        $lines = $s->lines()->get();
        $changes = AttendanceService::changes('sheet_line', $lines->pluck('id')->all());

        return $this->row($s, $user, $this->slips([$s])) + [
            'policy' => Shape::named($s->policy, ['lateGraceMinutes' => 'late_grace_minutes', 'earlyGraceMinutes' => 'early_grace_minutes']),
            'notes' => $s->notes, 'confirmedBy' => $s->confirmed_by, 'confirmedAt' => Shape::at($s->confirmed_at), 'approvedBy' => $s->approved_by, 'approvedAt' => Shape::at($s->approved_at),
            'sourceState' => $s->source_state, 'extra' => $s->extra,
            'lines' => $lines->map(fn (SheetLine $l) => [
                'id' => $l->id, 'date' => Shape::day($l->work_date), 'weekday' => $l->weekday, 'status' => $l->status,
                'plannedIn' => Shape::clock($l->planned_in), 'plannedOut' => Shape::clock($l->planned_out), 'actualIn' => Shape::clock($l->actual_in), 'actualOut' => Shape::clock($l->actual_out),
                'plannedMinutes' => $l->planned_minutes, 'workedMinutes' => $l->worked_minutes, 'lateActual' => $l->late_actual, 'late' => $l->late, 'earlyActual' => $l->early_actual, 'early' => $l->early,
                'overtimeActual' => $l->overtime_actual, 'overtime' => $l->overtime, 'absenceMinutes' => $l->absence_minutes, 'corrected' => $l->corrected, 'note' => $l->note,
                'changes' => $changes[$l->id] ?? [],
            ])->all(),
        ];
    }

    // ───────────────────────────── one sheet ─────────────────────────────

    /** @param  array{employee:string, dateFrom:string, dateTo:string}  $dto */
    public function generate(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            $employee = $this->workforce->employee((string) $dto['employee']) ?? throw AppError::notFound('EMPLOYEE_NOT_FOUND', 'الموظف غير موجود', 'Employee not found');
            [$from, $to] = self::range($dto['dateFrom'], $dto['dateTo']);
            $sheet = $this->make($user, $employee, $from, $to, null);

            return $this->get($user, $sheet->id);
        });
    }

    /** Builds the lines again from the presences as they are now; a corrected line keeps its correction. */
    public function recompute(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $s = $this->locked($idOrNumber);
            $this->assertState($s, ['draft'], 'يُعاد حساب الكشف وهو مسودة فقط — أعِد فتحه أولًا', 'A sheet is recomputed only as a draft — reopen it first');
            $this->build($s, Employee::findOrFail($s->employee_id));
            $this->audit->log($user, ['action' => 'SHEET.RECOMPUTE', 'entityType' => 'AttendanceSheet', 'entityId' => $s->id, 'entityNumber' => $s->number]);

            return $this->get($user, $s->id);
        });
    }

    public function confirm(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $s = $this->locked($idOrNumber);
            $this->toConfirmed($user, $s);

            return $this->get($user, $s->id);
        });
    }

    public function approve(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $s = $this->locked($idOrNumber);
            $this->toApproved($user, $s);

            return $this->get($user, $s->id);
        });
    }

    /** Back to a draft, with a reason. An approved sheet is reopened by payroll; never once its payslip is approved. */
    public function reopen(AuthUser $user, string $idOrNumber, string $reason): array
    {
        $reason = Shape::reason($reason, 'إعادة فتح الكشف', 'reopening the sheet');

        return DB::transaction(function () use ($user, $idOrNumber, $reason) {
            $s = $this->locked($idOrNumber);
            $this->assertState($s, ['confirmed', 'approved'], 'يُعاد فتح كشف مؤكَّد أو معتمد', 'Only a confirmed or approved sheet is reopened');
            if ($s->state === 'approved') {
                Access::needPay($user);
                $slip = $s->payslip_id ? Payslip::find($s->payslip_id) : null;
                if ($slip && in_array($slip->state, Run::FINAL, true)) {
                    throw AppError::rule('SHEET_IN_PAYROLL', "أرقام هذا الكشف دخلت قسيمة معتمدة ({$slip->number}) — المسير المعتمد لا يتغيّر؛ صحِّح بتسوية في المسير التالي أو اعكس المسير",
                        "This sheet's totals went into an approved payslip ({$slip->number}) — an approved run does not change; correct with an adjustment in the next run or reverse the run", ['payslip' => $slip->number]);
                }
            } elseif (! $user->can('attendance.manage') && ! Access::managesPay($user)) {
                throw AppError::forbidden('FORBIDDEN', 'إعادة فتح الكشف تحتاج صلاحية إدارة الحضور', 'Reopening a sheet needs attendance.manage');
            }
            $from = $s->state;
            $s->update(['state' => 'draft', 'confirmed_by' => null, 'confirmed_at' => null, 'approved_by' => null, 'approved_at' => null, 'payslip_id' => null]);
            $this->audit->status($user, 'AttendanceSheet', $s->id, $s->number, $from, 'draft', $reason);

            return $this->get($user, $s->id);
        });
    }

    /**
     * A correction of one day: the late, early-leave or overtime minutes after the policy, with the note that explains it.
     *
     * @param  array{late?:int|string|null, early?:int|string|null, overtime?:int|string|null, note:string}  $dto
     */
    public function correctLine(AuthUser $user, string $idOrNumber, string $lineId, array $dto): array
    {
        $note = Shape::reason($dto['note'] ?? null, 'التصحيح', 'the correction');

        return DB::transaction(function () use ($user, $idOrNumber, $lineId, $dto, $note) {
            $s = $this->locked($idOrNumber);
            $this->assertState($s, ['draft'], 'تُصحَّح سطور الكشف وهو مسودة فقط — أعِد فتحه أولًا', 'Lines are corrected only while the sheet is a draft — reopen it first');
            $line = SheetLine::where('sheet_id', $s->id)->whereKey($lineId)->lockForUpdate()->first() ?? throw AppError::notFound('SHEET_LINE_NOT_FOUND', 'سطر الكشف غير موجود', 'Sheet line not found');
            $new = [];
            foreach (SheetLine::CORRECTABLE as $field) {
                if (array_key_exists($field, $dto) && $dto[$field] !== null && $dto[$field] !== '') {
                    $value = (int) $dto[$field];
                    if ($value < 0 || $value > 24 * 60) {
                        throw AppError::validation('SHEET_MINUTES', 'الدقائق بين 0 و1440', 'Minutes are between 0 and 1440');
                    }
                    if ($value !== (int) $line->getAttribute($field)) {
                        $new[$field] = $value;
                    }
                }
            }
            if (! $new) {
                throw AppError::rule('SHEET_NO_CHANGE', 'لم يتغيّر شيء في السطر', 'Nothing changed on the line');
            }
            foreach ($new as $field => $value) {
                $this->attendance->change($user, 'sheet_line', $line->id, $s->employee_id, $field, (string) $line->getAttribute($field), (string) $value, $note);
            }
            $line->update($new + ['corrected' => true, 'note' => $note]);
            $this->retotal($s);
            $this->audit->log($user, ['action' => 'SHEET.CORRECT', 'entityType' => 'AttendanceSheet', 'entityId' => $s->id, 'entityNumber' => $s->number,
                'newValue' => ['date' => Shape::day($line->work_date), 'fields' => array_keys($new), 'reason' => $note]]);

            return $this->get($user, $s->id);
        });
    }

    // ───────────────────────────── batches ─────────────────────────────

    /** @param  array{state?:?string, department?:?string}  $f */
    public function batches(Paging $page, array $f): array
    {
        $q = SheetBatch::query()->with('department')->withCount('sheets');
        if (! empty($f['state'])) {
            $q->whereIn('state', array_intersect(explode(',', (string) $f['state']), SheetBatch::STATES) ?: ['-']);
        }
        if (! empty($f['department'])) {
            $q->where('department_id', $f['department']);
        }
        if ($page->q) {
            $like = "%{$page->q}%";
            $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('name', 'like', $like));
        }
        $q->orderBy(['number' => 'number', 'state' => 'state'][$page->sort ?? ''] ?? 'date_from', $page->order)->orderBy('id', $page->order);

        return $page->paginate($q, fn (SheetBatch $b) => $this->batchRow($b));
    }

    public function batch(AuthUser $user, string $idOrNumber): array
    {
        $b = $this->findBatch($idOrNumber)->load('department')->loadCount('sheets');
        $sheets = Sheet::where('batch_id', $b->id)->with(['employee', 'department'])->orderBy('number')->get();
        $slips = $this->slips($sheets->all());

        return $this->batchRow($b) + ['notes' => $b->notes, 'skipped' => $b->skipped ?? [], 'sheets' => $sheets->map(fn (Sheet $s) => $this->row($s, $user, $slips))->all()];
    }

    /** @param  array{department?:?string, dateFrom:string, dateTo:string, name?:?string}  $dto */
    public function createBatch(AuthUser $user, array $dto): array
    {
        return DB::transaction(function () use ($user, $dto) {
            [$from, $to] = self::range($dto['dateFrom'], $dto['dateTo']);
            $department = ! empty($dto['department']) ? (DB::table('hr_departments')->where('id', $dto['department'])->value('id') ?? throw AppError::notFound('DEPARTMENT_NOT_FOUND', 'القسم غير موجود', 'Department not found')) : null;
            $b = SheetBatch::create(['number' => $this->numbering->next('ATB'), 'name' => Shape::text($dto['name'] ?? null, 190), 'department_id' => $department, 'date_from' => $from, 'date_to' => $to,
                'state' => 'draft', 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'SHEETBATCH.CREATE', 'entityType' => 'AttendanceSheetBatch', 'entityId' => $b->id, 'entityNumber' => $b->number, 'newValue' => ['from' => $from, 'to' => $to]]);
            $this->audit->status($user, 'AttendanceSheetBatch', $b->id, $b->number, null, 'draft');

            return $this->batch($user, $b->id);
        });
    }

    /** draft → generated: a sheet for everyone at work in the department who has a contract in the period and no sheet yet. */
    public function generateBatch(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $b = $this->lockedBatch($idOrNumber, 'generated');
            [$from, $to] = [Shape::day($b->date_from), Shape::day($b->date_to)];
            $employees = $this->workforce->active($b->department_id);
            $this->book->load($employees->pluck('id')->all(), $from, $to);
            $withContract = [];
            foreach ($employees as $e) {
                $withContract[$e->id] = $this->book->hasContract($e->id);
            }
            $created = 0;
            $skipped = [];
            foreach ($employees as $e) {
                if (! $withContract[$e->id]) {
                    $skipped[] = ['employee' => $e->code, 'reason' => 'NO_CONTRACT'];

                    continue;
                }
                if ($this->overlapping($e->id, $from, $to)) {
                    $skipped[] = ['employee' => $e->code, 'reason' => 'HAS_SHEET'];

                    continue;
                }
                $this->make($user, $e, $from, $to, $b->id);
                $created++;
            }
            if ($created === 0) {
                throw AppError::rule('BATCH_EMPTY', 'لا موظف يُنشأ له كشف في هذه الدفعة (لا عقد سارٍ في المدة، أو له كشف بالفعل)', 'Nobody gets a sheet in this batch (no contract in force in the period, or a sheet already exists)', ['skipped' => $skipped]);
            }
            $b->update(['state' => 'generated', 'skipped' => $skipped]);
            $this->audit->status($user, 'AttendanceSheetBatch', $b->id, $b->number, 'draft', 'generated', "كشوف: {$created}");

            return $this->batch($user, $b->id);
        });
    }

    /** generated → submitted: every draft sheet of the batch is confirmed — all of them, or none. */
    public function submitBatch(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $b = $this->lockedBatch($idOrNumber, 'submitted');
            foreach (Sheet::where('batch_id', $b->id)->where('state', 'draft')->orderBy('number')->lockForUpdate()->get() as $s) {
                $this->toConfirmed($user, $s);
            }
            $b->update(['state' => 'submitted']);
            $this->audit->status($user, 'AttendanceSheetBatch', $b->id, $b->number, 'generated', 'submitted');

            return $this->batch($user, $b->id);
        });
    }

    /** submitted → closed: every confirmed sheet of the batch is approved. */
    public function closeBatch(AuthUser $user, string $idOrNumber): array
    {
        return DB::transaction(function () use ($user, $idOrNumber) {
            $b = $this->lockedBatch($idOrNumber, 'closed');
            $drafts = Sheet::where('batch_id', $b->id)->where('state', 'draft')->count();
            if ($drafts > 0) {
                throw AppError::rule('BATCH_HAS_DRAFTS', "في الدفعة {$drafts} كشف أُعيد فتحه — أكِّده أولًا", "{$drafts} sheet(s) of the batch were reopened — confirm them first", ['drafts' => $drafts]);
            }
            foreach (Sheet::where('batch_id', $b->id)->where('state', 'confirmed')->orderBy('number')->lockForUpdate()->get() as $s) {
                $this->toApproved($user, $s);
            }
            $b->update(['state' => 'closed']);
            $this->audit->status($user, 'AttendanceSheetBatch', $b->id, $b->number, 'submitted', 'closed');

            return $this->batch($user, $b->id);
        });
    }

    // ───────────────────────────── for payroll ─────────────────────────────

    /** The approved sheets of an employee that feed the payslip of a period and are not in another standing payslip. @return \Illuminate\Support\Collection<int, Sheet> */
    public function approvedFor(string $employeeId, string $period, ?string $payslipId = null)
    {
        return Sheet::where('employee_id', $employeeId)->where('period', $period)->where('state', 'approved')
            ->where(fn ($w) => $w->whereNull('payslip_id')->when($payslipId, fn ($x) => $x->orWhere('payslip_id', $payslipId)))->orderBy('date_from')->get();
    }

    // ───────────────────────────── internals ─────────────────────────────

    private function make(AuthUser $user, Employee $employee, string $from, string $to, ?string $batchId): Sheet
    {
        if ($clash = $this->overlapping($employee->id, $from, $to)) {
            throw AppError::rule('SHEET_OVERLAP', "للموظف {$employee->code} كشف آخر يتقاطع مع هذه المدة ({$clash->number})", "Employee {$employee->code} already has a sheet overlapping these dates ({$clash->number})", ['sheet' => $clash->number]);
        }
        $sheet = Sheet::create(['number' => $this->numbering->next('ATS'), 'employee_id' => $employee->id, 'batch_id' => $batchId, 'department_id' => $employee->department_id,
            'date_from' => $from, 'date_to' => $to, 'period' => substr($to, 0, 7), 'state' => 'draft', 'created_by' => $user->username]);
        $this->build($sheet, $employee);
        $this->audit->log($user, ['action' => 'SHEET.GENERATE', 'entityType' => 'AttendanceSheet', 'entityId' => $sheet->id, 'entityNumber' => $sheet->number, 'newValue' => ['employee' => $employee->code, 'from' => $from, 'to' => $to]]);
        $this->audit->status($user, 'AttendanceSheet', $sheet->id, $sheet->number, null, 'draft');

        return $sheet;
    }

    /** The lines of a sheet from the day book, through the policy; a line a person corrected keeps what they set. */
    private function build(Sheet $sheet, Employee $employee): void
    {
        [$from, $to] = [Shape::day($sheet->date_from), Shape::day($sheet->date_to)];
        $this->book->load([$employee->id], $from, $to);
        $policy = $this->calendar->policyOf($employee->id);
        $lines = [];
        for ($d = Carbon::parse($from); $d->format('Y-m-d') <= $to; $d = $d->copy()->addDay()) {
            $day = $this->book->day($employee, $d->format('Y-m-d'));
            $lines[] = SheetCalculator::day($day) + ['kind' => $day['kind'], 'date' => $day['date'], 'weekday' => $day['weekday']];
        }
        $lines = SheetCalculator::applyPolicy($lines, $policy);
        $existing = SheetLine::where('sheet_id', $sheet->id)->get()->keyBy(fn (SheetLine $l) => Shape::day($l->work_date));
        foreach ($lines as $l) {
            $values = ['weekday' => $l['weekday'], 'planned_in' => $l['planned_in'], 'planned_out' => $l['planned_out'], 'actual_in' => $l['actual_in'], 'actual_out' => $l['actual_out'],
                'planned_minutes' => $l['planned_minutes'], 'worked_minutes' => $l['worked_minutes'], 'late_actual' => $l['late_actual'], 'early_actual' => $l['open'] ? 0 : $l['early_actual'],
                'overtime_actual' => $l['overtime_actual'], 'absence_minutes' => $l['absence_minutes'], 'status' => $l['status']];
            $row = $existing[$l['date']] ?? null;
            if ($row?->corrected) {
                $row->update($values); // the figures after the policy and the note are the person's
            } else {
                $values += ['late' => $l['late'], 'early' => $l['open'] ? 0 : $l['early'], 'overtime' => $l['overtime'], 'note' => $l['open'] ? 'انصراف غير مسجَّل — Check-out not recorded' : null];
                $row ? $row->update($values) : SheetLine::create(['sheet_id' => $sheet->id, 'work_date' => $l['date']] + $values);
            }
            unset($existing[$l['date']]);
        }
        foreach ($existing as $stale) {
            $stale->delete(); // a day that is no longer in the period of a draft
        }
        $sheet->update(['policy_id' => $policy?->id, 'schedule_id' => $this->calendar->scheduleOf($employee->id)?->id, 'contract_id' => $this->workforce->contractOn($employee->id, $to)?->id ?? $sheet->contract_id]);
        $this->retotal($sheet);
    }

    private function retotal(Sheet $sheet): void
    {
        $lines = SheetLine::where('sheet_id', $sheet->id)->get()->map(fn (SheetLine $l) => $l->getAttributes())->all();
        $sheet->update(SheetCalculator::totals($lines));
    }

    private function toConfirmed(AuthUser $user, Sheet $s): void
    {
        if (! Sheet::can($s->state, 'confirmed')) {
            throw $this->transition($s, 'confirmed');
        }
        if (Shape::day($s->date_to) > Carbon::today()->toDateString()) {
            throw AppError::rule('SHEET_PERIOD_OPEN', "مدة الكشف {$s->number} لم تنتهِ بعد — يُؤكَّد بعد آخر يوم فيها", "The period of sheet {$s->number} has not ended yet — it is confirmed after its last day");
        }
        $open = Attendance::where('employee_id', $s->employee_id)->where('status', 'ok')->whereNull('check_out')->whereDate('work_date', '>=', Shape::day($s->date_from))->whereDate('work_date', '<=', Shape::day($s->date_to))
            ->orderBy('work_date')->pluck('work_date')->map(fn ($d) => Shape::day($d))->all();
        if ($open) {
            throw AppError::rule('SHEET_OPEN_ATTENDANCE', "في الكشف {$s->number} حضور بلا انصراف (".implode('، ', array_slice($open, 0, 5)).') — صحِّح السجل ثم أعِد الحساب',
                "Sheet {$s->number} has check-ins without a check-out (".implode(', ', array_slice($open, 0, 5)).') — correct the records and recompute', ['dates' => $open]);
        }
        $s->update(['state' => 'confirmed', 'confirmed_by' => $user->username, 'confirmed_at' => now()]);
        $this->audit->status($user, 'AttendanceSheet', $s->id, $s->number, 'draft', 'confirmed');
    }

    private function toApproved(AuthUser $user, Sheet $s): void
    {
        Access::needPay($user);
        if ($s->state !== 'confirmed' || ! Sheet::can($s->state, 'approved')) {
            throw $this->transition($s, 'approved');
        }
        $s->update(['state' => 'approved', 'approved_by' => $user->username, 'approved_at' => now()]);
        $this->audit->status($user, 'AttendanceSheet', $s->id, $s->number, 'confirmed', 'approved');
    }

    private function overlapping(string $employeeId, string $from, string $to): ?Sheet
    {
        return Sheet::where('employee_id', $employeeId)->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->first();
    }

    /** @return array{0:string, 1:string} */
    private static function range(mixed $from, mixed $to): array
    {
        $from = Carbon::parse((string) $from)->format('Y-m-d');
        $to = Carbon::parse((string) $to)->format('Y-m-d');
        if ($to < $from || Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 > self::MAX_DAYS) {
            throw AppError::validation('SHEET_DATES', 'مدة الكشف من يوم إلى '.self::MAX_DAYS.' يومًا، ونهايتها لا تسبق بدايتها', 'A sheet covers one to '.self::MAX_DAYS.' days and cannot end before it starts');
        }

        return [$from, $to];
    }

    private function find(string $idOrNumber): Sheet
    {
        return Sheet::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('SHEET_NOT_FOUND', 'كشف الحضور غير موجود', 'Attendance sheet not found');
    }

    private function locked(string $idOrNumber): Sheet
    {
        $s = Sheet::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('SHEET_NOT_FOUND', 'كشف الحضور غير موجود', 'Attendance sheet not found');
        Owned::refuse($s, 'sheet', "كشف الحضور {$s->number}", "Attendance sheet {$s->number}");

        return $s;
    }

    private function findBatch(string $idOrNumber): SheetBatch
    {
        return SheetBatch::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('SHEET_BATCH_NOT_FOUND', 'دفعة الكشوف غير موجودة', 'Sheet batch not found');
    }

    /** The batch under a lock, about to move to `$to`. */
    private function lockedBatch(string $idOrNumber, string $to): SheetBatch
    {
        $b = SheetBatch::where(fn ($w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first() ?? throw AppError::notFound('SHEET_BATCH_NOT_FOUND', 'دفعة الكشوف غير موجودة', 'Sheet batch not found');
        Owned::refuse($b, 'sheet_batch', "دفعة الكشوف {$b->number}", "Sheet batch {$b->number}");
        if (! in_array($to, SheetBatch::TRANSITIONS[$b->state] ?? [], true)) {
            throw AppError::rule('SHEET_BATCH_TRANSITION', "انتقال غير مسموح للدفعة {$b->number}: {$b->state} ← {$to}", "Invalid transition for batch {$b->number}: {$b->state} -> {$to}", ['from' => $b->state, 'to' => $to]);
        }

        return $b;
    }

    /** @param string[] $states */
    private function assertState(Sheet $s, array $states, string $ar, string $en): void
    {
        if (! in_array($s->state, $states, true)) {
            throw AppError::rule('SHEET_STATE', "{$ar} (حالة {$s->number}: {$s->state})", "{$en} ({$s->number} is {$s->state})", ['state' => $s->state]);
        }
    }

    private function transition(Sheet $s, string $to): AppError
    {
        return AppError::rule('SHEET_TRANSITION', "انتقال غير مسموح للكشف {$s->number}: {$s->state} ← {$to}", "Invalid transition for sheet {$s->number}: {$s->state} -> {$to}", ['from' => $s->state, 'to' => $to]);
    }

    /** @param  Sheet[]  $sheets  @return array<string, Payslip> payslip id → payslip (number, state) */
    private function slips(array $sheets): array
    {
        $ids = array_values(array_filter(array_map(fn (Sheet $s) => $s->payslip_id, $sheets)));

        return $ids ? Payslip::whereIn('id', $ids)->get(['id', 'number', 'state'])->keyBy('id')->all() : [];
    }

    private function row(Sheet $s, AuthUser $user, array $slips): array
    {
        $free = ! Owned::locked($s);
        $officer = $free && $user->can('attendance.manage');
        $payroll = $free && Access::managesPay($user);
        $slip = $s->payslip_id ? ($slips[$s->payslip_id] ?? null) : null;
        $totals = [];
        foreach (Sheet::TOTALS as $column) {
            $totals[Str::camel($column)] = $column === 'leave_days' ? (float) $s->getAttribute($column) : (int) $s->getAttribute($column);
        }

        return ['id' => $s->id, 'number' => $s->number, 'employee' => Shape::employee($s->employee), 'department' => Shape::department($s->department),
            'batch' => $s->batch ? ['id' => $s->batch->id, 'number' => $s->batch->number] : null, 'dateFrom' => Shape::day($s->date_from), 'dateTo' => Shape::day($s->date_to), 'period' => $s->period,
            'state' => $s->state, 'totals' => $totals, 'payslip' => $slip ? ['id' => $slip->id, 'number' => $slip->number, 'state' => $slip->state] : null,
            'mine' => Access::employeeId($user) === $s->employee_id, 'source' => $s->source_system, 'locked' => ! $free, 'createdAt' => Shape::at($s->created_at),
            'can' => ['recompute' => $officer && $s->state === 'draft', 'correct' => $officer && $s->state === 'draft', 'confirm' => $officer && $s->state === 'draft',
                'approve' => $payroll && $s->state === 'confirmed',
                'reopen' => ($s->state === 'confirmed' && ($officer || $payroll)) || ($s->state === 'approved' && $payroll && ! ($slip && in_array($slip->state, Run::FINAL, true)))]];
    }

    private function batchRow(SheetBatch $b): array
    {
        $by = Sheet::where('batch_id', $b->id)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');

        return ['id' => $b->id, 'number' => $b->number, 'name' => $b->name, 'department' => Shape::department($b->department), 'dateFrom' => Shape::day($b->date_from), 'dateTo' => Shape::day($b->date_to),
            'state' => $b->state, 'sheetCount' => (int) ($b->sheets_count ?? 0), 'sheetsByState' => ['draft' => (int) ($by['draft'] ?? 0), 'confirmed' => (int) ($by['confirmed'] ?? 0), 'approved' => (int) ($by['approved'] ?? 0)],
            'source' => $b->source_system, 'locked' => Owned::locked($b), 'createdBy' => $b->created_by, 'createdAt' => Shape::at($b->created_at),
            'next' => ['draft' => 'generate', 'generated' => 'submit', 'submitted' => 'close'][$b->state] ?? null];
    }
}
