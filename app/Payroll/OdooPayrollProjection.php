<?php

namespace App\Payroll;

use App\Hr\Models\Employee;
use App\Integration\Odoo\Models\OdooModel;
use App\Integration\Odoo\Models\OdooRecord;
use App\Integration\Odoo\Projection\OdooProjection;
use App\Integration\Odoo\Projection\Val;
use App\Integration\Services\ExternalRefs;
use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\AttendanceRule;
use App\Payroll\Models\AttendanceRuleLine;
use App\Payroll\Models\Component;
use App\Payroll\Models\EmployeeSkill;
use App\Payroll\Models\GosiRate;
use App\Payroll\Models\Holiday;
use App\Payroll\Models\Leave;
use App\Payroll\Models\LeaveEntry;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\Parameter;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\Profile;
use App\Payroll\Models\ResumeLine;
use App\Payroll\Models\ResumeType;
use App\Payroll\Models\Run;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\ScheduleDay;
use App\Payroll\Models\Sheet;
use App\Payroll\Models\SheetBatch;
use App\Payroll\Models\SheetLine;
use App\Payroll\Models\Skill;
use App\Payroll\Models\SkillLevel;
use App\Payroll\Models\SkillType;
use App\Payroll\Models\Structure;
use App\Payroll\Models\StructureRule;
use App\Payroll\Services\LeaveLedger;
use App\Payroll\Services\WorkCalendar;
use App\Payroll\Support\Owned;
use App\Services\Core\AuditService;
use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * From the copy of Odoo to «الرواتب والإجازات والحضور» (docs/odoo/WAVE3.md, P6): Odoo's work schedules and public
 * holidays, kinds of time off with their allocations and requests, attendance records, attendance policies and
 * attendance sheets, pay structures with their rules, payslip batches, payslips with their lines and worked days,
 * and skills and résumé lines become OPS records marked `source_system = 'odoo'` + `external_ref` (the Odoo id).
 * Idempotent: a second pass changes nothing.
 *
 * PEOPLE come from People & expenses: an Odoo employee, contract or department is the OPS record that section linked
 * it to (`int_external_refs`: hr_employee, hr_contract, hr_department). A record of somebody that section does not
 * hold yet is left out and counted (`…skippedEmployeeNotCopied`): load `hr` first.
 *
 * PAY IS HISTORY. A payslip of Odoo keeps Odoo's own figures, line by line and with Odoo's signs; nothing is
 * recomputed and nothing is posted to the OPS books (a confirmed batch's books are Odoo's). It holds its contract's
 * month like any payslip, so an OPS run never pays a month Odoo already holds a payslip for.
 * A structure's rules are kept to be READ (their formulas are Odoo's Python): OPS computes with its own components.
 *
 * THE ATTENDANCE-SHEET MODULE (attendance.sheet*, hr.attendance.policy and its rules) and `hr.public.holiday` are
 * read from the day the copy holds them: today Odoo refuses those records to the connection user, so the pass runs
 * without them, and an attendance policy is known only by the name contracts give it (`incomplete`, decides nothing).
 * The day the access is granted the records enter the copy and this class loads them — no code change.
 *
 * WHAT IS NOT BROUGHT. The generated work entries (hr.work.entry) are Odoo's own derivation of the schedule — OPS
 * derives the same days from the schedule itself; they stay in the archive. Where a presence was recorded from
 * (address, browser, coordinates) is not copied into this section.
 *
 * PEOPLE'S FILES. Counts and notes name records by model and Odoo id only — never a person, never a figure; a
 * database error is re-thrown without its statement, because the statement carries the values.
 */
class OdooPayrollProjection extends OdooProjection
{
    /** Read whenever the copy holds them — parents before children. */
    private const CORE = [
        'resource.calendar', 'resource.calendar.attendance', 'resource.calendar.leaves',
        'hr.payroll.structure', 'hr.salary.rule', 'hr.payslip.input.type', 'hr.rule.parameter.value',
        'hr.leave.type', 'hr.contract', 'hr.leave.allocation', 'hr.leave', 'hr.attendance',
        'hr.payslip.run', 'hr.payslip', 'hr.payslip.line', 'hr.payslip.worked_days', 'hr.payslip.input',
        'hr.skill.type', 'hr.skill', 'hr.skill.level', 'hr.employee.skill', 'hr.resume.line.type', 'hr.resume.line',
    ];

    /** Read from the day the copy holds them (the attendance-sheet module): model → the CORE model it is read before. */
    private const OPTIONAL = [
        'hr.public.holiday' => 'hr.payroll.structure',
        'hr.overtime.rule' => 'hr.payroll.structure', 'hr.late.rule' => 'hr.payroll.structure', 'hr.late.rule.line' => 'hr.payroll.structure',
        'hr.absence.rule' => 'hr.payroll.structure', 'hr.absence.rule.line' => 'hr.payroll.structure', 'hr.diff.rule' => 'hr.payroll.structure', 'hr.diff.rule.line' => 'hr.payroll.structure',
        'hr.attendance.policy' => 'hr.payroll.structure',
        'attendance.sheet.batch' => 'hr.skill.type', 'attendance.sheet' => 'hr.skill.type', 'attendance.sheet.line' => 'hr.skill.type',
    ];

    private const LEAVE_STATE = ['draft' => 'draft', 'confirm' => 'submitted', 'validate1' => 'manager_approved', 'validate' => 'approved', 'refuse' => 'refused', 'cancel' => 'cancelled'];

    private const LEAVE_APPROVAL = ['no_validation' => 'none', 'manager' => 'manager', 'hr' => 'hr', 'both' => 'both'];

    private const RUN_STATE = ['draft' => 'draft', 'verify' => 'review', 'close' => 'approved', 'paid' => 'paid'];

    private const SLIP_STATE = ['draft' => 'draft', 'verify' => 'review', 'done' => 'approved', 'paid' => 'paid', 'cancel' => 'cancelled'];

    private const SHEET_STATE = ['draft' => 'draft', 'confirm' => 'confirmed', 'done' => 'approved'];

    private const BATCH_STATE = ['draft' => 'draft', 'att_gen' => 'generated', 'att_sub' => 'submitted', 'done' => 'closed'];

    private const LINE_STATUS = ['ab' => 'absence', 'weekend' => 'weekend', 'ph' => 'holiday', 'leave' => 'leave'];

    private const OVERTIME_DAY = ['workday' => 'workday', 'weekend' => 'weekend', 'ph' => 'holiday'];

    /** Odoo's rule-category code → the category of an OPS payslip line. */
    private const CATEGORY = ['BASIC' => 'basic', 'ALW' => 'allowance', 'GROSS' => 'gross', 'DED' => 'deduction', 'NET' => 'net', 'COMP' => 'company'];

    /** The OPS component that does the work of an Odoo salary rule, by the rule's code (lower-cased). */
    private const COMPONENT_OF = [
        'basic' => 'BASIC', 'houallow' => 'HOUSING', 'houseallowance' => 'HOUSING', 'traallow' => 'TRANSPORT', 'transport' => 'TRANSPORT', 'otallow' => 'OTHER',
        'ot' => 'OVERTIME', 'ovt' => 'OVERTIME', 'unpaid' => 'UNPAID', 'abs' => 'ABSENCE', 'late' => 'LATE', 'diff' => 'EARLY', 'sic' => 'GOSI_ER',
        'reimbursement' => 'REIMB', 'deduction' => 'DEDUCTION', 'loans' => 'LOAN', 'employeepenalty' => 'PENALTY',
    ];

    /** What an attendance sheet of the module holds beside the attendance itself: kept as it is, when it holds something. */
    private const SHEET_EXTRA = ['house_allowances', 'overtime_allowance', 'treatment_allowance', 'transport_allowances', 'living_allowances', 'nature_of_work_allowances', 'telephone_allowance', 'bonus_request',
        'Regular_bonus_for_managers', 'Regular_regularity_equivalent', 'Incentive_bonus', 'motivation', 'profit_account', 'general_deductions', 'social_insurance_deductions', 'medical_insurance_deductions',
        'fingerprint_deductions', 'administrative_deductions', 'absence_without_permission_deductions', 'profit_tax_percent', 'penalty', 'loans'];

    /** Arabic for the names Odoo ships in English. The English name stays Odoo's; a name people typed is kept as it is. */
    private const ARABIC = [
        'paid time off' => 'إجازة مدفوعة', 'sick time off' => 'إجازة مرضية', 'unpaid' => 'إجازة غير مدفوعة', 'compensatory days' => 'أيام تعويضية', 'extra hours' => 'ساعات إضافية', 'annual leave' => 'إجازة سنوية',
        'experience' => 'خبرة عملية', 'education' => 'تعليم', 'languages' => 'اللغات', 'standard 40 hours/week' => 'دوام قياسي — 40 ساعة أسبوعيًا',
        'deduction' => 'خصم', 'reimbursement' => 'تعويض', 'attachment of salary' => 'حجز على الراتب', 'assignment of salary' => 'تنازل عن جزء من الراتب', 'child support' => 'نفقة أطفال',
        'regular pay' => 'الراتب الاعتيادي', 'worker pay' => 'راتب العمال', 'saudi employees payroll structure' => 'هيكل رواتب الموظفين السعوديين', 'expat employees payroll structure' => 'هيكل رواتب الموظفين غير السعوديين',
        'attendance sheet salary structure' => 'هيكل راتب كشف الحضور', 'default structure rules set' => 'مجموعة القواعد الافتراضية',
    ];

    /** The section's tables exist (a plan may run before the migration). */
    private bool $installed = true;

