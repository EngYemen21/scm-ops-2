<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\Component;
use App\Payroll\Models\GosiRate;
use App\Payroll\Models\LeaveType;
use App\Payroll\Models\Parameter;
use App\Payroll\Models\PayAccount;
use App\Payroll\Models\Schedule;
use App\Payroll\Models\Structure;
use App\Payroll\PayrollBootstrap;
use App\Payroll\Services\Rates;
use App\Payroll\Services\RunPosting;
use App\Payroll\Support\Owned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\ApiTestCase;

/** The migration and what it leaves behind: the tables, the engine's starting configuration, the permissions. */
class PayrollSchemaTest extends ApiTestCase
{
    use PayrollFixtures;

    public function test_the_tables_exist_and_the_section_starts_configured(): void
    {
        foreach (['pay_schedules', 'pay_schedule_days', 'pay_holidays', 'pay_leave_types', 'pay_leaves', 'pay_leave_entries', 'pay_attendance', 'pay_attendance_changes', 'pay_attendance_rules',
            'pay_attendance_rule_lines', 'pay_attendance_policies', 'pay_policy_overtime_rules', 'pay_sheet_batches', 'pay_sheets', 'pay_sheet_lines', 'pay_components', 'pay_structures',
            'pay_structure_rules', 'pay_gosi_rates', 'pay_parameters', 'pay_accounts', 'pay_profiles', 'pay_adjustments', 'pay_runs', 'pay_payslips', 'pay_payslip_lines',
            'pay_skill_types', 'pay_skills', 'pay_skill_levels', 'pay_employee_skills', 'pay_resume_types', 'pay_resume_lines'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "table {$table}");
        }

        // the engine's own configuration: every component it computes, the rates and parameters it reads — as rows with dates
        $this->assertSame(count(PayrollBootstrap::COMPONENTS), Component::whereIn('code', array_column(PayrollBootstrap::COMPONENTS, 0))->count());
        $this->assertEqualsCanonicalizing(['BASIC', 'HOUSING'], Component::where('gosi_base', true)->pluck('code')->all());
        $this->assertSame(['expat', 'saudi'], GosiRate::orderBy('scheme')->pluck('scheme')->all());
        foreach (array_keys(Rates::KNOWN) as $code) {
            $this->assertSame(1, Parameter::where('code', $code)->count(), "parameter {$code}");
        }
        $rates = app(Rates::class);
        $this->assertSame([30.0, 'gross', 'basic'], [$rates->number('month_days', '2026-08-31'), $rates->choice('unpaid_base', '2026-08-31'), $rates->choice('attendance_base', '2026-08-31')]);
        $this->assertSame(['9.7500', '11.7500'], [$rates->gosi('saudi', '2026-08-31')->employee_pct, $rates->gosi('saudi', '2026-08-31')->employer_pct]);
        $this->assertNull($rates->gosi('saudi', '2019-12-31'), 'no rate before its date: the engine refuses rather than assumes');

        // the posting map has every key; the ones the default chart answers are filled
        $this->assertEqualsCanonicalizing(array_keys(RunPosting::KEYS), PayAccount::pluck('key')->all());
        $this->assertNotNull(PayAccount::find('salaries')->account_code);
        $this->assertNotNull(PayAccount::find('payable')->account_code);

        // the engine's default policy with its rules: written whatever the section follows
        $policy = AttendancePolicy::where('is_default', true)->whereNull('source_system')->where('created_by', 'system')->with(['lateRule.lines', 'absenceRule.lines', 'overtimeRules'])->orderBy('created_at')->orderBy('id')->firstOrFail();
        $this->assertSame([15, 15, 1, 1, 3], [$policy->late_grace_minutes, $policy->early_grace_minutes, $policy->lateRule->lines->count(), $policy->absenceRule->lines->count(), $policy->overtimeRules->count()]);

        // what Odoo also holds (schedule, kinds of time off, structures, résumé types) is written only where the section
        // does NOT follow Odoo — the suite runs with the Odoo switch on, so it is proven on emptied tables inside a
        // transaction that is rolled back (nothing of another story is touched)
        $this->assertTrue(Owned::following(), 'the suite runs with the section following Odoo');
        $this->assertArrayNotHasKey('schedules', PayrollBootstrap::ensure(), 'following Odoo: the schedule comes from the copy, never a second one beside it');
        DB::beginTransaction();
        try {
            foreach (['pay_schedule_days', 'pay_schedules', 'pay_leave_types', 'pay_structures', 'pay_resume_types'] as $table) {
                DB::table($table)->delete();
            }
            $this->followOdoo(false);
            $this->assertSame(['schedules' => 1, 'leaveTypes' => 4, 'structures' => 3, 'resumeTypes' => 3], array_intersect_key(PayrollBootstrap::ensure(), ['schedules' => 1, 'leaveTypes' => 1, 'structures' => 1, 'resumeTypes' => 1]));
            $schedule = Schedule::where('is_default', true)->with('days')->sole();
            $this->assertEqualsCanonicalizing([0, 1, 2, 3, 6], $schedule->days->where('kind', 'work')->pluck('weekday')->unique()->values()->all());
            $this->assertSame(480, $schedule->dayMinutes());
            $this->assertEqualsCanonicalizing(['ANNUAL', 'SICK', 'UNPAID', 'COMP'], LeaveType::pluck('code')->all());
            $this->assertSame(1, Structure::where('is_default', true)->count());
            $this->assertSame(['schedules' => 0, 'leaveTypes' => 0, 'structures' => 0, 'resumeTypes' => 0], PayrollBootstrap::defaults(), 'only ever into an empty table');
        } finally {
            DB::rollBack();
            $this->followOdoo(true);
        }

        // a second call adds nothing
        $before = [Component::count(), GosiRate::count(), Parameter::count(), PayAccount::count(), AttendancePolicy::count(), Schedule::count(), LeaveType::count(), Structure::count()];
        PayrollBootstrap::ensure();
        $this->assertSame($before, [Component::count(), GosiRate::count(), Parameter::count(), PayAccount::count(), AttendancePolicy::count(), Schedule::count(), LeaveType::count(), Structure::count()]);
    }

