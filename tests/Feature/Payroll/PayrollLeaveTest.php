<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\LeaveEntry;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Time off as a story: a request, the two-step decision (manager, then HR), the balance as a ledger that reconciles,
 * the refusals of every illegal move, and who may see what. The suite's clock stands at Friday 2026-09-18; the story's
 * schedule works Sunday to Thursday. Every person here is invented.
 */
class PayrollLeaveTest extends ApiTestCase
{
    use PayrollFixtures;

    private static array $s = [];

    private function world(): array
    {
        if (self::$s) {
            return self::$s;
        }
        $w = $this->payrollWorld();
        $t = self::tag();
        $mgrUser = $this->userWith("plm{$t}", ['leave.request']);
        $empUser = $this->userWith("ple{$t}", ['leave.request']);
        $otherUser = $this->userWith("plo{$t}", ['leave.request']);
        $hr = $this->userWith("plh{$t}", ['leave.approve']);
        $hr2 = $this->userWith("plk{$t}", ['leave.approve']);
        $manager = $this->staff($w, ['userId' => $mgrUser]);
        $employee = $this->staff($w, ['userId' => $empUser, 'managerId' => $manager['id']]);
        $other = $this->staff($w, ['userId' => $otherUser]);
        $annual = $this->leaveType(['requiresAllocation' => true, 'approval' => 'both', 'yearlyDays' => 21]);
        $sick = $this->leaveType(['approval' => 'none']);

        return self::$s = compact('w', 'mgrUser', 'empUser', 'otherUser', 'hr', 'hr2', 'manager', 'employee', 'other', 'annual', 'sick');
    }

    private function balance(string $user, string $typeId, ?string $employee = null): array
    {
        $rows = $this->expectOk($this->getAs($user, '/api/payroll/leave-balances?year=2026'.($employee ? "&employee={$employee}" : '')))['balances'];

        return array_values(array_filter($rows, fn ($r) => $r['type']['id'] === $typeId))[0];
    }