    /** @var array<string, array<string, ?Model>> class → external_ref → the OPS row, as met in this pass */
    private array $rows = [];

    /** @var array<string, array<string, true>> class → external_ref a plan would create */
    private array $planned = [];

    /** @var array<int, int>|null Odoo employee id → the Odoo id of the contract that says how they are paid now */
    private ?array $currentContract = null;

    /** @var array<int, ?string> Odoo rule-category id → its code */
    private array $categoryCodes = [];

    /** @var array<int, true>|null Odoo structure ids that carry a social-insurance rule */
    private ?array $insured = null;

    /** @var array<string, ?string> OPS component code → id */
    private array $components = [];

    public function __construct(ExternalRefs $refs, private readonly AuditService $audit, private readonly LeaveLedger $ledger, private readonly WorkCalendar $calendar)
    {
        parent::__construct($refs);
    }

    public function key(): string
    {
        return Owned::AREA;
    }

    /** The core models, and — each at its place — the optional ones the copy holds today. */
    public function models(): array
    {
        $held = OdooModel::whereIn('model', array_keys(self::OPTIONAL))->where('status', 'active')->pluck('model')->flip();
        $out = [];
        foreach (self::CORE as $model) {
            foreach (self::OPTIONAL as $optional => $before) {
                if ($before === $model && isset($held[$optional])) {
                    $out[] = $optional;
                }
            }
            $out[] = $model;
        }

        return $out;
    }

    /** After the hand-over the section is OPS's: loading it from the copy again would put Odoo's values over what people changed here. */
    public function apply(): array
    {
        if (! $this->enabled()) {
            throw AppError::rule('ODOO_AREA_HANDED_OVER', 'قسم الرواتب والإجازات والحضور سُلِّم لنظام العمليات (ODOO_PROJECT_PAYROLL متوقف) — إعادة تحميله من أودو تكتب قيم أودو فوق ما عُدِّل هنا',
                'Payroll, time off & attendance was handed over to OPS (ODOO_PROJECT_PAYROLL is off) — loading it from Odoo again would overwrite what was changed here');
        }

        return parent::apply();
    }

    public function consume(int $max = 500, float $seconds = 8.0): array
    {
        return $this->enabled() ? parent::consume($max, $seconds) : ['changes' => 0, 'counts' => []];
    }

    protected function begin(): void
    {
        $this->installed = Schema::hasTable('pay_runs');
        if (! $this->installed && ! $this->dry) {
            throw AppError::rule('PAYROLL_NOT_INSTALLED', 'جداول قسم الرواتب لم تُنشأ بعد — شغّل الترحيل أولًا (php artisan migrate)', 'The tables of the payroll section do not exist yet — run the migration first');
        }
        $this->rows = $this->planned = $this->categoryCodes = $this->components = [];
        $this->currentContract = $this->insured = null;
        if (! $this->dry) {
            $this->actor(); // the service user exists before the first record's transaction, so a record that fails cannot take it back
        }
    }

    protected function project(OdooRecord $r): void
    {
        $work = fn () => match ($r->model) {
            'resource.calendar' => $this->schedule($r), 'resource.calendar.attendance' => $this->scheduleDay($r), 'resource.calendar.leaves' => $this->calendarLeave($r), 'hr.public.holiday' => $this->publicHoliday($r),
            'hr.overtime.rule' => $this->overtimeRule($r), 'hr.late.rule' => $this->rule($r, 'late'), 'hr.diff.rule' => $this->rule($r, 'early'), 'hr.absence.rule' => $this->rule($r, 'absence'),
            'hr.late.rule.line' => $this->ruleLine($r, 'late', 'late_id'), 'hr.diff.rule.line' => $this->ruleLine($r, 'early', 'diff_id'), 'hr.absence.rule.line' => $this->ruleLine($r, 'absence', 'absence_id'),
            'hr.attendance.policy' => $this->policy($r),
            'hr.payroll.structure' => $this->structure($r), 'hr.salary.rule' => $this->salaryRule($r), 'hr.payslip.input.type' => $this->inputType($r), 'hr.rule.parameter.value' => $this->parameter($r),
            'hr.leave.type' => $this->leaveType($r), 'hr.contract' => $this->profile($r), 'hr.leave.allocation' => $this->allocation($r), 'hr.leave' => $this->leave($r), 'hr.attendance' => $this->attendance($r),
            'hr.payslip.run' => $this->run($r), 'hr.payslip' => $this->payslip($r), 'hr.payslip.line' => $this->payslipLine($r), 'hr.payslip.worked_days' => $this->workedDays($r), 'hr.payslip.input' => $this->payslipInput($r),
            'attendance.sheet.batch' => $this->sheetBatch($r), 'attendance.sheet' => $this->sheet($r), 'attendance.sheet.line' => $this->sheetLine($r),
            'hr.skill.type' => $this->named('skillTypes', SkillType::class, $r), 'hr.skill' => $this->skill($r), 'hr.skill.level' => $this->skillLevel($r), 'hr.employee.skill' => $this->employeeSkill($r),
            'hr.resume.line.type' => $this->named('resumeTypes', ResumeType::class, $r), 'hr.resume.line' => $this->resumeLine($r),
            default => null,
        };
        try {
            $this->dry ? $work() : DB::transaction($work); // one record is applied whole or not at all
        } catch (QueryException $e) {
            throw new RuntimeException("the database refused the record (SQLSTATE {$e->getCode()})"); // never the statement: it carries a person's values
        }
    }

    // ───────────────────────────── work time ─────────────────────────────

    private function schedule(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $values = self::names($r) + ['timezone' => in_array($d['tz'] ?? null, timezone_identifiers_list(), true) ? $d['tz'] : (string) config('app.business_timezone', 'Asia/Riyadh'),
            'hours_per_day' => round(Val::float($d['hours_per_day'] ?? 0) ?: 8.0, 2), 'active' => $this->alive($r)];
        // the first schedule Odoo brings is the company's, unless OPS already chose a default
        $this->upsert('schedules', Schedule::class, (string) $r->odoo_id, $values, fn () => ['is_default' => $this->alive($r) && ! Schedule::where('is_default', true)->where('active', true)->exists()], $r, 'WorkSchedule');
    }

