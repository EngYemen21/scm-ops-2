<?php

namespace Tests\Feature\Payroll;

use App\Payroll\Models\Component;
use App\Payroll\Services\PayrollEngine;
use App\Payroll\Services\Rates;
use App\Payroll\Support\Money;
use App\Support\AppError;
use Tests\ApiTestCase;

/**
 * The arithmetic of one payslip, without HTTP: exact to the halala, every figure explained, the lines adding up to the
 * net. All pay here is invented. Rates and parameters are the engine's starting rows (month_days 30, unpaid leave and
 * attendance on the gross / basic, GOSI `saudi` 9.75 % + 11.75 % on basic + housing between 1,500 and 45,000).
 */
class PayrollEngineTest extends ApiTestCase
{
    use PayrollFixtures;

    private function slip(array $in): array
    {
        return app(PayrollEngine::class)->compute($in + [
            'period' => '2026-08',
            'components' => Component::where('active', true)->whereNull('source_system')->orderBy('sequence')->get()->keyBy('code'),
            'dayMinutes' => 480,
        ]);
    }

    private static function contract(array $more = []): array
    {
        return $more + ['id' => 'C1', 'number' => 'HRC-TEST-1', 'start' => '2026-01-01', 'end' => null, 'wage' => 6000, 'housing' => 1500, 'transport' => 500, 'other' => 0];
    }

    /** @return array<string, string> code → total as the payslip carries it */
    private static function lines(array $out): array
    {
        $map = [];
        foreach ($out['lines'] as $l) {
            $map[$l['code']] = Money::s($l['total']);
        }

        return $map;
    }

    private function assertAddsUp(array $out): void
    {
        $sum = 0;
        foreach ($out['lines'] as $l) {
            if (in_array($l['category'], ['basic', 'allowance', 'deduction', 'reimbursement'], true)) {
                $sum += $l['total'];
            }
        }
        $this->assertSame($out['totals']['net'], $sum, 'the lines add up to the net to the halala');
        $net = array_values(array_filter($out['lines'], fn ($l) => $l['code'] === 'NET'))[0]['total'];
        $this->assertSame($out['totals']['net'], $net);
    }

    public function test_a_whole_month_with_social_insurance(): void
    {
        $out = $this->slip(['contract' => self::contract(), 'gosiScheme' => 'saudi']);
        $this->assertSame(['BASIC' => '6000.00', 'HOUSING' => '1500.00', 'TRANSPORT' => '500.00', 'GROSS' => '8000.00', 'GOSI_EE' => '-731.25', 'GOSI_ER' => '881.25', 'NET' => '7268.75'], self::lines($out));
        $this->assertSame(['basic' => 600000, 'gross' => 800000, 'deductions' => 73125, 'reimbursements' => 0, 'employer_cost' => 88125, 'net' => 726875], $out['totals']);
        $this->assertAddsUp($out);
        // the figure explains itself: which rate row, which base, which days
        $this->assertSame(['saudi', '2020-01-01', '7500.00'], [$out['explain']['gosi']['scheme'], $out['explain']['gosi']['effectiveFrom'], $out['explain']['gosi']['base']]);
        $this->assertSame(['daysCovered' => 31, 'daysInMonth' => 31], array_intersect_key($out['explain']['proration'], ['daysCovered' => 1, 'daysInMonth' => 1]));
        // the same inputs give the same payslip
        $this->assertSame($out, $this->slip(['contract' => self::contract(), 'gosiScheme' => 'saudi']));
    }

    public function test_a_contract_that_starts_in_the_month_is_paid_for_its_days(): void
    {
        $out = $this->slip(['contract' => self::contract(['start' => '2026-08-10']), 'gosiScheme' => 'saudi']);
        // 22 of 31 days: 6000×22/31 = 4258.0645… · 1500×22/31 = 1064.5161… · 500×22/31 = 354.8387… — each rounded once
        $this->assertSame(['4258.06', '1064.52', '354.84', '5677.42'], [self::lines($out)['BASIC'], self::lines($out)['HOUSING'], self::lines($out)['TRANSPORT'], self::lines($out)['GROSS']]);
        // GOSI base 7500×22/31 = 5322.58; 9.75 % = 518.95 · 11.75 % = 625.40
        $this->assertSame(['-518.95', '625.40'], [self::lines($out)['GOSI_EE'], self::lines($out)['GOSI_ER']]);
        $this->assertAddsUp($out);
        $this->assertSame(['from' => '2026-08-10', 'to' => '2026-08-31', 'days' => 22, 'daysInMonth' => 31], $out['coverage']);
    }