    public function test_a_request_goes_manager_then_hr_and_the_ledger_follows(): void
    {
        $s = $this->world();
        // five days granted by hand (the ledger's first line)
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leave-allocations', ['employee' => $s['employee']['id'], 'type' => $s['annual']['id'], 'year' => 2026, 'days' => 5]), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs($s['hr'], '/api/payroll/leave-allocations', ['employee' => $s['employee']['id'], 'type' => $s['annual']['id'], 'year' => 2026, 'days' => 5, 'note' => 'رصيد افتتاحي']));
        $this->assertEquals(5.0, $this->balance($s['empUser'], $s['annual']['id'])['available']);

        // more than the balance is refused, and nothing is left behind
        $before = DB::table('pay_leaves')->where('employee_id', $s['employee']['id'])->count();
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['annual']['id'], 'dateFrom' => '2026-10-04', 'dateTo' => '2026-10-11', 'submit' => true]), 'LEAVE_BALANCE', [422]);
        $this->assertSame($before, DB::table('pay_leaves')->where('employee_id', $s['employee']['id'])->count());

        // Sunday 4 to Thursday 8 October: five working days; Friday and Saturday are not counted
        $l = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['annual']['id'], 'dateFrom' => '2026-10-04', 'dateTo' => '2026-10-10', 'reason' => 'سفر عائلي خاص', 'submit' => true]));
        $this->assertEquals(['submitted', 5.0, true], [$l['state'], $l['days'], $l['mine']]);
        $this->assertFalse($l['can']['approve'], 'nobody decides their own leave');
        $this->assertEquals(0.0, $this->balance($s['empUser'], $s['annual']['id'])['available'], 'what waits counts against the balance');
        $this->assertEquals(5.0, $this->balance($s['empUser'], $s['annual']['id'])['balance'], '… but is not in the ledger yet');

        // the wrong people
        $this->expectRejected($this->postAs($s['empUser'], "/api/payroll/leaves/{$l['id']}/approve"), 'LEAVE_SELF_APPROVAL', [422]);
        $this->expectRejected($this->postAs($s['otherUser'], "/api/payroll/leaves/{$l['id']}/approve"), 'LEAVE_NOT_YOURS_TO_DECIDE', [403]);
        $this->expectRejected($this->getAs($s['otherUser'], "/api/payroll/leaves/{$l['id']}"), 'PAYROLL_PRIVATE', [403]);
        $this->expectRejected($this->getAs($s['empUser'], '/api/payroll/leaves?scope=all'), 'FORBIDDEN', [403]);

        // the manager sees it waiting for him and takes the first step
        $waiting = $this->expectOk($this->getAs($s['mgrUser'], '/api/payroll/leaves?scope=waiting'));
        $this->assertSame([$l['id']], array_column($waiting['items'], 'id'));
        $this->assertSame('first', $waiting['items'][0]['can']['step']);
        $l = $this->expectOk($this->postAs($s['mgrUser'], "/api/payroll/leaves/{$l['id']}/approve", ['note' => 'موافق']));
        $this->assertSame('manager_approved', $l['state']);
        $this->expectRejected($this->postAs($s['mgrUser'], "/api/payroll/leaves/{$l['id']}/approve"), 'LEAVE_SECOND_APPROVER', [422]);
        $this->expectRejected($this->postAs($s['otherUser'], "/api/payroll/leaves/{$l['id']}/approve"), 'LEAVE_NOT_YOURS_TO_DECIDE', [403]);
        $this->expectRejected($this->postAs($s['empUser'], "/api/payroll/leaves/{$l['id']}/withdraw"), 'LEAVE_STATE', [422]);

        // HR takes the final step: the days leave the ledger
        $l = $this->expectOk($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/approve"));
        $this->assertSame(['approved', $s['hr']], [$l['state'], $l['decidedBy']]);
        $b = $this->balance($s['empUser'], $s['annual']['id']);
        $this->assertEquals([5.0, 5.0, 0.0, 0.0], [$b['allocated'], $b['taken'], $b['balance'], $b['available']]);
        $this->assertEquals([-5.0], LeaveEntry::where('leave_id', $l['id'])->pluck('days')->map(fn ($d) => (float) $d)->all());
        $this->assertSame(['draft', 'submitted', 'manager_approved', 'approved'], array_column($l['history'], 'to'));

        // an overlapping request is refused; illegal moves are refused with their code
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['sick']['id'], 'dateFrom' => '2026-10-06']), 'LEAVE_OVERLAP', [422]);
        $this->expectRejected($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/refuse", ['reason' => 'متأخر جدًا']), 'LEAVE_STATE', [422]);
        $this->expectRejected($this->postAs($s['empUser'], "/api/payroll/leaves/{$l['id']}/cancel", ['reason' => 'تغيّرت الخطط']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/cancel"), 'REASON_REQUIRED', [400]);

        // cancelling the approved leave gives the days back — a new line, the old one stays
        $l = $this->expectOk($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/cancel", ['reason' => 'أُلغيت الرحلة']));
        $this->assertSame('cancelled', $l['state']);
        $this->assertEquals(5.0, $this->balance($s['empUser'], $s['annual']['id'])['available']);
        $this->assertEqualsCanonicalizing(['taken', 'restored'], LeaveEntry::where('leave_id', $l['id'])->pluck('kind')->all());
        $this->expectRejected($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/cancel", ['reason' => 'مرة ثانية']), 'LEAVE_TRANSITION', [422]);

        // the ledger reconciles with the leaves; an employee cannot ask for that
        $rec = $this->expectOk($this->getAs($s['hr'], '/api/payroll/leave-ledger/reconcile'));
        $this->assertTrue($rec['ok'], json_encode($rec['problems']));
        $this->assertSame(403, $this->getAs($s['empUser'], '/api/payroll/leave-ledger/reconcile')->getStatusCode());

        // the trail keeps the move, never the person's reason
        $this->assertFalse(DB::table('audit_logs')->where('entity_id', $l['id'])->where(fn ($q) => $q->where('new_value', 'like', '%سفر عائلي%')->orWhere('old_value', 'like', '%سفر عائلي%'))->exists());
    }

    public function test_the_two_steps_need_two_people_and_a_refusal_needs_a_reason(): void
    {
        $s = $this->world();
        // HR may take the first step of a two-step type, but then another holder of leave.approve decides
        $l = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['annual']['id'], 'dateFrom' => '2026-10-18', 'dateTo' => '2026-10-18', 'halfDay' => true, 'submit' => true]));
        $this->assertEquals(0.5, $l['days']);
        $l = $this->expectOk($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/approve"));
        $this->assertSame('manager_approved', $l['state']);
        $this->expectRejected($this->postAs($s['hr'], "/api/payroll/leaves/{$l['id']}/approve"), 'LEAVE_SECOND_APPROVER', [422]);
        $this->expectRejected($this->postAs($s['hr2'], "/api/payroll/leaves/{$l['id']}/refuse", ['reason' => 'لا']), 'INVALID_INPUT', [400]);
        $l = $this->expectOk($this->postAs($s['hr2'], "/api/payroll/leaves/{$l['id']}/refuse", ['reason' => 'ضغط عمل في نهاية الشهر']));
        $this->assertSame('refused', $l['state']);
        $this->assertEquals(0.0, LeaveEntry::where('leave_id', $l['id'])->sum('days') + 0.0, 'a refused leave never touched the ledger');

        // a draft is edited, sent, withdrawn, edited again — only by its owner or a decider
        $d = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['annual']['id'], 'dateFrom' => '2026-10-25', 'dateTo' => '2026-10-26']));
        $this->assertEquals(['draft', 2.0], [$d['state'], $d['days']]);
        $this->expectRejected($this->patchAs($s['otherUser'], "/api/payroll/leaves/{$d['id']}", ['dateTo' => '2026-10-27']), 'LEAVE_NOT_YOURS', [403]);
        $d = $this->expectOk($this->patchAs($s['empUser'], "/api/payroll/leaves/{$d['id']}", ['dateTo' => '2026-10-27']));
        $this->assertEquals(3.0, $d['days']);
        $d = $this->expectOk($this->postAs($s['empUser'], "/api/payroll/leaves/{$d['id']}/submit"));
        $this->expectRejected($this->patchAs($s['empUser'], "/api/payroll/leaves/{$d['id']}", ['dateTo' => '2026-10-28']), 'LEAVE_STATE', [422]);
        $d = $this->expectOk($this->postAs($s['empUser'], "/api/payroll/leaves/{$d['id']}/withdraw"));
        $this->assertSame('draft', $d['state']);
        $d = $this->expectOk($this->postAs($s['empUser'], "/api/payroll/leaves/{$d['id']}/cancel"));
        $this->assertSame('cancelled', $d['state']);
    }

    public function test_days_follow_the_schedule_and_the_holidays_and_a_type_without_approval_is_approved_at_once(): void
    {
        $s = $this->world();
        // a public holiday for the story's department only, on Sunday 1 November
        $this->expectOk($this->postAs('admin', '/api/payroll/holidays', ['nameAr' => 'عطلة اختبار '.self::tag(), 'dateFrom' => '2026-11-01', 'scope' => 'department', 'scopeIds' => [$s['w']['department']]]));
        $l = $this->expectOk($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['sick']['id'], 'dateFrom' => '2026-11-01', 'dateTo' => '2026-11-07', 'submit' => true]));
        $this->assertEquals(['approved', 4.0], [$l['state'], $l['days']], 'Sunday is a holiday, Friday and Saturday are the weekend');
        $this->assertEquals([-4.0], LeaveEntry::where('leave_id', $l['id'])->pluck('days')->map(fn ($d) => (float) $d)->all());
        // only weekend days: nothing to take
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['sick']['id'], 'dateFrom' => '2026-11-13', 'dateTo' => '2026-11-14', 'submit' => true]), 'LEAVE_NO_WORKING_DAYS', [422]);
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leaves', ['type' => $s['sick']['id'], 'dateFrom' => '2026-11-20', 'dateTo' => '2026-11-18']), 'LEAVE_DATES', [400]);
        $this->expectRejected($this->postAs($s['empUser'], '/api/payroll/leaves', ['employee' => $s['other']['id'], 'type' => $s['sick']['id'], 'dateFrom' => '2026-11-22']), 'LEAVE_ON_BEHALF', [403]);

        // the calendar shows the caller's own and their team's; the manager sees his report
        $cal = $this->expectOk($this->getAs($s['mgrUser'], '/api/payroll/leaves/calendar?from=2026-11-01&to=2026-11-30'));
        $this->assertContains($l['id'], array_column($cal['items'], 'id'));
        $cal = $this->expectOk($this->getAs($s['otherUser'], '/api/payroll/leaves/calendar?from=2026-11-01&to=2026-11-30'));
        $this->assertNotContains($l['id'], array_column($cal['items'], 'id'));
        $this->expectRejected($this->getAs($s['mgrUser'], '/api/payroll/leaves/calendar?from=2026-01-01&to=2026-12-31'), 'RANGE_TOO_LONG', [400]);
    }

    public function test_the_yearly_allowance_is_granted_once(): void
    {
        $s = $this->world();
        $type = $this->leaveType(['requiresAllocation' => true, 'yearlyDays' => 12.5]);
        $first = $this->expectOk($this->postAs($s['hr'], '/api/payroll/leave-allocations/yearly', ['year' => 2027, 'type' => $type['id']]));
        $this->assertGreaterThanOrEqual(3, $first['created']);
        $again = $this->expectOk($this->postAs($s['hr'], '/api/payroll/leave-allocations/yearly', ['year' => 2027, 'type' => $type['id']]));
        $this->assertSame([0, $first['created']], [$again['created'], $again['existing']]);
        $rows = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/leave-balances?year=2027'))['balances'];
        $mine = array_values(array_filter($rows, fn ($r) => $r['type']['id'] === $type['id']))[0];
        $this->assertEquals(12.5, $mine['balance']);
        // an adjustment needs a reason and may not push a type below zero
        $this->expectRejected($this->postAs($s['hr'], '/api/payroll/leave-allocations', ['employee' => $s['employee']['id'], 'type' => $type['id'], 'year' => 2027, 'days' => -2, 'kind' => 'adjustment']), 'REASON_REQUIRED', [400]);
        $this->expectRejected($this->postAs($s['hr'], '/api/payroll/leave-allocations', ['employee' => $s['employee']['id'], 'type' => $type['id'], 'year' => 2027, 'days' => -20, 'kind' => 'adjustment', 'note' => 'تصحيح رصيد خاطئ']), 'LEAVE_BALANCE', [422]);
        $this->expectOk($this->postAs($s['hr'], '/api/payroll/leave-allocations', ['employee' => $s['employee']['id'], 'type' => $type['id'], 'year' => 2027, 'days' => -2.5, 'kind' => 'adjustment', 'note' => 'تصحيح رصيد خاطئ']));
        $entries = $this->expectOk($this->getAs($s['empUser'], "/api/payroll/leave-entries?type={$type['id']}&year=2027"));
        $this->assertEquals([2, 10.0], [$entries['total'], $entries['totals']['days']]);
        // somebody else's ledger is not the employee's to read
        $this->assertSame(0, $this->expectOk($this->getAs($s['otherUser'], "/api/payroll/leave-entries?employee={$s['employee']['id']}"))['total']);
        $this->expectRejected($this->getAs($s['otherUser'], "/api/payroll/leave-balances?employee={$s['employee']['id']}"), 'PAYROLL_PRIVATE', [403]);
    }
}
