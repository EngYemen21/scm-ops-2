<?php

namespace Tests\Feature\Commissions;

use App\Commissions\Services\CalculationService;
use App\Commissions\Support\Period;
use App\Commissions\Support\Tiers;
use App\Support\AppError;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic of the section, without a database: tiers (the tier a total reaches, slices each at their own rate)
 * and the rates of a statement's rows — flat, tiered on the whole, tiered progressively, exclusions, credit notes.
 */
class CommissionMathTest extends TestCase
{
    private const TIERS = [['upTo' => 100000.0, 'rate' => 1.0], ['upTo' => 250000.0, 'rate' => 1.5], ['upTo' => null, 'rate' => 2.0]];

    public function test_tiers_are_checked_and_put_in_order(): void
    {
        $this->assertSame(self::TIERS, Tiers::normalise([['upTo' => null, 'rate' => 2], ['upTo' => '250000', 'rate' => 1.5], ['upTo' => 100000, 'rate' => '1']]));
        $this->assertSame([['upTo' => null, 'rate' => 0.0]], Tiers::normalise([['rate' => 0]]), 'one open tier is a flat rate; zero is a rate');
        $bad = [
            'no tier' => [],
            'no open tier' => [['upTo' => 100, 'rate' => 1]],
            'two open tiers' => [['upTo' => null, 'rate' => 1], ['rate' => 2]],
            'a rate above 100' => [['upTo' => null, 'rate' => 120]],
            'a negative rate' => [['upTo' => null, 'rate' => -1]],
            'a ceiling of zero' => [['upTo' => 0, 'rate' => 1], ['upTo' => null, 'rate' => 2]],
            'the same ceiling twice' => [['upTo' => 500, 'rate' => 1], ['upTo' => 500, 'rate' => 1.5], ['upTo' => null, 'rate' => 2]],
            'a rate that is not a number' => [['upTo' => null, 'rate' => 'x']],
        ];
        foreach ($bad as $what => $tiers) {
            try {
                Tiers::normalise($tiers, 'rules.0.tiers');
                $this->fail("expected a refusal: {$what}");
            } catch (AppError $e) {
                $this->assertSame(['COMMISSION_TIERS', 400], [$e->errorCode, $e->status()], $what);
                $this->assertStringStartsWith('rules.0.tiers', $e->details[0]['path'], $what);
            }
        }
    }

    public function test_a_total_reaches_one_tier_and_slices_are_paid_each_at_their_own_rate(): void
    {
        // an amount exactly on a ceiling belongs to the tier below it; nothing or less sits in the first tier
        $this->assertSame([1.0, 1.0, 1.0, 1.5, 1.5, 2.0, 1.0], array_map(fn (float $x) => Tiers::rateAt(self::TIERS, $x), [0.0, 99999.99, 100000.0, 100000.01, 250000.0, 250000.01, -5000.0]));
        $this->assertSame(['index' => 1, 'from' => 100000.0, 'upTo' => 250000.0, 'rate' => 1.5], Tiers::tierAt(self::TIERS, 180000));
        $this->assertSame(['index' => 2, 'from' => 250000.0, 'upTo' => null, 'rate' => 2.0], Tiers::tierAt(self::TIERS, 1e9));
        // progressive: 100,000 at 1 % + 150,000 at 1.5 % + the rest at 2 %
        $expected = [0 => 0.0, 50000 => 500.0, 100000 => 1000.0, 180000 => 2200.0, 250000 => 3250.0, 300000 => 4250.0, -1000 => -10.0];
        foreach ($expected as $amount => $commission) {
            $this->assertEqualsWithDelta($commission, Tiers::cumulative(self::TIERS, (float) $amount), 0.000001, "cumulative({$amount})");
        }
        $this->assertSame(0.0, Tiers::cumulative([], 5000));
    }

    public function test_a_period_is_a_calendar_month(): void
    {
        [$from, $to] = Period::bounds('2026-02');
        $this->assertSame(['2026-02-01', '2026-02-28', '2026-01', '2026-03', '2025-12'], [$from->toDateString(), $to->toDateString(), Period::shift('2026-02', -1), Period::shift('2026-02', 1), Period::shift('2026-02', -2)]);
        $this->assertSame([true, false, false, false, false], array_map(fn ($p) => Period::valid($p), ['2026-12', '2026-13', '2026-1', '26-01', null]));
        $this->assertSame('2026-11', Period::of('2026-11-24'));
        try {
            Period::bounds('2026-00');
            $this->fail('expected COMMISSION_PERIOD');
        } catch (AppError $e) {
            $this->assertSame('COMMISSION_PERIOD', $e->errorCode);
        }
    }