    public function test_unpaid_leave_attendance_adjustments_and_claims(): void
    {
        $out = $this->slip([
            'contract' => self::contract(),
            'unpaidLeaves' => [['number' => 'LVE-T-1', 'from' => '2026-08-02', 'to' => '2026-08-03', 'half' => false], ['number' => 'LVE-T-2', 'from' => '2026-08-20', 'to' => '2026-08-20', 'half' => true]],
            'sheets' => [['id' => 'S1', 'number' => 'ATS-T-1', 'overtime' => 120, 'late' => 30, 'early' => 45, 'absence' => 480, 'absenceDays' => 1]],
            'adjustments' => [['id' => 'A1', 'code' => 'BONUS', 'amount' => '250.50'], ['id' => 'A2', 'code' => 'LOAN', 'amount' => '300']],
            'claims' => [['id' => 'CL1', 'number' => 'CLM-T-1', 'due' => '87.65']],
        ]);
        $l = self::lines($out);
        // unpaid: 8000 × 2.5 days / 30 = 666.666… → 666.67
        $this->assertSame('-666.67', $l['UNPAID']);
        // attendance on the basic: 6000 × minutes / (30 × 480)
        $this->assertSame(['50.00', '-200.00', '-12.50', '-18.75'], [$l['OVERTIME'], $l['ABSENCE'], $l['LATE'], $l['EARLY']]);
        $this->assertSame(['250.50', '-300.00', '87.65'], [$l['BONUS'], $l['LOAN'], $l['REIMB']]);
        $this->assertSame('8300.50', $l['GROSS']);           // 8000 + 50 overtime + 250.50 bonus
        $this->assertSame('7190.23', $l['NET']);             // 8300.50 − 666.67 − 200 − 12.50 − 18.75 − 300 + 87.65
        $this->assertArrayNotHasKey('GOSI_EE', $l, 'a structure without a scheme pays no social insurance');
        $this->assertAddsUp($out);
        $this->assertSame(['LVE-T-1', 'LVE-T-2'], $out['explain']['unpaidLeaves']);
        $this->assertSame(['ATS-T-1'], $out['explain']['sheets']);
        $this->assertSame(['CLM-T-1'], $out['explain']['claims']);
        $this->assertSame(2.5, $out['worked']['unpaidDays']);
    }

    public function test_time_not_worked_never_takes_more_than_the_month_earned(): void
    {
        $out = $this->slip(['contract' => self::contract(['start' => '2026-08-25']),
            'unpaidLeaves' => [['number' => 'LVE-T-3', 'from' => '2026-08-25', 'to' => '2026-08-31', 'half' => false]],
            'sheets' => [['id' => 'S2', 'number' => 'ATS-T-2', 'overtime' => 0, 'late' => 600, 'early' => 0, 'absence' => 0, 'absenceDays' => 0]]]);
        // a coverage that is unpaid whole loses exactly what it earned — and the lateness finds nothing left to take
        $this->assertSame('0.00', self::lines($out)['NET']);
        $this->assertArrayNotHasKey('LATE', self::lines($out));
        $this->assertContains('DEDUCTION_CAPPED', $out['warnings']);
        $this->assertAddsUp($out);
    }

    public function test_a_rate_is_configuration_with_a_date_and_a_missing_one_is_a_refusal(): void
    {
        $rates = app(Rates::class);
        $this->assertNull($rates->gosi('saudi', '2019-12-31'));
        try {
            app(PayrollEngine::class)->compute(['period' => '2019-12', 'contract' => self::contract(['start' => '2019-01-01']), 'gosiScheme' => 'saudi',
                'components' => Component::where('active', true)->whereNull('source_system')->get()->keyBy('code'), 'dayMinutes' => 480]);
            $this->fail('no social-insurance rate before 2020: the engine must refuse');
        } catch (AppError $e) {
            $this->assertSame('PAYROLL_GOSI_RATE_MISSING', $e->errorCode);
        }
        try {
            app(PayrollEngine::class)->compute(['period' => '2026-09', 'contract' => self::contract(['start' => '2026-10-01']), 'components' => collect(), 'dayMinutes' => 480]);
            $this->fail('a contract outside the month');
        } catch (AppError $e) {
            $this->assertSame('PAYROLL_NOT_IN_PERIOD', $e->errorCode);
        }
    }
}
