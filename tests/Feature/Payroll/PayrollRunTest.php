<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\Sheet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * The monthly run of one department for August 2026, end to end: payslips built from the contracts, the approved
 * attendance sheet, unpaid leave and an adjustment; review; approval by somebody else; a balanced journal through the
 * finance ledger; the bank transfer listing; payment; reversal. And the bar: exact to the halala, reproducible,
 * explained, a month never paid twice to one contract, an approved run immutable, rates with dates that do not move
 * under approved pay. Every person, wage and account number here is invented.
 *
 *   `admin` prepares the run (payroll.manage, gl.post) · `gm` approves and reverses (payroll.approve, gl.approve)
 *   `finance` posts and pays (payroll.manage, gl.post)
 */
class PayrollRunTest extends ApiTestCase
{
    use PayrollFixtures;

    private static array $s = [];

    private function world(): array
    {
        if (self::$s) {
            return self::$s;
        }
        $w = $this->payrollWorld();
        $empUser = $this->userWith('pre'.self::tag(), ['leave.request']);
        $a = $this->staff($w, ['userId' => $empUser]);
        $this->expectOk($this->putAs('admin', "/api/hr/employees/{$a['id']}/private", ['bankName' => 'بنك تجريبي', 'bankAccount' => 'SA0000TEST0000000000001']));
        $b = $this->staff($w, [], ['wage' => 4000, 'housingAllowance' => 1000, 'transportAllowance' => 0], '2026-08-10');
        $this->expectOk($this->putAs('admin', "/api/payroll/profiles/{$b['id']}", ['paymentMethod' => 'cash']));
        $this->staff($w, [], [], '2026-01-01', '2026-07-31');   // a contract that ended before August: not in the run

        // A worked two hours past the plan on Sunday 2 August → 180 paid minutes after the ×1.5 policy
        $this->presence($a['id'], '2026-08-02', '08:00', '19:00');
        $sheet = $this->expectOk($this->postAs('admin', '/api/payroll/sheets', ['employee' => $a['id'], 'dateFrom' => '2026-08-02', 'dateTo' => '2026-08-02']));
        $this->expectOk($this->postAs('admin', "/api/payroll/sheets/{$sheet['id']}/confirm"));
        $sheet = $this->expectOk($this->postAs('admin', "/api/payroll/sheets/{$sheet['id']}/approve"));
        $this->assertSame(180, $sheet['totals']['overtimeMinutes']);
        // one approved day of unpaid leave, entered on A's behalf
        $unpaid = $this->leaveType(['paid' => false, 'approval' => 'none']);
        $this->expectOk($this->postAs('admin', '/api/payroll/leaves', ['employee' => $a['id'], 'type' => $unpaid['id'], 'dateFrom' => '2026-08-11', 'submit' => true]));
        $bonus = $this->expectOk($this->postAs('admin', '/api/payroll/adjustments', ['employee' => $a['id'], 'period' => '2026-08', 'component' => 'BONUS', 'amount' => 250, 'reason' => 'مكافأة أداء الربع']));

        return self::$s = ['w' => $w, 'empUser' => $empUser, 'a' => $a, 'b' => $b, 'sheet' => $sheet, 'bonus' => $bonus, 'bank' => $this->bankAccount()];
    }

    private static function slipOf(array $run, string $employeeId): array
    {
        return array_values(array_filter($run['payslips'], fn ($p) => $p['employee']['id'] === $employeeId))[0];
    }

    private static function lines(array $slip): array
    {
        $out = [];
        foreach ($slip['lines'] as $l) {
            $out[$l['code']] = $l['total'];
        }

        return $out;
    }

