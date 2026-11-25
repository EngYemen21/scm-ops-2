<?php

namespace Tests\Feature\Payroll;

use App\Integration\Services\ExternalRefs;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\EmployeeSkill;
use App\Payroll\Models\Leave;
use App\Payroll\Models\LeaveEntry;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\ResumeLine;
use App\Payroll\Models\Run;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\ScheduleDay;
use App\Payroll\Models\Structure;
use App\Payroll\Models\StructureRule;
use App\Payroll\OdooPayrollProjection;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From a small copy of Odoo (rows written straight into odoo_models / odoo_records — Odoo is never called) to the
 * section: a plan that writes nothing, a first load that puts every record in its place, a second load that changes
 * nothing, Odoo's records shown and not edited while the section follows Odoo, a deletion arriving through the feed,
 * and an OPS run that never pays a month Odoo already holds a payslip for. Every person and figure here is invented;
 * the people come from People & expenses through their links (load `hr` first in real life).
 */
class PayrollOdooProjectionTest extends ApiTestCase
{
    use PayrollFixtures;

    private const CORE = ['resource.calendar', 'resource.calendar.attendance', 'resource.calendar.leaves', 'hr.payroll.structure', 'hr.salary.rule', 'hr.payslip.input.type', 'hr.rule.parameter.value',
        'hr.leave.type', 'hr.contract', 'hr.leave.allocation', 'hr.leave', 'hr.attendance', 'hr.payslip.run', 'hr.payslip', 'hr.payslip.line', 'hr.payslip.worked_days', 'hr.payslip.input',
        'hr.skill.type', 'hr.skill', 'hr.skill.level', 'hr.employee.skill', 'hr.resume.line.type', 'hr.resume.line'];

    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sealNetwork();
        $this->followOdoo(true);
        if (! self::$s) {
            self::$s = $this->buildMirror();
        }
    }

    private function projection(): OdooPayrollProjection
    {
        return app(OdooPayrollProjection::class);
    }

    private function buildMirror(): array
    {
        $w = $this->payrollWorld();
        $empUser = $this->userWith('pod'.self::tag(), ['leave.request']);
        $e = $this->staff($w, ['userId' => $empUser]);
        $this->emptyMirror();
        foreach (self::CORE as $model) {
            $this->mirrorModel($model, ['name' => 'char', 'active' => 'boolean']);
        }
        $refs = app(ExternalRefs::class);
        $refs->link('odoo', 'hr_employee', '96001', $e['id']);
        $refs->link('odoo', 'hr_contract', '96101', $e['contract']['id']);
        $tag = self::tag();
        $m = fn (int $id) => [$id, 'label'];

        $this->mirrorRecord('resource.calendar', 96201, ['name' => "Calendar P6 {$tag}", 'tz' => 'Asia/Riyadh', 'hours_per_day' => 8.0, 'active' => true]);
        $this->mirrorRecord('resource.calendar.attendance', 96211, ['calendar_id' => $m(96201), 'dayofweek' => '6', 'hour_from' => 8.0, 'hour_to' => 12.0, 'day_period' => 'morning', 'name' => 'Sunday Morning']);
        $this->mirrorRecord('resource.calendar.attendance', 96212, ['calendar_id' => $m(96201), 'dayofweek' => '6', 'hour_from' => 12.0, 'hour_to' => 13.0, 'day_period' => 'lunch', 'name' => 'Lunch']);
        $this->mirrorRecord('resource.calendar.attendance', 96213, ['calendar_id' => $m(96201), 'dayofweek' => '6', 'hour_from' => 13.0, 'hour_to' => 17.0, 'day_period' => 'afternoon', 'name' => 'Sunday Afternoon']);
        $this->mirrorRecord('resource.calendar.attendance', 96214, ['calendar_id' => $m(96201), 'dayofweek' => '0', 'hour_from' => 0.0, 'hour_to' => 0.0, 'display_type' => 'line_section', 'name' => 'Week 1']);
        $this->mirrorRecord('hr.payroll.structure', 96301, ['name' => "Structure P6 {$tag}", 'active' => true, 'type_id' => [1, 'Employee'], 'country_id' => [192, 'Saudi Arabia']]);
        $this->mirrorRecord('hr.salary.rule', 96311, ['name' => 'Basic Salary', 'code' => 'BASIC', 'struct_id' => $m(96301), 'sequence' => 1, 'amount_select' => 'code', 'amount_python_compute' => 'result = payslip.paid_amount', 'condition_select' => 'none', 'active' => true]);
        $this->mirrorRecord('hr.salary.rule', 96312, ['name' => 'Social insurance', 'code' => 'SIC', 'struct_id' => $m(96301), 'sequence' => 50, 'amount_select' => 'percentage', 'amount_percentage' => 9.75, 'amount_percentage_base' => 'contract.wage', 'condition_select' => 'none', 'active' => true]);
        $this->mirrorRecord('hr.salary.rule', 96313, ['name' => 'Orphan', 'code' => 'X', 'struct_id' => $m(96399), 'active' => true]);
        $this->mirrorRecord('hr.leave.type', 96401, ['name' => "Leave P6 {$tag}", 'requires_allocation' => 'yes', 'leave_validation_type' => 'both', 'request_unit' => 'day', 'unpaid' => false, 'active' => true, 'sequence' => 5]);
        $this->mirrorRecord('hr.leave.allocation', 96411, ['employee_id' => $m(96001), 'holiday_status_id' => $m(96401), 'holiday_type' => 'employee', 'number_of_days' => 10.0, 'state' => 'validate', 'date_from' => '2026-01-01', 'active' => true]);
        $this->mirrorRecord('hr.leave', 96421, ['employee_id' => $m(96001), 'holiday_status_id' => $m(96401), 'holiday_type' => 'employee', 'request_date_from' => '2026-07-05', 'request_date_to' => '2026-07-06',
            'number_of_days' => 2.0, 'state' => 'validate', 'private_name' => 'خاص', 'create_date' => '2026-07-01 08:00:00', 'active' => true]);
        $this->mirrorRecord('hr.attendance', 96501, ['employee_id' => $m(96001), 'check_in' => '2026-07-07 05:00:00', 'check_out' => '2026-07-07 14:00:00', 'in_mode' => 'kiosk', 'out_mode' => 'kiosk']);
        $this->mirrorRecord('hr.attendance', 96502, ['employee_id' => $m(96999), 'check_in' => '2026-07-07 05:00:00', 'check_out' => false]);   // somebody People & expenses does not hold
        $this->mirrorRecord('hr.payslip.run', 96601, ['name' => 'July 2026', 'date_start' => '2026-07-01', 'date_end' => '2026-07-31', 'state' => 'close', 'create_date' => '2026-07-30 10:00:00']);
        $this->mirrorRecord('hr.payslip', 96611, ['employee_id' => $m(96001), 'contract_id' => $m(96101), 'payslip_run_id' => $m(96601), 'struct_id' => $m(96301), 'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
            'state' => 'done', 'number' => 'SLIP/0001', 'basic_wage' => 5000.0, 'gross_wage' => 6000.0, 'net_wage' => 5512.5, 'credit_note' => false, 'create_date' => '2026-07-30 10:00:00']);
        $this->mirrorRecord('hr.payslip.line', 96621, ['slip_id' => $m(96611), 'code' => 'BASIC', 'name' => 'Basic Salary', 'category_id' => false, 'sequence' => 1, 'quantity' => 1.0, 'rate' => 100.0, 'amount' => 5000.0, 'total' => 5000.0]);
        $this->mirrorRecord('hr.payslip.line', 96622, ['slip_id' => $m(96611), 'code' => 'HOUALLOW', 'name' => 'Housing', 'category_id' => false, 'sequence' => 2, 'quantity' => 1.0, 'rate' => 100.0, 'amount' => 1000.0, 'total' => 1000.0]);
        $this->mirrorRecord('hr.payslip.line', 96623, ['slip_id' => $m(96611), 'code' => 'SIC', 'name' => 'Social insurance', 'category_id' => false, 'sequence' => 50, 'quantity' => 1.0, 'rate' => 9.75, 'amount' => 487.5, 'total' => -487.5]);
        $this->mirrorRecord('hr.skill.type', 96701, ['name' => "Languages P6 {$tag}", 'active' => true]);
        $this->mirrorRecord('hr.skill', 96711, ['name' => "Arabic P6 {$tag}", 'skill_type_id' => $m(96701), 'sequence' => 1]);
        $this->mirrorRecord('hr.skill.level', 96721, ['name' => "Native P6 {$tag}", 'skill_type_id' => $m(96701), 'level_progress' => 100, 'default_level' => true]);
        $this->mirrorRecord('hr.employee.skill', 96731, ['employee_id' => $m(96001), 'skill_id' => $m(96711), 'skill_level_id' => $m(96721), 'skill_type_id' => $m(96701)]);
        $this->mirrorRecord('hr.resume.line.type', 96801, ['name' => "Experience P6 {$tag}", 'sequence' => 1]);
        $this->mirrorRecord('hr.resume.line', 96811, ['employee_id' => $m(96001), 'line_type_id' => $m(96801), 'name' => 'Previous employer', 'date_start' => '2020-01-01', 'date_end' => '2025-12-31', 'description' => '<p>Storekeeper</p>']);

        return ['w' => $w, 'e' => $e, 'empUser' => $empUser];
    }

    private static function ops(string $class, int $ref): mixed
    {
        return $class::where('source_system', 'odoo')->where('external_ref', (string) $ref)->first();
    }

    /** @return array<string, int> how many rows each payroll table holds */
    private static function sizes(): array
    {
        $out = [];
        foreach (['pay_schedules', 'pay_schedule_days', 'pay_structures', 'pay_structure_rules', 'pay_leave_types', 'pay_leaves', 'pay_leave_entries', 'pay_attendance', 'pay_runs', 'pay_payslips', 'pay_payslip_lines',
            'pay_skill_types', 'pay_skills', 'pay_skill_levels', 'pay_employee_skills', 'pay_resume_types', 'pay_resume_lines'] as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    public function test_the_plan_counts_and_writes_nothing(): void
    {
        $before = [self::sizes(), DB::table('audit_logs')->count()];
        $this->assertTrue($this->projection()->ready());
        $plan = $this->projection()->plan();
        $this->assertTrue($plan['dryRun']);
        $c = $plan['counts'];
        foreach (['schedules.created' => 1, 'scheduleDays.created' => 3, 'scheduleDays.skippedNotWeekly' => 1, 'structures.created' => 1, 'rules.created' => 2, 'rules.skippedStructureNotCopied' => 1,
            'leaveTypes.created' => 1, 'allocations.created' => 1, 'leaves.created' => 1, 'attendance.created' => 1, 'attendance.skippedEmployeeNotCopied' => 1, 'runs.created' => 1, 'payslips.created' => 1,
            'payslipLines.created' => 3, 'skillTypes.created' => 1, 'skills.created' => 1, 'skillLevels.created' => 1, 'employeeSkills.created' => 1, 'resumeTypes.created' => 1, 'resumeLines.created' => 1] as $key => $n) {
            $this->assertSame($n, $c[$key] ?? null, $key);
        }
        $this->assertSame($before, [self::sizes(), DB::table('audit_logs')->count()], 'a plan writes nothing');
        // what a person reads of a pass names records by model and Odoo id only
        $this->assertSame(['hr.attendance #96502'], $plan['notes']['attendance.skippedEmployeeNotCopied']);
        $this->assertStringNotContainsString('5512', json_encode($plan));
    }

    public function test_the_first_load_puts_everything_in_place_and_a_second_changes_nothing(): void
    {
        $s = self::$s;
        $this->projection()->apply();
        $schedule = self::ops(Schedule::class, 96201);
        $this->assertSame(['Asia/Riyadh', 480], [$schedule->timezone, $schedule->load('days')->dayMinutes()]);
        $this->assertSame('break', self::ops(ScheduleDay::class, 96212)->kind);
        $structure = self::ops(Structure::class, 96301);
        $this->assertSame(['OD-96301', 'saudi'], [$structure->code, $structure->gosi_scheme], 'a structure with a social-insurance rule starts under the Saudi scheme');
        $this->assertSame('9.75% of contract.wage', self::ops(StructureRule::class, 96312)->formula);

        // time off: the type, the allocation and the approved leave as ledger lines that reconcile
        $type = self::ops(LeaveType::class, 96401);
        $this->assertSame(['OD-LT-96401', 'both', true], [$type->code, $type->approval, $type->requires_allocation]);
        $leave = self::ops(Leave::class, 96421);
        $this->assertSame(['OD-LV-96421', 'approved', 2.0], [$leave->number, $leave->state, (float) $leave->days]);
        $this->assertEquals(8.0, (float) LeaveEntry::where('employee_id', $s['e']['id'])->where('leave_type_id', $type->id)->where('year', 2026)->sum('days'));
        $this->assertTrue($this->expectOk($this->getAs('admin', '/api/payroll/leave-ledger/reconcile'))['ok']);

        // attendance on the schedule's clock
        $a = self::ops(Attendance::class, 96501);
        $this->assertSame(['2026-07-07', 540, 'kiosk'], [$a->work_date->format('Y-m-d'), $a->worked_minutes, $a->in_mode]);

        // pay is history: Odoo's figures, Odoo's books, the month held
        $run = self::ops(Run::class, 96601);
        $this->assertSame(['OD-PR-96601', 'approved', 'odoo'], [$run->number, $run->state, $run->books]);
        $slip = self::ops(Payslip::class, 96611);
        $this->assertSame(['OD-PS-96611', 'approved', '5512.50', $run->id], [$slip->number, $slip->state, (string) $slip->net, $slip->run_id]);
        $this->assertSame(Payslip::liveKey($s['e']['contract']['id'], '2026-07'), $slip->live_key);
        $this->assertSame(['basic', 'deduction'], [self::ops(PayslipLine::class, 96621)->category, self::ops(PayslipLine::class, 96623)->category]);
        $this->assertSame('HOUSING', DB::table('pay_components')->where('id', self::ops(PayslipLine::class, 96622)->component_id)->value('code'));

        // skills and résumé
        $this->assertNotNull(self::ops(EmployeeSkill::class, 96731)->level_id);
        $this->assertSame(['Previous employer', 'Storekeeper'], [self::ops(ResumeLine::class, 96811)->title, self::ops(ResumeLine::class, 96811)->description]);

        // the employee sees his own Odoo payslip, his skills and his résumé
        $this->assertContains($slip->id, array_column($this->expectOk($this->getAs($s['empUser'], '/api/payroll/payslips'))['items'], 'id'));
        $mine = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/skills/employees/me'));
        $this->assertSame([1, 1, false], [count($mine['skills']), count($mine['resume']), $mine['resume'][0]['editable']]);

        // a second load changes nothing
        $sizes = self::sizes();
        $again = $this->projection()->apply()['counts'];
        $this->assertSame([], array_filter($again, fn ($n, $k) => str_ends_with($k, '.created') || str_ends_with($k, '.updated'), ARRAY_FILTER_USE_BOTH));
        $this->assertSame($sizes, self::sizes());
    }

    public function test_odoos_records_are_shown_and_not_edited_and_an_ops_run_never_pays_odoos_month(): void
    {
        $s = self::$s;
        $type = self::ops(LeaveType::class, 96401);
        $this->expectRejected($this->patchAs('admin', "/api/payroll/leave-types/{$type->id}", ['nameAr' => 'اسم آخر']), 'LEAVE_TYPE_OWNED_BY_SOURCE', [422]);
        $this->expectOk($this->patchAs('admin', "/api/payroll/leave-types/{$type->id}", ['yearlyDays' => 21]));   // what OPS adds stays OPS's
        $att = self::ops(Attendance::class, 96501);
        $this->expectRejected($this->postAs('admin', "/api/payroll/attendance/{$att->id}/void", ['reason' => 'محاولة تعديل سجل أودو']), 'ATTENDANCE_OWNED_BY_SOURCE', [422]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/runs/'.self::ops(Run::class, 96601)->id.'/reverse', ['reason' => 'محاولة عكس مسير أودو']), 'PAYRUN_OWNED_BY_SOURCE', [422]);
        $leave = self::ops(Leave::class, 96421);
        $this->expectRejected($this->postAs('admin', "/api/payroll/leaves/{$leave->id}/cancel", ['reason' => 'محاولة إلغاء إجازة أودو']), 'LEAVE_OWNED_BY_SOURCE', [422]);

        $run = $this->expectOk($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-07', 'department' => $s['w']['department']]));
        $this->assertSame([0, ['ALREADY_PAID']], [$run['slipCount'], array_column($run['skipped'], 'reason')]);
        $this->assertSame('OD-PS-96611', $run['skipped'][0]['payslip']);
        $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$run['id']}/cancel", ['reason' => 'أودو دفع الشهر']));
    }

    public function test_a_deletion_arrives_through_the_feed(): void
    {
        $this->mirrorRecord('hr.attendance', 96501, ['employee_id' => [96001, 'x'], 'check_in' => '2026-07-07 05:00:00', 'check_out' => '2026-07-07 14:00:00'], 'deleted', true);
        $this->mirrorRecord('hr.leave', 96421, ['employee_id' => [96001, 'x'], 'holiday_status_id' => [96401, 'x'], 'holiday_type' => 'employee', 'request_date_from' => '2026-07-05', 'request_date_to' => '2026-07-06',
            'number_of_days' => 2.0, 'state' => 'refuse', 'active' => true], 'updated');
        $out = $this->projection()->consume();
        $this->assertSame(2, $out['changes']);
        $a = self::ops(Attendance::class, 96501);
        $this->assertSame(['void', 'svc.odoo'], [$a->status, $a->voided_by], 'a presence Odoo deleted is voided here, never removed');
        $leave = self::ops(Leave::class, 96421);
        $this->assertSame('refused', $leave->state);
        $this->assertEquals(10.0, (float) LeaveEntry::where('employee_id', self::$s['e']['id'])->where('leave_type_id', $leave->leave_type_id)->where('year', 2026)->sum('days'), 'the days came back through a new ledger line');
        $this->assertTrue($this->expectOk($this->getAs('admin', '/api/payroll/leave-ledger/reconcile'))['ok']);
    }
}