    public function test_the_rates_of_a_statement_rule_by_rule(): void
    {
        $row = fn (string $kind, string $rule, float $base, string $day, string $number, bool $excluded = false) => ['kind' => $kind, 'rule_id' => $rule, 'base_amount' => $base, 'excluded' => $excluded,
            'earned_on' => $day, 'doc_number' => $number, 'explain' => ['base' => 'net'], 'rate' => null, 'commission' => 0.0];
        $rules = [
            'flat' => ['id' => 'flat', 'rateType' => 'flat', 'rate' => 2],
            'whole' => ['id' => 'whole', 'rateType' => 'tiered', 'tierMode' => 'whole', 'tiers' => self::TIERS],
            'slices' => ['id' => 'slices', 'rateType' => 'tiered', 'tierMode' => 'progressive', 'tiers' => self::TIERS],
        ];
        $rows = [
            'a' => $row('invoice', 'flat', 1000, '2026-08-01', 'CI-1'),
            'b' => $row('credit_note', 'flat', -200, '2026-08-02', 'CN-1'),                 // a credit note takes its share back at the same rate
            'c' => $row('invoice', 'flat', 500, '2026-08-03', 'CI-2', true),               // excluded by a person: listed, earns nothing
            'd' => $row('invoice', 'whole', 70000, '2026-08-09', 'CI-4'),
            'e' => $row('invoice', 'whole', 60000, '2026-08-05', 'CI-3'),                  // the month totals 130,000: the second tier pays on everything
            'f' => $row('invoice', 'whole', 900000, '2026-08-06', 'CI-9', true),           // … what is excluded does not lift the tier
            'g' => $row('credit_note', 'slices', -20000, '2026-08-20', 'CN-2'),            // 130,000 → 110,000: taken back at 1.5 %
            'h' => $row('invoice', 'slices', 70000, '2026-08-10', 'CI-6'),                 // 60,000 → 130,000: 40,000 at 1 % + 30,000 at 1.5 %
            'i' => $row('invoice', 'slices', 60000, '2026-08-04', 'CI-5'),                 // 0 → 60,000 at 1 %
            'j' => ['kind' => 'reversal', 'rule_id' => 'flat', 'base_amount' => -300.0, 'rate' => 2.0, 'commission' => -6.0, 'explain' => ['reversal' => 'cancelled']] + $row('reversal', 'flat', -300, '2026-08-11', 'CI-0'),
            'k' => $row('invoice', 'gone', 100, '2026-08-12', 'CI-7'),                     // its rule is no longer in the terms: left as it is
        ];
        $rated = CalculationService::rate($rows, $rules);
        $got = array_map(fn (array $r) => [$r['rate'], $r['commission']], $rated);
        $this->assertSame([
            'a' => [2.0, 20.0], 'b' => [2.0, -4.0], 'c' => [2.0, 0.0],
            'd' => [1.5, 1050.0], 'e' => [1.5, 900.0], 'f' => [null, 0.0],
            'g' => [1.5, -300.0], 'h' => [1.2143, 850.0], 'i' => [1.0, 600.0],
            'j' => [2.0, -6.0], 'k' => [null, 0.0],
        ], $got);
        $this->assertSame(['mode' => 'whole', 'total' => 130000.0, 'from' => 100000.0, 'upTo' => 250000.0], $rated['d']['explain']['tier']);
        $this->assertSame(['mode' => 'progressive', 'from' => 60000.0, 'to' => 130000.0], $rated['h']['explain']['tier']);
        $this->assertSame(['base' => 'net'], $rated['a']['explain'], 'a flat rule adds nothing to the explanation');
        $this->assertSame(['reversal' => 'cancelled'], $rated['j']['explain'], 'a reversal carries the figures of the line it takes back');
        // idempotent: rating what is already rated gives the same rows
        $this->assertSame($rated, CalculationService::rate($rated, $rules));
        // the tier of a month that nets to nothing or less is the first one
        $back = CalculationService::rate([$row('invoice', 'whole', 1000, '2026-08-01', 'CI-1'), $row('credit_note', 'whole', -3000, '2026-08-02', 'CN-1')], $rules);
        $this->assertSame([[1.0, 10.0], [1.0, -30.0]], array_map(fn (array $r) => [$r['rate'], $r['commission']], $back));
    }
}