    public function test_the_month_from_draft_to_paid_and_reversed(): void
    {
        $s = $this->world();
        $dept = $s['w']['department'];
        $this->expectRejected($this->getAs($s['empUser'], '/api/payroll/runs'), null, [403]);
        $this->expectRejected($this->postAs('gm', '/api/payroll/runs', ['period' => '2026-08', 'department' => $dept]), null, [403]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-13', 'department' => $dept]), 'INVALID_INPUT', [400]);

        // ── the draft: one payslip per contract in force, figures exact and explained
        $run = $this->expectOk($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-08', 'department' => $dept]));
        $this->assertSame(['draft', 2, 0], [$run['state'], $run['slipCount'], $run['skippedCount']]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-08', 'department' => $dept]), 'PAYRUN_OPEN_EXISTS', [422]);
        $a = $this->expectOk($this->getAs('admin', '/api/payroll/payslips/'.self::slipOf($run, $s['a']['id'])['id']));
        // 6000 + 1500 + 500 + overtime 6000×180/(30×480) = 75 + bonus 250 = 8325; unpaid day 8000/30 = 266.67; GOSI 9.75 % of 7500
        $this->assertSame(['BASIC' => '6000.00', 'HOUSING' => '1500.00', 'TRANSPORT' => '500.00', 'OVERTIME' => '75.00', 'BONUS' => '250.00', 'GROSS' => '8325.00',
            'UNPAID' => '-266.67', 'GOSI_EE' => '-731.25', 'GOSI_ER' => '881.25', 'NET' => '7327.08'], array_intersect_key(self::lines($a), array_flip(['BASIC', 'HOUSING', 'TRANSPORT', 'OVERTIME', 'BONUS', 'GROSS', 'UNPAID', 'GOSI_EE', 'GOSI_ER', 'NET'])));
        $this->assertCount(10, $a['lines']);
        $this->assertSame([$s['sheet']['number']], $a['explain']['sheets']);
        $this->assertSame(1, $a['worked']['unpaidDays']);
        $b = $this->expectOk($this->getAs('admin', '/api/payroll/payslips/'.self::slipOf($run, $s['b']['id'])['id']));
        // from the 10th: 22 of 31 days — 4000×22/31 = 2838.71, 1000×22/31 = 709.68; GOSI base 5000×22/31 = 3548.39 → 345.97 / 416.94
        $this->assertSame(['2838.71', '709.68', '3548.39', '-345.97', '416.94', '3202.42'], array_values(array_intersect_key(self::lines($b), array_flip(['BASIC', 'HOUSING', 'GROSS', 'GOSI_EE', 'GOSI_ER', 'NET']))));
        $this->assertSame(['10529.50', '11873.39', '1298.19'], [$run['net'], $run['gross'], $run['employerCost']]);
        foreach ([$a, $b] as $slip) { // the lines add up to the net to the halala
            $sum = array_sum(array_map(fn ($l) => in_array($l['category'], ['basic', 'allowance', 'deduction', 'reimbursement'], true) ? (int) round($l['total'] * 100) : 0, $slip['lines']));
            $this->assertSame((int) round($slip['net'] * 100), $sum);
        }

        // ── the employee sees nothing of a draft
        $this->assertSame(0, $this->expectOk($this->getAs($s['empUser'], '/api/payroll/payslips'))['total']);
        $this->expectRejected($this->getAs($s['empUser'], "/api/payroll/payslips/{$a['id']}"), 'PAYROLL_PRIVATE', [403]);
        $this->expectRejected($this->getAs($s['empUser'], '/api/payroll/payslips?scope=all'), 'PAYROLL_PRIVATE', [403]);

        // ── review; the inputs change behind it → the approval refuses a stale run
        $run = $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$run['id']}/submit"));
        $this->assertSame('review', $run['state']);
        $this->expectRejected($this->postAs('admin', "/api/payroll/runs/{$run['id']}/compute"), 'PAYRUN_STATE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/payroll/runs/{$run['id']}/approve"), 'PAYRUN_SELF_APPROVAL', [422]);
        $penalty = $this->expectOk($this->postAs('admin', '/api/payroll/adjustments', ['employee' => $s['b']['id'], 'period' => '2026-08', 'component' => 'PENALTY', 'amount' => 100, 'reason' => 'مخالفة نظام العمل']));
        $stale = $this->expectRejected($this->postAs('gm', "/api/payroll/runs/{$run['id']}/approve"), 'PAYRUN_STALE', [422]);
        $this->assertStringContainsString(self::slipOf($run, $s['b']['id'])['number'], json_encode($stale, JSON_UNESCAPED_UNICODE), 'the refusal names the payslip that changed');
        $this->expectRejected($this->postAs('gm', "/api/payroll/runs/{$run['id']}/return"), 'INVALID_INPUT', [400]);
        $run = $this->expectOk($this->postAs('gm', "/api/payroll/runs/{$run['id']}/return", ['reason' => 'تسوية جديدة بعد الإرسال']));
        $run = $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$run['id']}/compute"));
        $this->assertSame('10429.50', $run['net']);
        $run = $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$run['id']}/submit"));

        // ── approved by another person: immutable from here on
        $run = $this->expectOk($this->postAs('gm', "/api/payroll/runs/{$run['id']}/approve", ['note' => 'مراجَع']));
        $this->assertSame(['approved', 'gm'], [$run['state'], $run['approvedBy']]);
        $this->assertSame(['approved', 'approved'], array_column($run['payslips'], 'state'));
        $this->assertSame(['applied', 'applied'], Adjustment::whereIn('id', [$s['bonus']['id'], $penalty['id']])->pluck('state')->all());
        $this->assertSame(self::slipOf($run, $s['a']['id'])['id'], Sheet::find($s['sheet']['id'])->payslip_id);
        $this->expectRejected($this->postAs('admin', "/api/payroll/runs/{$run['id']}/compute"), 'PAYRUN_STATE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/payroll/runs/{$run['id']}/cancel"), 'PAYRUN_TRANSITION', [422]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/adjustments', ['employee' => $s['a']['id'], 'period' => '2026-08', 'component' => 'BONUS', 'amount' => 10, 'reason' => 'إضافة متأخرة']), 'ADJUSTMENT_PERIOD_CLOSED', [422]);
        $this->expectRejected($this->postAs('admin', "/api/payroll/adjustments/{$penalty['id']}/cancel", ['reason' => 'أُلغيت المخالفة']), 'ADJUSTMENT_STATE', [422]);
        $this->expectRejected($this->postAs('admin', "/api/payroll/sheets/{$s['sheet']['id']}/reopen", ['reason' => 'مراجعة الإضافي']), 'SHEET_IN_PAYROLL', [422]);
        // rates are dated: nothing may be slipped in behind approved pay, nor a rate it used changed
        $this->expectRejected($this->postAs('admin', '/api/payroll/gosi-rates', ['scheme' => 'saudi', 'effectiveFrom' => '2026-08-01', 'employeePct' => 10, 'employerPct' => 12]), 'RATE_RETROACTIVE', [422]);
        $this->expectRejected($this->postAs('admin', '/api/payroll/parameters', ['code' => 'month_days', 'value' => '31', 'effectiveFrom' => '2026-08-15']), 'RATE_RETROACTIVE', [422]);

        // ── the employee now sees his own payslip, and only his
        $mine = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/payslips'));
        $this->assertSame([self::slipOf($run, $s['a']['id'])['id']], array_column($mine['items'], 'id'));
        $this->assertSame('7327.08', $this->expectOk($this->getAs($s['empUser'], "/api/payroll/payslips/{$a['id']}"))['net']);
        $this->expectRejected($this->getAs($s['empUser'], "/api/payroll/payslips/{$b['id']}"), 'PAYROLL_PRIVATE', [403]);

        // ── never twice: a second run of the month leaves both contracts out and says why; the database refuses a copy
        $second = $this->expectOk($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-08', 'department' => $dept]));
        $this->assertSame([0, ['ALREADY_PAID', 'ALREADY_PAID']], [$second['slipCount'], array_column($second['skipped'], 'reason')]);
        $this->expectRejected($this->postAs('admin', "/api/payroll/runs/{$second['id']}/submit"), 'PAYRUN_EMPTY', [422]);
        $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$second['id']}/cancel", ['reason' => 'لا شيء يُدفع']));
        $copy = Payslip::findOrFail($a['id'])->replicate();
        $copy->number = 'PSL-TEST-'.self::tag();
        try {
            $copy->save();
            $this->fail('a second standing payslip of one contract and month must be impossible');
        } catch (UniqueConstraintViolationException) {
            $this->assertTrue(true);
        }

        // ── the journal: balanced, read before it is written
        $preview = $this->expectOk($this->getAs('finance', "/api/payroll/runs/{$run['id']}/journal"));
        $this->assertSame([true, []], [$preview['balanced'], $preview['missing']]);
        $this->assertSame($preview['debit'], $preview['credit']);
        $this->expectRejected($this->postAs('gm', "/api/payroll/runs/{$run['id']}/post"), null, [403]);
        $run = $this->expectOk($this->postAs('finance', "/api/payroll/runs/{$run['id']}/post", ['date' => '2026-09-15']));
        $this->assertSame(['posted', 'ops'], [$run['state'], $run['books']]);
        $journal = DB::table('fin_journals')->where('number', $run['journalNumber'])->first();
        $lines = DB::table('fin_journal_lines')->where('journal_id', $journal->id)->selectRaw('coalesce(sum(debit), 0) as d, coalesce(sum(credit), 0) as c')->first();
        $this->assertSame([number_format((float) $journal->total_debit, 2, '.', ''), number_format((float) $lines->d, 2, '.', '')], [number_format((float) $journal->total_credit, 2, '.', ''), number_format((float) $lines->c, 2, '.', '')]);
        $this->assertSame($preview['debit'], number_format((float) $journal->total_debit, 2, '.', ''));
        // the expense side: what was earned less time not worked + the employer's share = net + deductions + employer's share owed
        // debit: earned 11873.39 − the unpaid day 266.67 + the employer's share 1298.19 = 12904.91
        // credit: net 10429.50 + both GOSI shares (1077.22 + 1298.19) + the penalty 100 = 12904.91
        $this->assertSame('12904.91', $preview['debit']);
        $this->expectRejected($this->postAs('finance', "/api/payroll/runs/{$run['id']}/post"), 'PAYRUN_TRANSITION', [422]);

        // ── the bank listing: account numbers only here, only for payroll.manage, never in the trail
        $this->assertSame(403, $this->getAs('gm', "/api/payroll/runs/{$run['id']}/transfer")->getStatusCode());
        $t = $this->expectOk($this->getAs('finance', "/api/payroll/runs/{$run['id']}/transfer"));
        $this->assertSame([1, '7327.08', 1, '3102.42', 0], [$t['totals']['bank']['count'], $t['totals']['bank']['amount'], $t['totals']['cash']['count'], $t['totals']['cash']['amount'], $t['totals']['missingBank']['count']]);
        $csv = $this->getAs('finance', "/api/payroll/runs/{$run['id']}/transfer.csv");
        $this->assertSame(200, $csv->getStatusCode());
        $this->assertStringContainsString('SA0000TEST0000000000001', $csv->getContent());
        $this->assertStringContainsString('7327.08', $csv->getContent());
        $this->assertStringNotContainsString('3102.42', $csv->getContent(), 'a cash payment is not in the bank file');
        $this->assertFalse(DB::table('audit_logs')->where('new_value', 'like', '%SA0000TEST%')->exists(), 'no account number in the trail');
        $this->assertStringNotContainsString('SA0000TEST', json_encode($this->expectOk($this->getAs('finance', "/api/payroll/runs/{$run['id']}"))));

        // ── paid, taken back, reversed
        $this->expectRejected($this->postAs('finance', "/api/payroll/runs/{$run['id']}/pay", ['bankAccount' => $s['bank']]), 'INVALID_INPUT', [400]);
        $run = $this->expectOk($this->postAs('finance', "/api/payroll/runs/{$run['id']}/pay", ['bankAccount' => $s['bank'], 'reference' => 'TRF-AUG-1', 'date' => '2026-09-16']));
        $this->assertSame(['paid', 'TRF-AUG-1'], [$run['state'], $run['paymentRef']]);
        $this->assertNotNull($run['paymentJournalNumber']);
        $this->expectRejected($this->postAs('gm', "/api/payroll/runs/{$run['id']}/reverse", ['reason' => 'خطأ في المسير']), 'PAYRUN_PAID', [422]);
        $run = $this->expectOk($this->postAs('finance', "/api/payroll/runs/{$run['id']}/unpay", ['reason' => 'التحويل لم يُنفَّذ في البنك']));
        $this->assertSame('posted', $run['state']);
        $run = $this->expectOk($this->postAs('gm', "/api/payroll/runs/{$run['id']}/reverse", ['reason' => 'خطأ في بيانات المسير']));
        $this->assertSame('reversed', $run['state']);
        $this->assertNotNull($run['reversalJournalNumber']);
        $this->assertSame(['pending', 'pending'], Adjustment::whereIn('id', [$s['bonus']['id'], $penalty['id']])->pluck('state')->all(), 'what the run carried waits for the next one');
        $this->assertNull(Sheet::find($s['sheet']['id'])->payslip_id);
        $this->assertSame(0, Payslip::where('run_id', $run['id'])->whereNotNull('live_key')->count());
        $this->assertSame(['reversed', 'reversed'], array_column($run['payslips'], 'state'), 'nothing is deleted: the reversed payslips stay readable');

        // the month is free again: a new run pays both contracts, to the same figures
        $again = $this->expectOk($this->postAs('admin', '/api/payroll/runs', ['period' => '2026-08', 'department' => $dept]));
        $this->assertSame([2, '10429.50'], [$again['slipCount'], $again['net']]);
        $this->expectOk($this->postAs('admin', "/api/payroll/runs/{$again['id']}/cancel", ['reason' => 'نهاية الاختبار']));
    }