    private function scheduleDay(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $this->drop('scheduleDays', ScheduleDay::class, $ref);

            return;
        }
        $scheduleId = $this->ref(Schedule::class, self::key1(Val::id($d['calendar_id'] ?? null)));
        if (! $scheduleId) {
            $this->count('scheduleDays.skippedScheduleNotCopied', "resource.calendar.attendance #{$ref}");

            return;
        }
        if (! empty($d['display_type']) || ! empty($d['week_type']) || ! empty($d['resource_id']) || ! is_numeric($d['dayofweek'] ?? null)) {
            $this->count('scheduleDays.skippedNotWeekly'); // a title row, a two-week rota, or one person's own hours: OPS schedules are one week for everyone on them

            return;
        }
        $from = (int) round(Val::float($d['hour_from'] ?? 0) * 60);
        $to = (int) round(Val::float($d['hour_to'] ?? 0) * 60);
        if ($to <= $from) {
            $this->count('scheduleDays.skippedNoDuration', "resource.calendar.attendance #{$ref}");

            return;
        }
        $this->upsert('scheduleDays', ScheduleDay::class, $ref, ['schedule_id' => $scheduleId, 'weekday' => max(0, min(6, (int) $d['dayofweek'])), 'kind' => ($d['day_period'] ?? '') === 'lunch' ? 'break' : 'work',
            'name' => Val::text($d['name'] ?? null, 120), 'from_minute' => $from, 'to_minute' => $to, 'sequence' => Val::int($d['sequence'] ?? 0)]);
    }

    /** A day off of the whole calendar is a public holiday; one that belongs to a person is their leave, which comes as `hr.leave`. */
    private function calendarLeave(OdooRecord $r): void
    {
        $d = (array) $r->data;
        if (Val::id($d['resource_id'] ?? null) !== null) {
            $this->count('holidays.skippedPersonal');

            return;
        }
        $tz = (string) config('app.business_timezone', 'Asia/Riyadh');
        $from = Val::at($d['date_from'] ?? null)?->setTimezone($tz)->format('Y-m-d');
        $to = Val::at($d['date_to'] ?? null)?->setTimezone($tz)->format('Y-m-d');
        if (! $from || ! $to) {
            $this->count('holidays.skippedNoDates', "resource.calendar.leaves #{$r->odoo_id}");

            return;
        }
        $this->upsert('holidays', Holiday::class, "cal:{$r->odoo_id}", self::names($r) + ['date_from' => $from, 'date_to' => max($from, $to), 'scope' => 'company', 'scope_ids' => null, 'active' => $r->deleted_at === null], null, $r, 'PublicHoliday');
    }

    private function publicHoliday(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $from = Val::date($d['date_from'] ?? null);
        $to = Val::date($d['date_to'] ?? null) ?? $from;
        if (! $from) {
            $this->count('holidays.skippedNoDates', "hr.public.holiday #{$r->odoo_id}");

            return;
        }
        [$scope, $ids] = match ($d['type_select'] ?? 'company') {
            'emp' => ['employee', array_values(array_filter(array_map(fn (int $id) => $this->linked('hr_employee', $id), Val::ids($d['emp_ids'] ?? []))))],
            'dep' => ['department', array_values(array_filter(array_map(fn (int $id) => $this->linked('hr_department', $id), Val::ids($d['dep_ids'] ?? []))))],
            'tag' => ['tag', []],
            default => ['company', []],
        };
        if ($scope === 'tag') {
            $this->count('holidays.skippedByTag', "hr.public.holiday #{$r->odoo_id}"); // OPS has no employee tags: a person enters it for the people it is for

            return;
        }
        sort($ids);
        $this->upsert('holidays', Holiday::class, "ph:{$r->odoo_id}", self::names($r) + ['date_from' => $from, 'date_to' => max($from, $to), 'scope' => $scope, 'scope_ids' => $ids ?: null,
            // a holiday for named people or departments none of whom OPS knows is for nobody: it must not become everybody's
            'active' => $r->deleted_at === null && ($d['state'] ?? 'active') === 'active' && ($scope === 'company' || $ids !== [])], null, $r, 'PublicHoliday');
    }

    // ───────────────────────────── attendance policies ─────────────────────────────

    private function overtimeRule(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $this->upsert('policyRules', AttendanceRule::class, "ot:{$r->odoo_id}", self::names($r) + ['kind' => 'overtime', 'day_type' => self::OVERTIME_DAY[is_string($d['type'] ?? null) ? $d['type'] : ''] ?? 'workday',
            'active_after_minutes' => (int) round(Val::float($d['active_after'] ?? 0) * 60), 'rate' => round(Val::float($d['rate'] ?? 0), 4), 'active' => $this->alive($r)]);
    }

    private function rule(OdooRecord $r, string $kind): void
    {
        $prefix = ['late' => 'late', 'early' => 'diff', 'absence' => 'abs'][$kind];
        $this->upsert('policyRules', AttendanceRule::class, "{$prefix}:{$r->odoo_id}", self::names($r) + ['kind' => $kind, 'active' => $this->alive($r)]);
    }

    private function ruleLine(OdooRecord $r, string $kind, string $parentField): void
    {
        $d = (array) $r->data;
        $prefix = ['late' => 'late', 'early' => 'diff', 'absence' => 'abs'][$kind];
        $ref = "{$prefix}:{$r->odoo_id}";
        if ($r->deleted_at !== null) {
            $this->drop('policyRuleLines', AttendanceRuleLine::class, $ref);

            return;
        }
        $parent = Val::id($d[$parentField] ?? null);
        $ruleId = $this->ref(AttendanceRule::class, $parent ? "{$prefix}:{$parent}" : null);
        if (! $ruleId) {
            $this->count('policyRuleLines.skippedRuleNotCopied', "{$r->model} #{$r->odoo_id}");

            return;
        }
        if ($kind === 'absence') {
            $counter = max(1, Val::int($d['counter'] ?? 1));
            $values = ['rule_id' => $ruleId, 'sequence' => $counter * 10, 'counter' => $counter, 'rate' => round(Val::float($d['rate'] ?? 0), 4)];
        } else {
            $weight = fn (string $key) => ($w = Val::float($d[$key] ?? 0)) > 0 ? round($w, 4) : null;
            $values = ['rule_id' => $ruleId, 'sequence' => (int) round(Val::float($d['time'] ?? 0) * 60), 'type' => ($d['type'] ?? 'rate') === 'fix' ? 'fix' : 'rate', 'threshold_minutes' => (int) round(Val::float($d['time'] ?? 0) * 60),
                'rate' => round(Val::float($d['rate'] ?? 0), 4), 'amount_minutes' => (int) round(Val::float($d['amount'] ?? 0) * 60),
                'first' => $weight('first'), 'second' => $weight('second'), 'third' => $weight('third'), 'fourth' => $weight('fourth'), 'fifth' => $weight('fifth')];
        }
        $this->upsert('policyRuleLines', AttendanceRuleLine::class, $ref, $values);
    }

    private function policy(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $rule = fn (string $prefix, string $field) => ($id = Val::id($d[$field] ?? null)) ? $this->ref(AttendanceRule::class, "{$prefix}:{$id}") : null;
        $values = self::names($r) + ['late_rule_id' => $rule('late', 'late_rule_id'), 'early_rule_id' => $rule('diff', 'diff_rule_id'), 'absence_rule_id' => $rule('abs', 'absence_rule_id'),
            'incomplete' => false, 'active' => $this->alive($r)];
        $policy = $this->upsert('policies', AttendancePolicy::class, (string) $r->odoo_id, $values, null, $r, 'AttendancePolicy');
        if (! $policy || $this->dry) {
            return;
        }
        $overtime = array_values(array_filter(array_map(fn (int $id) => $this->ref(AttendanceRule::class, "ot:{$id}"), Val::ids($d['overtime_rule_ids'] ?? []))));
        sort($overtime);
        $have = DB::table('pay_policy_overtime_rules')->where('policy_id', $policy->id)->orderBy('rule_id')->pluck('rule_id')->map(fn ($id) => (string) $id)->all();
        if ($have !== $overtime) {
            $policy->overtimeRules()->sync($overtime);
            $this->count('policies.overtimeRulesSet');
        }
    }

    /**
     * The policy a contract names, when the copy does not hold the policy itself: kept by its name, marked
     * `incomplete` — it decides nothing (the default policy stands in) until its rules arrive or a person writes them.
     */
    private function policyByName(mixed $many2one): ?string
    {
        $id = Val::id($many2one);
        if (! $id) {
            return null;
        }
        if ($known = $this->ref(AttendancePolicy::class, (string) $id)) {
            return $known;
        }
        $label = Val::label($many2one) ?? "Odoo attendance policy #{$id}";
        $this->count('policies.knownByNameOnly', "hr.attendance.policy #{$id}");
        if ($this->dry) {
            $this->planned[AttendancePolicy::class][(string) $id] = true;

            return "new:{$id}";
        }
        $row = AttendancePolicy::create(['name_ar' => self::cut($label, 190), 'name_en' => self::cut($label, 190), 'incomplete' => true, 'active' => true, 'source_system' => self::SYSTEM, 'external_ref' => (string) $id,
            'notes' => 'قواعد هذه السياسة لم تُقرأ من أودو (لا صلاحية قراءة لمستخدم الربط) — تعمل السياسة الافتراضية بدلًا منها إلى أن تصل قواعدها أو تُكتب هنا', 'created_by' => 'svc.odoo']);
        $this->rows[AttendancePolicy::class][(string) $id] = $row;

        return $row->id;
    }

    // ───────────────────────────── pay structures, rules, inputs, parameters ─────────────────────────────

    private function structure(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $values = self::names($r) + ['type_label' => self::cut(Val::label($d['type_id'] ?? null), 190), 'country' => self::cut(Val::label($d['country_id'] ?? null), 120), 'active' => $this->alive($r)];
        $this->upsert('structures', Structure::class, (string) $r->odoo_id, $values, function () use ($r) {
            // a structure that deducts social insurance in Odoo starts under the Saudi scheme when OPS has its rates; a person confirms it
            $scheme = $this->insuredStructure($r->odoo_id) && $this->installed && GosiRate::where('scheme', 'saudi')->exists() ? 'saudi' : null;
            if ($scheme) {
                $this->count('structures.gosiSchemeProposed', "hr.payroll.structure #{$r->odoo_id}");
            }

            return ['code' => "OD-{$r->odoo_id}", 'gosi_scheme' => $scheme];
        }, $r, 'PayStructure');
    }

    private function insuredStructure(int $odooId): bool
    {
        if ($this->insured === null) {
            $this->insured = [];
            foreach (OdooRecord::where('model', 'hr.salary.rule')->whereNull('deleted_at')->get(['odoo_id', 'data']) as $rule) {
                if (mb_strtolower((string) ($rule->data['code'] ?? '')) === 'sic' && ($struct = Val::id($rule->data['struct_id'] ?? null))) {
                    $this->insured[$struct] = true;
                }
            }
        }

        return isset($this->insured[$odooId]);
    }

    private function salaryRule(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $structureId = $this->ref(Structure::class, self::key1(Val::id($d['struct_id'] ?? null)));
        if (! $structureId) {
            $this->count('rules.skippedStructureNotCopied', "hr.salary.rule #{$ref}");

            return;
        }
        $code = self::cut(Val::text($d['code'] ?? null) ?? "RULE-{$ref}", 60);
        $formula = match ($d['amount_select'] ?? 'code') {
            'fix' => 'fix: '.Val::float($d['amount_fix'] ?? 0),
            'percentage' => Val::float($d['amount_percentage'] ?? 0).'% of '.(Val::text($d['amount_percentage_base'] ?? null) ?? '?'),
            default => Val::text($d['amount_python_compute'] ?? null, 20000),
        };
        $condition = is_string($d['condition_select'] ?? null) ? $d['condition_select'] : 'none';
        $this->upsert('rules', StructureRule::class, $ref, ['structure_id' => $structureId, 'sequence' => Val::int($d['sequence'] ?? 0), 'code' => $code, 'name' => self::cut(Val::text($d['name'] ?? null) ?? $code, 250),
            'category_code' => $this->categoryCode(Val::id($d['category_id'] ?? null)), 'condition' => self::cut($condition, 10),
            'condition_text' => match ($condition) {
                'python' => Val::text($d['condition_python'] ?? null, 20000),
                'range' => (Val::text($d['condition_range'] ?? null) ?? '?').' in '.Val::float($d['condition_range_min'] ?? 0).' .. '.Val::float($d['condition_range_max'] ?? 0),
                default => null,
            },
            'formula' => $formula, 'quantity' => Val::text($d['quantity'] ?? null, 120), 'appears_on_payslip' => array_key_exists('appears_on_payslip', $d) ? Val::bool($d['appears_on_payslip']) : true,
            'component_id' => $this->componentFor($code), 'active' => $this->alive($r)]);
    }

    /** A kind of one-off input of a payslip is a pay component people add through an adjustment. */
    private function inputType(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $code = mb_strtoupper(trim((string) preg_replace('/[^A-Za-z0-9_.\-]+/', '_', (string) (Val::text($d['code'] ?? null) ?? "INPUT_{$ref}")), '_'));
        $existing = $this->find(Component::class, $ref);
        if (! $existing && $r->deleted_at !== null) {
            $this->count('components.skippedDeleted');

            return;
        }
        if (! $existing && $this->installed && ($twin = Component::where('code', $code)->first())) {
            if ($twin->source_system !== null || $twin->calc !== 'input') {
                // the code is the engine's own (BASIC …) or another input's: nothing is taken over, nothing is doubled
                $this->count('components.skippedCodeTaken', "hr.payslip.input.type #{$ref}");

                return;
            }
            // OPS has an input component of exactly this code: the same thing, adopted — it keeps its own wording and its account
            $this->count('components.linked');
            if (! $this->dry) {
                $twin->update(['source_system' => self::SYSTEM, 'external_ref' => $ref]);
                $this->rows[Component::class][$ref] = $twin;
            }

            return;
        }
        if ($existing && $existing->created_by !== 'svc.odoo') {
            $this->count('components.unchanged'); // an adopted component is OPS's in everything but the link

            return;
        }
        // whether it adds or deducts is what the salary rule of the same code says in Odoo
        $adds = OdooRecord::where('model', 'hr.salary.rule')->whereNull('deleted_at')->get(['data'])->contains(fn ($rule) => mb_strtoupper((string) ($rule->data['code'] ?? '')) === $code
            && $this->categoryCode(Val::id($rule->data['category_id'] ?? null)) === 'ALW');
        $this->upsert('components', Component::class, $ref, self::names($r) + ['active' => $this->alive($r)], fn () => ['code' => self::cut($code, 40), 'category' => $adds ? 'allowance' : 'deduction', 'calc' => 'input',
            'gosi_base' => false, 'sequence' => $adds ? 66 : 186, 'account_key' => $adds ? 'allowances' : 'deductions', 'system' => false, 'created_by' => 'svc.odoo'], $r, 'PayComponent');
    }

    private function parameter(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $this->drop('parameters', Parameter::class, $ref);

            return;
        }
        $parent = $this->mirrored('hr.rule.parameter', Val::id($d['rule_parameter_id'] ?? null));
        $code = Val::text($d['code'] ?? null, 60) ?? Val::text($parent?->data['code'] ?? null, 60);
        $from = Val::date($d['date_from'] ?? null);
        if ($code === null || $from === null) {
            $this->count('parameters.skippedIncomplete', "hr.rule.parameter.value #{$ref}");

            return;
        }
        if (! $this->find(Parameter::class, $ref) && $this->installed && Parameter::where('code', $code)->whereDate('effective_from', $from)->exists()) {
            $this->count('parameters.skippedSameCodeAndDate', "hr.rule.parameter.value #{$ref}");

            return;
        }
        $this->upsert('parameters', Parameter::class, $ref, ['code' => $code, 'name' => self::cut(Val::text($parent?->data['name'] ?? null) ?? $code, 190), 'value' => self::cut(Val::text($d['parameter_value'] ?? null, 190) ?? '', 190), 'effective_from' => $from]);
    }

    // ───────────────────────────── how each person is paid ─────────────────────────────

    /** The contract that is in force for an employee in Odoo says their pay structure, schedule and attendance policy. */
    private function profile(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $odooEmployee = Val::id($d['employee_id'] ?? null);
        if (! $odooEmployee) {
            return;
        }
        if (($this->currentContracts()[$odooEmployee] ?? null) !== $r->odoo_id) {
            $this->count('profiles.skippedNotCurrentContract');

            return;
        }
        $employeeId = $this->linked('hr_employee', $odooEmployee);
        if (! $employeeId) {
            $this->count('profiles.skippedEmployeeNotCopied', "hr.contract #{$r->odoo_id}");

            return;
        }
        $type = $this->mirrored('hr.payroll.structure.type', Val::id($d['structure_type_id'] ?? null));
        $values = [
            'structure_id' => $this->ref(Structure::class, self::key1(Val::id($type?->data['default_struct_id'] ?? null))),
            'schedule_id' => $this->ref(Schedule::class, self::key1(Val::id($d['resource_calendar_id'] ?? null))),
            'policy_id' => $this->policyByName($d['att_policy_id'] ?? null),
        ];
        $profile = $this->installed ? Profile::where('employee_id', $employeeId)->first() : null;
        if (! $profile) {
            $this->count('profiles.created');
            if (! $this->dry) {
                Profile::create($values + ['employee_id' => $employeeId, 'payment_method' => 'bank', 'source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id, 'created_by' => 'svc.odoo']);
            }

            return;
        }
        // a profile is one per employee: the one a person made here takes Odoo's structure, schedule and policy and keeps the rest
        $changes = self::changes($profile, $values + ['source_system' => self::SYSTEM, 'external_ref' => (string) $r->odoo_id]);
        $this->count($changes ? 'profiles.updated' : 'profiles.unchanged');
        if ($changes && ! $this->dry) {
            $profile->update($changes);
        }
    }

    /** @return array<int, int> Odoo employee id → the Odoo contract that decides: running and not archived, the latest start */
    private function currentContracts(): array
    {
        if ($this->currentContract === null) {
            $best = [];
            foreach (OdooRecord::where('model', 'hr.contract')->whereNull('deleted_at')->get(['odoo_id', 'active', 'data']) as $c) {
                $employee = Val::id($c->data['employee_id'] ?? null);
                if (! $employee || ($c->data['state'] ?? '') !== 'open' || $c->active === false) {
                    continue;
                }
                $key = (Val::date($c->data['date_start'] ?? null) ?? '0000-00-00').sprintf('%012d', $c->odoo_id);
                if (! isset($best[$employee]) || $key > $best[$employee][0]) {
                    $best[$employee] = [$key, $c->odoo_id];
                }
            }
            $this->currentContract = array_map(fn (array $b) => $b[1], $best);
        }

        return $this->currentContract;
    }

    // ───────────────────────────── time off ─────────────────────────────

    private function leaveType(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        $names = self::names($r);
        $unit = is_string($d['request_unit'] ?? null) ? $d['request_unit'] : 'day';
        $values = $names + ['paid' => ! Val::bool($d['unpaid'] ?? false), 'requires_allocation' => ($d['requires_allocation'] ?? 'no') === 'yes',
            'approval' => self::LEAVE_APPROVAL[is_string($d['leave_validation_type'] ?? null) ? $d['leave_validation_type'] : ''] ?? 'hr', 'requires_document' => Val::bool($d['support_document'] ?? false),
            'unit' => $unit === 'hour' ? 'hour' : 'day', 'color' => max(0, min(LeaveType::COLORS - 1, Val::int($d['color'] ?? 0))), 'sequence' => Val::int($d['sequence'] ?? 0), 'active' => $this->alive($r)];
        if (! $this->find(LeaveType::class, $ref) && $r->deleted_at === null && $this->installed) {
            $twin = LeaveType::whereNull('source_system')->where(fn ($w) => $w->where('name_en', $names['name_en'])->orWhere('name_ar', $names['name_ar']))->orderBy('created_at')->first();
            if ($twin) { // OPS already has this kind of time off under the same name: adopted, never doubled
                $this->count('leaveTypes.linked');
                if (! $this->dry) {
                    $twin->update($values + ['source_system' => self::SYSTEM, 'external_ref' => $ref]);
                    $this->rows[LeaveType::class][$ref] = $twin;
                }

                return;
            }
        }
        $this->upsert('leaveTypes', LeaveType::class, $ref, $values, fn () => ['code' => "OD-LT-{$ref}", 'allow_half_day' => true, 'allow_negative' => Val::bool($d['allows_negative'] ?? false)], $r, 'LeaveType');
    }

    /**
     * An allocation Odoo approved is days in the ledger. The ledger is only ever added to: what Odoo holds for the
     * allocation NOW (its days while it is approved, nothing otherwise) is compared with what the ledger holds for it,
     * and the difference becomes a new line.
     */
    private function allocation(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if (($d['holiday_type'] ?? 'employee') !== 'employee') {
            $this->count('allocations.skippedNotPersonal', "hr.leave.allocation #{$ref}");

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $typeId = $this->ref(LeaveType::class, self::key1(Val::id($d['holiday_status_id'] ?? null)));
        if (! $employeeId || ! $typeId) {
            $this->count($employeeId ? 'allocations.skippedTypeNotCopied' : 'allocations.skippedEmployeeNotCopied', "hr.leave.allocation #{$ref}");

            return;
        }
        $target = $r->deleted_at === null && $r->active !== false && ($d['state'] ?? '') === 'validate' ? round(Val::float($d['number_of_days'] ?? 0), 2) : 0.0;
        $held = $this->installed ? round((float) LeaveEntry::where('source_system', self::SYSTEM)->where(fn ($w) => $w->where('external_ref', $ref)->orWhere('external_ref', 'like', "{$ref}:%"))->sum('days'), 2) : 0.0;
        $difference = round($target - $held, 2);
        if (abs($difference) < 0.005) {
            $this->count($held == 0.0 ? 'allocations.skippedNotApproved' : 'allocations.unchanged');

            return;
        }
        $this->count($held == 0.0 ? 'allocations.created' : 'allocations.corrected');
        if ($this->dry) {
            return;
        }
        $first = ! LeaveEntry::where('source_system', self::SYSTEM)->where('external_ref', $ref)->exists();
        LeaveEntry::create(['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'year' => (int) substr(Val::date($d['date_from'] ?? null) ?? Val::date($d['create_date'] ?? null) ?? now()->toDateString(), 0, 4),
            'kind' => $first ? 'allocation' : 'adjustment', 'days' => $difference, 'note' => $first ? "Odoo allocation #{$ref}" : "Odoo allocation #{$ref} changed", 'created_by' => 'svc.odoo',
            'source_system' => self::SYSTEM, 'external_ref' => $first ? $ref : "{$ref}:{$r->version}"]);
    }

    private function leave(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        /** @var ?Leave $existing */
        $existing = $this->find(Leave::class, $ref);
        if (! $existing && $r->deleted_at !== null) {
            $this->count('leaves.skippedDeleted');

            return;
        }
        if (($d['holiday_type'] ?? 'employee') !== 'employee') {
            $this->count('leaves.skippedNotPersonal', "hr.leave #{$ref}");

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $typeId = $this->ref(LeaveType::class, self::key1(Val::id($d['holiday_status_id'] ?? null)));
        if (! $employeeId || ! $typeId) {
            $this->count($employeeId ? 'leaves.skippedTypeNotCopied' : 'leaves.skippedEmployeeNotCopied', "hr.leave #{$ref}");

            return;
        }
        $tz = (string) config('app.business_timezone', 'Asia/Riyadh');
        $from = Val::date($d['request_date_from'] ?? null) ?? Val::at($d['date_from'] ?? null)?->setTimezone($tz)->format('Y-m-d');
        $to = Val::date($d['request_date_to'] ?? null) ?? Val::at($d['date_to'] ?? null)?->setTimezone($tz)->format('Y-m-d') ?? $from;
        if (! $from) {
            $this->count('leaves.skippedNoDates', "hr.leave #{$ref}");

            return;
        }
        $to = max($from, (string) $to);
        $half = Val::bool($d['request_unit_half'] ?? false) && $from === $to;
        $days = round(Val::float($d['number_of_days'] ?? 0), 2);
        if ($days <= 0 && ! $this->dry && ($employee = Employee::find($employeeId))) {
            // Odoo holds no day count for it: the days are counted on the person's schedule, as for a request made here
            $days = $this->calendar->leaveDays($employee, $from, $to, $half)['days'];
            $this->count('leaves.daysCounted');
        }
        $state = $r->deleted_at !== null || $r->active === false ? 'cancelled' : (self::LEAVE_STATE[is_string($d['state'] ?? null) ? $d['state'] : ''] ?? 'draft');
        $values = ['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'date_from' => $from, 'date_to' => $to, 'half_day' => $half, 'days' => $days, 'year' => (int) substr($from, 0, 4), 'state' => $state,
            'reason' => Val::text($d['private_name'] ?? null, 2000) ?? Val::text($d['notes'] ?? null, 2000), 'source_state' => self::cut(is_string($d['state'] ?? null) ? $d['state'] : null, 20)]
            + (($departmentId = $this->linked('hr_department', Val::id($d['department_id'] ?? null))) ? ['department_id' => $departmentId] : []);
        if ($this->dry && $days <= 0) {
            unset($values['days']); // a plan does not count days: what OPS holds is not called a change for it
        }
        /** @var ?Leave $leave */
        $leave = $this->upsert('leaves', Leave::class, $ref, $values, fn () => ['number' => "OD-LV-{$ref}", 'created_by' => 'svc.odoo', 'submitted_at' => Val::at($d['create_date'] ?? null),
            'created_at' => (Val::at($d['create_date'] ?? null) ?? now())->format('Y-m-d H:i:s.v')], $r, 'Leave');
        if (! $leave || $this->dry) {
            return;
        }
        // the ledger follows the leave: an approved leave has taken exactly its days, any other holds none
        $target = $leave->state === 'approved' ? round(-1 * (float) $leave->days, 2) : 0.0;
        if (abs($this->ledger->netOf($leave->id) - $target) > 0.001) {
            $this->ledger->restore($leave, 'svc.odoo', self::SYSTEM);
            if ($target != 0.0) {
                $this->ledger->take($leave, 'svc.odoo', self::SYSTEM);
            }
            $this->count('leaves.ledgerSet');
        }
    }

    // ───────────────────────────── attendance ─────────────────────────────

    private function attendance(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        /** @var ?Attendance $existing */
        $existing = $this->find(Attendance::class, $ref);
        if ($r->deleted_at !== null) {
            if ($existing && $existing->status !== 'void') {
                $this->count('attendance.voided');
                if (! $this->dry) {
                    $existing->update(['status' => 'void', 'voided_by' => 'svc.odoo', 'voided_at' => now(), 'void_reason' => 'حُذف السجل من أودو']);
                }
            } elseif (! $existing) {
                $this->count('attendance.skippedDeleted');
            }

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $in = Val::at($d['check_in'] ?? null);
        if (! $employeeId || ! $in) {
            $this->count($employeeId ? 'attendance.skippedNoCheckIn' : 'attendance.skippedEmployeeNotCopied', "hr.attendance #{$ref}");

            return;
        }
        $out = Val::at($d['check_out'] ?? null);
        $schedule = $this->dry && ! $this->installed ? null : $this->calendar->scheduleOf($employeeId);
        $this->upsert('attendance', Attendance::class, $ref, ['employee_id' => $employeeId, 'work_date' => $this->calendar->dateOf($schedule, $in), 'check_in' => $in, 'check_out' => $out,
            'worked_minutes' => $out ? max(0, intdiv($out->getTimestamp() - $in->getTimestamp(), 60)) : 0, 'in_mode' => self::cut(Val::text($d['in_mode'] ?? null) ?? 'import', 10),
            'out_mode' => $out ? self::cut(Val::text($d['out_mode'] ?? null) ?? 'import', 10) : null, 'status' => 'ok'], fn () => ['created_by' => 'svc.odoo'], $r, 'Attendance');
    }

    private function sheetBatch(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null && ! $this->find(SheetBatch::class, $ref)) {
            $this->count('sheetBatches.skippedDeleted');

            return;
        }
        $from = Val::date($d['date_from'] ?? null);
        if (! $from) {
            $this->count('sheetBatches.skippedNoDates', "attendance.sheet.batch #{$ref}");

            return;
        }
        $this->upsert('sheetBatches', SheetBatch::class, $ref, ['name' => Val::text($d['name'] ?? null, 190), 'department_id' => $this->linked('hr_department', Val::id($d['department_id'] ?? null)), 'date_from' => $from,
            'date_to' => max($from, Val::date($d['date_to'] ?? null) ?? $from), 'state' => self::BATCH_STATE[is_string($d['state'] ?? null) ? $d['state'] : ''] ?? 'draft',
            'source_run_ref' => self::key1(Val::id($d['payslip_batch_id'] ?? null))], fn () => ['number' => "OD-AB-{$ref}", 'created_by' => 'svc.odoo'], $r, 'AttendanceSheetBatch');
    }

    private function sheet(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null && ! $this->find(Sheet::class, $ref)) {
            $this->count('sheets.skippedDeleted');

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $from = Val::date($d['date_from'] ?? null);
        if (! $employeeId || ! $from) {
            $this->count($employeeId ? 'sheets.skippedNoDates' : 'sheets.skippedEmployeeNotCopied', "attendance.sheet #{$ref}");

            return;
        }
        $to = max($from, Val::date($d['date_to'] ?? null) ?? $from);
        $minutes = fn (string $key) => (int) round(Val::float($d[$key] ?? 0) * 60);
        $extra = [];
        foreach (self::SHEET_EXTRA as $key) {
            if (Val::float($d[$key] ?? 0) != 0.0) {
                $extra[$key] = round(Val::float($d[$key]), 2);
            }
        }
        $values = ['employee_id' => $employeeId, 'batch_id' => $this->ref(SheetBatch::class, self::key1(Val::id($d['batch_id'] ?? null))), 'contract_id' => $this->linked('hr_contract', Val::id($d['contract_id'] ?? null)),
            'policy_id' => $this->policyByName($d['att_policy_id'] ?? null), 'date_from' => $from, 'date_to' => $to, 'period' => substr($to, 0, 7),
            'state' => self::SHEET_STATE[is_string($d['state'] ?? null) ? $d['state'] : ''] ?? 'draft', 'source_state' => self::cut(is_string($d['state'] ?? null) ? $d['state'] : null, 20),
            // the module's totals are the figures AFTER its policy, in hours
            'overtime_count' => Val::int($d['no_overtime'] ?? 0), 'overtime_minutes' => $minutes('tot_overtime'), 'late_count' => Val::int($d['no_late'] ?? 0), 'late_minutes' => $minutes('tot_late'),
            'early_count' => Val::int($d['no_difftime'] ?? 0), 'early_minutes' => $minutes('tot_difftime'), 'absence_count' => Val::int($d['no_absence'] ?? 0), 'absence_minutes' => $minutes('tot_absence'),
            'worked_minutes' => $minutes('tot_worked_hour'), 'extra' => $extra ?: null, 'payslip_id' => $this->ref(Payslip::class, self::key1(Val::id($d['payslip_id'] ?? null)))]
            + (($departmentId = $this->linked('hr_department', Val::id($d['department_id'] ?? null))) ? ['department_id' => $departmentId] : []);
        $this->upsert('sheets', Sheet::class, $ref, $values, fn () => ['number' => "OD-AS-{$ref}", 'created_by' => 'svc.odoo'], $r, 'AttendanceSheet');
    }

    private function sheetLine(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $sheetId = $this->find(SheetLine::class, $ref)?->sheet_id;
            $this->drop('sheetLines', SheetLine::class, $ref);
            if ($sheetId && ! $this->dry) {
                $this->sheetActuals($sheetId);
            }

            return;
        }
        $sheetId = $this->ref(Sheet::class, self::key1(Val::id($d['att_sheet_id'] ?? null)));
        $date = Val::date($d['date'] ?? null);
        if (! $sheetId || ! $date) {
            $this->count($sheetId ? 'sheetLines.skippedNoDate' : 'sheetLines.skippedSheetNotCopied', "attendance.sheet.line #{$ref}");

            return;
        }
        $clock = fn (string $key) => ($h = Val::float($d[$key] ?? 0)) > 0 ? (int) round($h * 60) : null;
        $minutes = fn (string $key) => max(0, (int) round(Val::float($d[$key] ?? 0) * 60));
        $status = self::LINE_STATUS[is_string($d['status'] ?? null) ? $d['status'] : ''] ?? null;
        [$plannedIn, $plannedOut] = [$clock('pl_sign_in'), $clock('pl_sign_out')];
        $absent = $status === 'absence';
        $values = ['sheet_id' => $sheetId, 'work_date' => $date, 'weekday' => is_numeric($d['day'] ?? null) ? max(0, min(6, (int) $d['day'])) : WorkCalendar::weekday($date),
            'planned_in' => $plannedIn, 'planned_out' => $plannedOut, 'actual_in' => $clock('ac_sign_in'), 'actual_out' => $clock('ac_sign_out'),
            'planned_minutes' => $plannedIn !== null && $plannedOut !== null ? max(0, $plannedOut - $plannedIn) : 0, 'worked_minutes' => $minutes('worked_hours'),
            'late_actual' => $minutes('act_late_in'), 'late' => $minutes('late_in'), 'overtime_actual' => $minutes('act_overtime'), 'overtime' => $minutes('overtime'),
            // the module keeps the hours of an absent day in its "difference time": here they are the day's absence, not an early leave
            'early_actual' => $absent ? 0 : $minutes('act_diff_time'), 'early' => $absent ? 0 : $minutes('diff_time'), 'absence_minutes' => $absent ? $minutes('diff_time') : 0,
            'status' => $status, 'note' => Val::text($d['note'] ?? null, 2000)];
        // one line per day: a day OPS already holds for this sheet under another id is the same day
        if (! $this->find(SheetLine::class, $ref) && $this->installed && ! str_starts_with($sheetId, 'new:') && ($same = SheetLine::where('sheet_id', $sheetId)->whereDate('work_date', $date)->first())) {
            $this->count('sheetLines.skippedSameDay', "attendance.sheet.line #{$ref}");

            return;
        }
        $line = $this->upsert('sheetLines', SheetLine::class, $ref, $values);
        if ($line && ! $this->dry) {
            $this->sheetActuals($sheetId);
        }
    }

    /** What OPS derives of a sheet from its lines and Odoo does not hold as a total: the planned time, the actual figures, the kinds of day. */
    private function sheetActuals(string $sheetId): void
    {
        $s = SheetLine::where('sheet_id', $sheetId)->selectRaw("coalesce(sum(planned_minutes), 0) as planned, coalesce(sum(late_actual), 0) as late, coalesce(sum(early_actual), 0) as early, coalesce(sum(overtime_actual), 0) as overtime,
            coalesce(sum(case when status = 'weekend' then 1 else 0 end), 0) as weekends, coalesce(sum(case when status = 'holiday' then 1 else 0 end), 0) as holidays, coalesce(sum(case when status = 'leave' then 1 else 0 end), 0) as leaves")->first();
        Sheet::whereKey($sheetId)->update(['planned_minutes' => (int) $s->planned, 'late_actual_minutes' => (int) $s->late, 'early_actual_minutes' => (int) $s->early, 'overtime_actual_minutes' => (int) $s->overtime,
            'weekend_days' => (int) $s->weekends, 'holiday_days' => (int) $s->holidays, 'leave_days' => (int) $s->leaves]);
    }

    // ───────────────────────────── payslip batches, payslips, lines ─────────────────────────────

    private function run(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        /** @var ?Run $existing */
        $existing = $this->find(Run::class, $ref);
        if ($r->deleted_at !== null) {
            if (! $existing) {
                $this->count('runs.skippedDeleted');
            } elseif ($existing->state !== 'cancelled') {
                $this->count('runs.cancelled');
                if (! $this->dry) {
                    Payslip::where('run_id', $existing->id)->update(['state' => 'cancelled', 'live_key' => null]);
                    $existing->update(['state' => 'cancelled', 'closed_by' => 'svc.odoo', 'closed_at' => now(), 'close_reason' => 'حُذفت الدفعة من أودو']);
                }
            }

            return;
        }
        $from = Val::date($d['date_start'] ?? null);
        if (! $from) {
            $this->count('runs.skippedNoDates', "hr.payslip.run #{$ref}");

            return;
        }
        $odooState = is_string($d['state'] ?? null) ? $d['state'] : 'draft';
        $state = self::RUN_STATE[$odooState] ?? 'draft';
        $this->upsert('runs', Run::class, $ref, ['name' => Val::text($d['name'] ?? null, 190), 'period' => substr($from, 0, 7), 'date_from' => $from, 'date_to' => max($from, Val::date($d['date_end'] ?? null) ?? $from),
            'state' => $state, 'source_state' => self::cut($odooState, 20), 'books' => in_array($state, Run::FINAL, true) ? 'odoo' : null], // what Odoo confirmed is in Odoo's books: never posted here
            fn () => ['number' => "OD-PR-{$ref}", 'currency' => 'SAR', 'created_by' => 'svc.odoo', 'created_at' => (Val::at($d['create_date'] ?? null) ?? now())->format('Y-m-d H:i:s.v')], $r, 'PayrollRun');
    }

    private function payslip(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        /** @var ?Payslip $existing */
        $existing = $this->find(Payslip::class, $ref);
        if (! $existing && $r->deleted_at !== null) {
            $this->count('payslips.skippedDeleted');

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $from = Val::date($d['date_from'] ?? null);
        if (! $employeeId || ! $from) {
            $this->count($employeeId ? 'payslips.skippedNoDates' : 'payslips.skippedEmployeeNotCopied', "hr.payslip #{$ref}");

            return;
        }
        $to = max($from, Val::date($d['date_to'] ?? null) ?? $from);
        $period = substr($from, 0, 7);
        $odooState = is_string($d['state'] ?? null) ? $d['state'] : 'draft';
        $state = $r->deleted_at !== null ? 'cancelled' : (self::SLIP_STATE[$odooState] ?? 'draft');
        $contractId = $this->linked('hr_contract', Val::id($d['contract_id'] ?? null));
        // it holds its contract's month while it stands — unless a payslip made here already does: then a person must look
        $liveKey = $contractId && $state !== 'cancelled' && ! Val::bool($d['credit_note'] ?? false) ? Payslip::liveKey($contractId, $period) : null;
        if ($liveKey && $this->installed && Payslip::where('live_key', $liveKey)->where(fn ($w) => $w->whereNull('source_system')->orWhere('external_ref', '!=', $ref))->exists()) {
            $liveKey = null;
            $this->count('payslips.monthAlreadyHeldHere', "hr.payslip #{$ref}");
        }
        $values = ['run_id' => $this->ref(Run::class, self::key1(Val::id($d['payslip_run_id'] ?? null))), 'employee_id' => $employeeId, 'contract_id' => $contractId,
            'structure_id' => $this->ref(Structure::class, self::key1(Val::id($d['struct_id'] ?? null))), 'period' => $period, 'date_from' => $from, 'date_to' => $to, 'state' => $state, 'source_state' => self::cut($odooState, 20),
            'days_covered' => (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1, 'days_in_month' => (int) Carbon::parse($from)->endOfMonth()->format('d'),
            // Odoo's own figures: nothing is recomputed
            'basic' => round(Val::float($d['basic_wage'] ?? 0), 2), 'gross' => round(Val::float($d['gross_wage'] ?? 0), 2), 'net' => round(Val::float($d['net_wage'] ?? 0), 2), 'live_key' => $liveKey,
            'explain' => ['source' => 'odoo', 'number' => Val::text($d['number'] ?? null), 'computedOn' => Val::date($d['compute_date'] ?? null), 'structure' => Val::label($d['struct_id'] ?? null), 'creditNote' => Val::bool($d['credit_note'] ?? false)]]
            + (($departmentId = $this->linked('hr_department', Val::id($d['department_id'] ?? null))) ? ['department_id' => $departmentId] : []);
        /** @var ?Payslip $slip */
        $slip = $this->upsert('payslips', Payslip::class, $ref, $values, fn () => ['number' => "OD-PS-{$ref}", 'currency' => 'SAR', 'created_by' => 'svc.odoo',
            'created_at' => (Val::at($d['create_date'] ?? null) ?? now())->format('Y-m-d H:i:s.v')], $r, 'Payslip');
        if ($slip && ! $this->dry && $slip->run_id) {
            $this->runTotals($slip->run_id);
        }
    }

    private function payslipLine(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $slipId = $this->find(PayslipLine::class, $ref)?->payslip_id;
            $this->drop('payslipLines', PayslipLine::class, $ref);
            if ($slipId && ! $this->dry) {
                $this->slipTotals($slipId);
            }

            return;
        }
        $slipId = $this->ref(Payslip::class, self::key1(Val::id($d['slip_id'] ?? null)));
        if (! $slipId) {
            $this->count('payslipLines.skippedPayslipNotCopied', "hr.payslip.line #{$ref}");

            return;
        }
        $code = self::cut(Val::text($d['code'] ?? null) ?? "LINE-{$ref}", 60);
        $total = round(Val::float($d['total'] ?? 0), 2);
        $name = self::cut(Val::text($d['name'] ?? null) ?? $code, 250);
        $line = $this->upsert('payslipLines', PayslipLine::class, $ref, ['payslip_id' => $slipId, 'sequence' => Val::int($d['sequence'] ?? 0), 'component_id' => $this->componentFor($code), 'code' => $code,
            'name_ar' => self::arabic($name), 'name_en' => $name, 'category' => self::CATEGORY[$this->categoryCode(Val::id($d['category_id'] ?? null)) ?? ''] ?? self::categoryOfCode($code, $total) ?? ($total < 0 ? 'deduction' : 'allowance'),
            'quantity' => round(Val::float($d['quantity'] ?? 1), 4), 'rate' => round(Val::float($d['rate'] ?? 100), 4), 'amount' => round(Val::float($d['amount'] ?? 0), 2), 'total' => $total]); // Odoo's sign: a deduction is negative there too
        if ($line && ! $this->dry) {
            $this->slipTotals($slipId);
        }
    }

    /** The days and hours behind a payslip, as Odoo counted them, are kept on the payslip. */
    private function workedDays(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $key = (string) $r->odoo_id;
        $slipRef = self::key1(Val::id($d['payslip_id'] ?? null));
        /** @var ?Payslip $slip */
        $slip = $slipRef ? $this->find(Payslip::class, $slipRef) : null;
        if (! $slip) {
            // in a plan, the payslip is one the plan would create: so would this be
            isset($this->planned[Payslip::class][$slipRef ?? '']) ? $this->count('workedDays.created') : $this->count('workedDays.skippedPayslipNotCopied', "hr.payslip.worked_days #{$key}");

            return;
        }
        $worked = (array) ($slip->worked ?? []);
        $lines = (array) ($worked['odoo'] ?? []);
        $entry = $r->deleted_at !== null ? null : ['type' => Val::label($d['work_entry_type_id'] ?? null) ?? Val::text($d['name'] ?? null), 'days' => round(Val::float($d['number_of_days'] ?? 0), 2),
            'hours' => round(Val::float($d['number_of_hours'] ?? 0), 2), 'amount' => round(Val::float($d['amount'] ?? 0), 2), 'paid' => Val::bool($d['is_paid'] ?? true)];
        $before = $lines[$key] ?? null;
        if ($before == $entry) {
            $this->count($entry === null ? 'workedDays.skippedDeleted' : 'workedDays.unchanged');

            return;
        }
        $this->count($entry === null ? 'workedDays.removed' : ($before === null ? 'workedDays.created' : 'workedDays.updated'));
        if ($this->dry) {
            return;
        }
        if ($entry === null) {
            unset($lines[$key]);
        } else {
            $lines[$key] = $entry;
        }
        $slip->update(['worked' => ['odoo' => $lines] + $worked]);
    }

    /** A one-off input of an Odoo payslip is an adjustment that payslip already carried. */
    private function payslipInput(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        /** @var ?Adjustment $existing */
        $existing = $this->find(Adjustment::class, $ref);
        if ($r->deleted_at !== null) {
            if ($existing && $existing->state !== 'cancelled') {
                $this->count('adjustments.cancelled');
                if (! $this->dry) {
                    $existing->update(['state' => 'cancelled', 'cancelled_by' => 'svc.odoo', 'cancelled_at' => now(), 'cancel_reason' => 'حُذف من أودو']);
                }
            }

            return;
        }
        $slipRef = self::key1(Val::id($d['payslip_id'] ?? null));
        /** @var ?Payslip $slip */
        $slip = $slipRef ? $this->find(Payslip::class, $slipRef) : null;
        $componentId = $this->ref(Component::class, self::key1(Val::id($d['input_type_id'] ?? null)));
        if (! $slip || ! $componentId) {
            match (true) {
                ! $slip && ! isset($this->planned[Payslip::class][$slipRef ?? '']) => $this->count('adjustments.skippedPayslipNotCopied', "hr.payslip.input #{$ref}"),
                ! $componentId => $this->count('adjustments.skippedTypeNotCopied', "hr.payslip.input #{$ref}"),
                default => $this->count('adjustments.created'), // a plan: its payslip is one the plan would create
            };

            return;
        }
        $this->upsert('adjustments', Adjustment::class, $ref, ['employee_id' => $slip->employee_id, 'period' => $slip->period, 'component_id' => $componentId, 'amount' => round(abs(Val::float($d['amount'] ?? 0)), 2),
            'reason' => Val::text($d['name'] ?? null, 500) ?? Val::label($d['input_type_id'] ?? null) ?? "Odoo payslip input #{$ref}", 'state' => 'applied', 'payslip_id' => $slip->id], fn () => ['created_by' => 'svc.odoo']);
    }

    /** What a payslip of Odoo does not carry as a field is the sum of its own lines. */
    private function slipTotals(string $slipId): void
    {
        $s = PayslipLine::where('payslip_id', $slipId)->selectRaw("coalesce(sum(case when category = 'deduction' then total else 0 end), 0) as deductions, coalesce(sum(case when category = 'company' then total else 0 end), 0) as company")->first();
        Payslip::whereKey($slipId)->update(['deductions' => round(abs((float) $s->deductions), 2), 'employer_cost' => round(abs((float) $s->company), 2)]);
        if ($runId = Payslip::whereKey($slipId)->value('run_id')) {
            $this->runTotals($runId);
        }
    }

    private function runTotals(string $runId): void
    {
        $s = Payslip::where('run_id', $runId)->where('state', '!=', 'cancelled')->selectRaw('count(*) as n, coalesce(sum(gross), 0) as gross, coalesce(sum(deductions), 0) as deductions, coalesce(sum(net), 0) as net, coalesce(sum(employer_cost), 0) as company')->first();
        Run::whereKey($runId)->update(['slip_count' => (int) $s->n, 'gross' => round((float) $s->gross, 2), 'deductions' => round((float) $s->deductions, 2), 'net' => round((float) $s->net, 2), 'employer_cost' => round((float) $s->company, 2)]);
    }

    // ───────────────────────────── skills and résumé ─────────────────────────────

    /** A skill type or a résumé line type: a name, adopted when OPS already has it under the same name. */
    private function named(string $what, string $class, OdooRecord $r): void
    {
        $ref = (string) $r->odoo_id;
        $names = self::names($r);
        if (! $this->find($class, $ref) && $r->deleted_at === null && $this->installed) {
            $twin = $class::whereNull('source_system')->where(fn ($w) => $w->where('name_en', $names['name_en'])->orWhere('name_ar', $names['name_ar']))->orderBy('created_at')->first();
            if ($twin) {
                $this->count("{$what}.linked");
                if (! $this->dry) {
                    $twin->update($names + ['active' => true, 'source_system' => self::SYSTEM, 'external_ref' => $ref]);
                    $this->rows[$class][$ref] = $twin;
                }

                return;
            }
        }
        $this->upsert($what, $class, $ref, $names + ['active' => $this->alive($r)] + (array_key_exists('sequence', (array) $r->data) ? ['sequence' => Val::int($r->data['sequence'])] : []));
    }

    private function skill(OdooRecord $r): void
    {
        $typeId = $this->ref(SkillType::class, self::key1(Val::id($r->data['skill_type_id'] ?? null)));
        if (! $typeId) {
            $this->count('skills.skippedTypeNotCopied', "hr.skill #{$r->odoo_id}");

            return;
        }
        $this->upsert('skills', Skill::class, (string) $r->odoo_id, self::names($r) + ['type_id' => $typeId, 'sequence' => Val::int($r->data['sequence'] ?? 0), 'active' => $this->alive($r)]);
    }

    private function skillLevel(OdooRecord $r): void
    {
        $typeId = $this->ref(SkillType::class, self::key1(Val::id($r->data['skill_type_id'] ?? null)));
        if (! $typeId) {
            $this->count('skillLevels.skippedTypeNotCopied', "hr.skill.level #{$r->odoo_id}");

            return;
        }
        $this->upsert('skillLevels', SkillLevel::class, (string) $r->odoo_id, self::names($r) + ['type_id' => $typeId, 'progress' => max(0, min(100, Val::int($r->data['level_progress'] ?? 0))),
            'is_default' => Val::bool($r->data['default_level'] ?? false), 'active' => $this->alive($r)]);
    }

    private function employeeSkill(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $this->drop('employeeSkills', EmployeeSkill::class, $ref);

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        $skillId = $this->ref(Skill::class, self::key1(Val::id($d['skill_id'] ?? null)));
        $typeId = $this->ref(SkillType::class, self::key1(Val::id($d['skill_type_id'] ?? null)));
        if (! $employeeId || ! $skillId || ! $typeId) {
            $this->count($employeeId ? 'employeeSkills.skippedSkillNotCopied' : 'employeeSkills.skippedEmployeeNotCopied', "hr.employee.skill #{$ref}");

            return;
        }
        $values = ['employee_id' => $employeeId, 'skill_id' => $skillId, 'type_id' => $typeId, 'level_id' => $this->ref(SkillLevel::class, self::key1(Val::id($d['skill_level_id'] ?? null)))];
        if (! $this->find(EmployeeSkill::class, $ref) && $this->installed && ! str_starts_with($skillId, 'new:')
            && ($same = EmployeeSkill::where('employee_id', $employeeId)->where('skill_id', $skillId)->first())) {
            // the person already has this skill here: one row per person and skill — it takes Odoo's level and the link
            $this->count('employeeSkills.linked');
            if (! $this->dry) {
                $same->update($values + ['source_system' => self::SYSTEM, 'external_ref' => $ref]);
                $this->rows[EmployeeSkill::class][$ref] = $same;
            }

            return;
        }
        $this->upsert('employeeSkills', EmployeeSkill::class, $ref, $values, fn () => ['created_by' => 'svc.odoo']);
    }

    private function resumeLine(OdooRecord $r): void
    {
        $d = (array) $r->data;
        $ref = (string) $r->odoo_id;
        if ($r->deleted_at !== null) {
            $this->drop('resumeLines', ResumeLine::class, $ref);

            return;
        }
        $employeeId = $this->linked('hr_employee', Val::id($d['employee_id'] ?? null));
        if (! $employeeId) {
            $this->count('resumeLines.skippedEmployeeNotCopied', "hr.resume.line #{$ref}");

            return;
        }
        $start = Val::date($d['date_start'] ?? null);
        $end = Val::date($d['date_end'] ?? null);
        // what the line says is a person's history: it is stored for who may read it and written nowhere else
        $this->upsert('resumeLines', ResumeLine::class, $ref, ['employee_id' => $employeeId, 'type_id' => $this->ref(ResumeType::class, self::key1(Val::id($d['line_type_id'] ?? null))),
            'title' => self::cut(Val::text($d['name'] ?? null) ?? "Odoo résumé line #{$ref}", 250), 'date_start' => $start, 'date_end' => $end !== null && $start !== null && $end < $start ? null : $end,
            'description' => Val::plain($d['description'] ?? null, 4000)], fn () => ['created_by' => 'svc.odoo']);
    }

    // ───────────────────────────── the machinery ─────────────────────────────

    /**
     * Creates or refreshes the OPS row of one Odoo record and counts what happened as `<what>.created | updated |
     * archived | unchanged`. A plan decides and counts without writing. `$values` are the columns Odoo owns.
     *
     * @param  class-string<Model>  $class
     * @param  ?callable  $onCreate  values only a new row gets (its number, what OPS proposes once)
     * @param  ?string  $trail  the entity type of the audit line of a created or changed row (none for the lines of a document)
     */
    private function upsert(string $what, string $class, string $ref, array $values, ?callable $onCreate = null, ?OdooRecord $r = null, ?string $trail = null): ?Model
    {
        $row = $this->find($class, $ref);
        if (! $row) {
            if ($r !== null && $r->deleted_at !== null) {
                $this->count("{$what}.skippedDeleted");

                return null;
            }
            $this->count("{$what}.created");
            if ($this->dry) {
                $this->planned[$class][$ref] = true;

                return null;
            }
            $row = $class::create($values + ($onCreate ? $onCreate() : []) + ['source_system' => self::SYSTEM, 'external_ref' => $ref]);
            $this->rows[$class][$ref] = $row;
            if ($trail) {
                $this->audit->log($this->actor(), ['action' => 'PAYROLL.IMPORT', 'entityType' => $trail, 'entityId' => $row->getKey(), 'entityNumber' => $row->getAttribute('number') ?? $row->getAttribute('code') ?? $ref,
                    'newValue' => ['origin' => $r ? "Odoo {$r->model} #{$r->odoo_id}" : 'Odoo']]);
            }

            return $row;
        }
        $changes = self::changes($row, $values);
        $this->count($changes ? (($changes['active'] ?? null) === false ? "{$what}.archived" : "{$what}.updated") : "{$what}.unchanged");
        if ($changes && ! $this->dry) {
            $row->update($changes);
            if ($trail) {
                // which fields Odoo changed — never their values: the trail is read by people who may not read the record
                $this->audit->log($this->actor(), ['action' => 'PAYROLL.SYNC', 'entityType' => $trail, 'entityId' => $row->getKey(), 'entityNumber' => $row->getAttribute('number') ?? $row->getAttribute('code') ?? $ref,
                    'newValue' => ['fields' => array_keys($changes), 'origin' => 'Odoo']]);
            }
        }

        return $row;
    }

    /** A line of something (a stretch of a schedule, a line of a rule or of a sheet, a skill of a person) that Odoo no longer holds goes with it. */
    private function drop(string $what, string $class, string $ref): void
    {
        $row = $this->find($class, $ref);
        if (! $row) {
            $this->count("{$what}.skippedDeleted");

            return;
        }
        $this->count("{$what}.removed");
        if (! $this->dry) {
            $row->delete();
            $this->rows[$class][$ref] = null;
        }
    }

    /** The OPS row an Odoo record became (looked up once per pass). */
    private function find(string $class, string $ref): ?Model
    {
        if (! $this->installed) {
            return null;
        }
        if (! array_key_exists($ref, $this->rows[$class] ?? [])) {
            $this->rows[$class][$ref] = $class::where('source_system', self::SYSTEM)->where('external_ref', $ref)->first();
        }

        return $this->rows[$class][$ref];
    }

    /** The OPS id of an Odoo record; in a plan, a marker for one the plan would create; null when OPS does not hold it. */
    private function ref(string $class, ?string $ref): ?string
    {
        if ($ref === null) {
            return null;
        }
        if ($row = $this->find($class, $ref)) {
            return (string) $row->getKey();
        }

        return isset($this->planned[$class][$ref]) ? "new:{$ref}" : null;
    }

    private function categoryCode(?int $odooId): ?string
    {
        if (! $odooId) {
            return null;
        }
        if (! array_key_exists($odooId, $this->categoryCodes)) {
            $this->categoryCodes[$odooId] = ($code = Val::text($this->mirrored('hr.salary.rule.category', $odooId)?->data['code'] ?? null, 20)) === null ? null : mb_strtoupper($code);
        }

        return $this->categoryCodes[$odooId];
    }

    /** The OPS component that does the work of an Odoo rule of this code, when there is one. */
    private function componentFor(string $code): ?string
    {
        $ops = self::COMPONENT_OF[mb_strtolower($code)] ?? null;
        if (! $ops || ! $this->installed) {
            return null;
        }
        if (! array_key_exists($ops, $this->components)) {
            $this->components[$ops] = Component::where('code', $ops)->value('id');
        }

        return $this->components[$ops];
    }

    /**
     * The category of a line Odoo holds without one, from what its code means: the OPS component that does the same
     * work (BASIC → basic, HOUALLOW → allowance …), or Odoo's own summary codes; a negative amount is a deduction
     * whatever the code (Odoo's SIC line on a payslip is the employee's share). Null when the code says nothing.
     */
    private static function categoryOfCode(string $code, float $total): ?string
    {
        $upper = mb_strtoupper($code);
        if (in_array($upper, ['GROSS', 'NET'], true)) {
            return mb_strtolower($upper);
        }
        $ops = self::COMPONENT_OF[mb_strtolower($code)] ?? null;
        $category = $ops ? (array_column(PayrollBootstrap::COMPONENTS, 3, 0)[$ops] ?? null) : null;

        if ($category === null || ! in_array($category, ['basic', 'allowance', 'deduction', 'company', 'reimbursement'], true)) {
            return null;
        }

        return $total < 0 ? 'deduction' : $category;
    }

    /** @return array{name_ar:string, name_en:string} */
    private static function names(OdooRecord $r): array
    {
        $name = self::cut(Val::text($r->data['name'] ?? null) ?? Val::text($r->display_name) ?? "#{$r->odoo_id}", 190);

        return ['name_ar' => self::arabic($name), 'name_en' => $name];
    }

    private static function arabic(string $name): string
    {
        return preg_match('/\p{Arabic}/u', $name) ? $name : (self::ARABIC[mb_strtolower(trim($name))] ?? $name);
    }

    private static function cut(?string $s, int $max): ?string
    {
        return $s === null ? null : mb_substr($s, 0, $max);
    }

    private static function key1(?int $id): ?string
    {
        return $id ? (string) $id : null;
    }

    /**
     * The values that differ from what the row holds. A moment equals the same moment, a day its `YYYY-MM-DD`, 5 equals
     * "5.00", a list its content — and a marker of a plan ("new:…") always differs.
     *
     * @return array<string, mixed>
     */
    private static function changes(Model $row, array $values): array
    {
        $out = [];
        foreach ($values as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof DateTimeInterface || $value instanceof DateTimeInterface) {
                $format = is_string($value) && strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
                $same = ($current instanceof DateTimeInterface ? $current->format($format) : $current) === ($value instanceof DateTimeInterface ? $value->format($format) : $value);
            } elseif (is_bool($current) || is_bool($value)) {
                $same = $current !== null && $value !== null && (bool) $current === (bool) $value;
            } elseif (is_array($current) || is_array($value)) {
                [$a, $b] = [(array) ($current ?: []), (array) ($value ?: [])];
                ksort($a);
                ksort($b);
                $same = $a == $b;
            } elseif (is_numeric($current) && is_numeric($value) && (is_int($value) || is_float($value) || is_int($current) || is_float($current))) {
                $same = abs((float) $current - (float) $value) < 0.005;
            } else {
                $same = (string) ($current ?? '') === (string) ($value ?? '');
            }
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }
}