    public function test_the_permissions_are_granted_to_the_roles_the_contract_names(): void
    {
        $holders = fn (string $permission) => DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.key', $permission)->pluck('roles.key')->all();
        $this->assertContains('finance', $holders('payroll.manage'));
        $this->assertContains('gm', $holders('payroll.approve'));
        $this->assertNotContains('gm', $holders('payroll.manage'), 'the approver does not prepare the run');
        $this->assertContains('wm', $holders('leave.approve'));
        $this->assertContains('wm', $holders('attendance.manage'));
        $this->assertContains('sales', $holders('leave.request'));
    }

    public function test_a_person_without_an_employee_record_gets_an_honest_empty_corner(): void
    {
        $nobody = $this->userWith('paynobody', ['leave.request']);
        $summary = $this->expectOk($this->getAs($nobody, '/api/payroll/summary'));
        $this->assertFalse($summary['me']['linked']);
        $this->assertArrayNotHasKey('payroll', $summary);
        $this->assertArrayNotHasKey('attendance', $summary);
        $this->expectRejected($this->postAs($nobody, '/api/payroll/attendance/check-in'), 'NO_EMPLOYEE_RECORD', [422]);
        $this->assertSame([], $this->expectOk($this->getAs($nobody, '/api/payroll/payslips'))['items']);
        $this->assertSame([], $this->expectOk($this->getAs($nobody, '/api/payroll/leaves'))['items']);
        // nothing of the payroll people's lists is theirs to open
        foreach (['/api/payroll/runs', '/api/payroll/payslips?scope=all', '/api/payroll/profiles', '/api/payroll/adjustments', '/api/payroll/gosi-rates', '/api/payroll/attendance/daily', '/api/payroll/skills/employees'] as $url) {
            $this->assertSame(403, $this->getAs($nobody, $url)->getStatusCode(), $url);
        }
    }
}