    public function test_lists_and_the_summary_answer_each_caller_with_their_part(): void
    {
        $s = $this->world();
        $runs = $this->expectOk($this->getAs('gm', "/api/payroll/runs?department={$s['w']['department']}&sort=created&order=asc"));
        $this->assertGreaterThanOrEqual(1, $runs['total']);
        $this->assertSame(array_column($runs['items'], 'id'), array_column($this->expectOk($this->getAs('gm', "/api/payroll/runs?department={$s['w']['department']}&sort=created&order=asc"))['items'], 'id'));
        $summary = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/summary'));
        $this->assertTrue($summary['me']['linked']);
        $this->assertSame($s['a']['id'], $summary['me']['employee']['id']);
        $this->assertArrayNotHasKey('payroll', $summary);
        $this->assertArrayHasKey('payroll', $this->expectOk($this->getAs('finance', '/api/payroll/summary')));
        $lookups = $this->expectOk($this->getAs($s['empUser'], '/api/payroll/lookups'));
        $this->assertSame([$s['a']['id']], array_column($lookups['employees'], 'id'), 'a person picks only himself');
        $this->assertArrayNotHasKey('bankAccounts', $lookups);
        $this->expectRejected($this->getAs($s['empUser'], "/api/payroll/runs/{$runs['items'][0]['id']}/journal"), null, [403]);
    }
}
