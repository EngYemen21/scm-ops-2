<?php

namespace Tests\Feature\Planning;

use App\Planning\Engine\Cadence;
use App\Planning\Engine\Calendar;
use App\Planning\Engine\Forecaster;
use PHPUnit\Framework\TestCase;

/**
 * Each forecast method against cases computed by hand (the arithmetic is written beside every expectation). Pure
 * engine: no database, no clock. History Jan–Jun 2026 (six calendar months), forecast Jul–Sep 2026 (31 + 31 + 30 days)
 * unless a case says otherwise.
 */
class ForecastMethodsTest extends TestCase
{
    private function run6(string $method, array $daily, array $opt = []): array
    {
        return Forecaster::run($method, $daily, Calendar::history('2026-01-01', '2026-06-30'), Calendar::horizon('2026-07-01', '2026-09-30'), $opt);
    }

    public function test_periods_are_calendar_months_and_weeks_count_back_from_the_end(): void
    {
        $h = Calendar::history('2026-01-24', '2026-03-31');
        $this->assertSame([['2026-01', 8], ['2026-02', 28], ['2026-03', 31]], array_map(fn ($p) => [$p->label, $p->days], $h));
        // 17 days back in weeks: the newest block is whole, only the oldest is short (17 = 3 + 7 + 7)
        $w = Calendar::history('2026-03-15', '2026-03-31', 'week');
        $this->assertSame([['2026-03-15', '2026-03-17'], ['2026-03-18', '2026-03-24'], ['2026-03-25', '2026-03-31']], array_map(fn ($p) => [$p->from, $p->to], $w));
        $this->assertSame('2025-02-28', Calendar::yearBefore('2026-02-28'));
        $this->assertSame('2023-02-28', Calendar::yearBefore('2024-02-29'));
    }

    public function test_simple_average_is_total_over_the_days_used(): void
    {
        // Jan 62 · Feb 28 · Mar 31 · Apr 60 · May 31 · Jun 30 = 242 over 181 days = 1.3370166 a day
        $f = $this->run6('average', ['2026-01-10' => 62, '2026-02-10' => 28, '2026-03-10' => 31, '2026-04-10' => 60, '2026-05-10' => 31, '2026-06-10' => 30]);
        $this->assertSame('average', $f['used']);
        $this->assertFalse($f['fallback']);
        $this->assertSame(6, $f['periodsUsed']);
        $this->assertEqualsWithDelta(242.0, $f['total'], 1e-9);
        $this->assertSame([1 => 41.448, 2 => 41.448, 3 => 40.11], $f['forecast']);   // 31 × 1.3370166 = 41.4475; 30 × = 40.1105
        $this->assertEqualsWithDelta(123.006, $f['forecastTotal'], 1e-9);
        $this->assertEqualsWithDelta(40.333, $f['mean'], 1e-9);                        // 242 ÷ 6
    }

    public function test_weighted_moving_average_weighs_the_newest_period_most(): void
    {
        // the last three periods: Apr 60/30 = 2 · May 31/31 = 1 · Jun 30/30 = 1 → (1×2 + 2×1 + 3×1) ÷ 6 = 7/6 a day
        $daily = ['2026-01-10' => 62, '2026-02-10' => 28, '2026-03-10' => 31, '2026-04-10' => 60, '2026-05-10' => 31, '2026-06-10' => 30];
        $f = $this->run6('wma', $daily, ['wmaWindow' => 3]);
        $this->assertSame([1 => 36.167, 2 => 36.167, 3 => 35.0], $f['forecast']);       // 31 × 7/6 = 36.1667; 30 × 7/6 = 35
        $this->assertSame([1, 2, 3], array_column($f['detail']['periods'], 'weight'));
        // the growth factor multiplies the end: +10 % → 39.783, 39.783, 38.5
        $g = $this->run6('wma', $daily, ['wmaWindow' => 3, 'growthPct' => 10]);
        $this->assertSame([1 => 39.783, 2 => 39.783, 3 => 38.5], $g['forecast']);
    }

    public function test_seasonal_takes_the_same_dates_last_year_and_scales_to_the_current_level(): void
    {
        // history Apr 2025 – Jun 2026; last year: Jul 50 · Aug 40 · Sep 30
        $daily = ['2025-04-15' => 20, '2025-05-15' => 20, '2025-06-15' => 20, '2025-07-15' => 50, '2025-08-15' => 40, '2025-09-15' => 30];
        foreach (['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'] as $m) {
            $daily["{$m}-15"] = 10;
        }
        $daily += ['2026-04-15' => 40, '2026-05-15' => 40, '2026-06-15' => 40];
        [$hist, $hor] = [Calendar::history('2025-04-01', '2026-06-30'), Calendar::horizon('2026-07-01', '2026-09-30')];
        $plain = Forecaster::run('seasonal', $daily, $hist, $hor);
        $this->assertSame('seasonal', $plain['used']);
        $this->assertSame([1 => 50.0, 2 => 40.0, 3 => 30.0], $plain['forecast']);
        $this->assertSame([1 => 50.0, 2 => 40.0, 3 => 30.0], $plain['sameDatesLastYear']);
        // the last 91 days (Apr–Jun 2026) sold 120; the same days of 2025 sold 60 → × 2
        $scaled = Forecaster::run('seasonal', $daily, $hist, $hor, ['seasonalScale' => true]);
        $this->assertSame([1 => 100.0, 2 => 80.0, 3 => 60.0], $scaled['forecast']);
        $this->assertEqualsWithDelta(2.0, $scaled['detail']['scale']['factor'], 1e-9);
        $this->assertSame(91, $scaled['detail']['scale']['days']);
    }

    public function test_seasonal_falls_back_to_the_weighted_average_and_says_why(): void
    {
        // the window does not reach last July: weighted moving average, reason "seasonal_window"
        $f = $this->run6('seasonal', ['2026-04-10' => 60, '2026-05-10' => 31, '2026-06-10' => 30], ['wmaWindow' => 3]);
        $this->assertSame('wma', $f['used']);
        $this->assertTrue($f['fallback']);
        $this->assertSame('seasonal_window', $f['reason']['code']);
        $this->assertSame([1 => 36.167, 2 => 36.167, 3 => 35.0], $f['forecast']);
        // the window reaches it but nothing was sold then: reason "seasonal_empty"
        $e = Forecaster::run('seasonal', ['2026-04-10' => 60, '2026-05-10' => 31, '2026-06-10' => 30], Calendar::history('2025-07-01', '2026-06-30'), Calendar::horizon('2026-07-01', '2026-09-30'), ['wmaWindow' => 3]);
        $this->assertSame('seasonal_empty', $e['reason']['code']);
        $this->assertSame('wma', $e['used']);
    }

    public function test_no_history_forecasts_nothing_and_says_so(): void
    {
        foreach (['average', 'wma', 'seasonal'] as $method) {
            $f = $this->run6($method, []);
            $this->assertSame('none', $f['used'], $method);
            $this->assertSame('no_history', $f['reason']['code']);
            $this->assertSame([1 => 0.0, 2 => 0.0, 3 => 0.0], $f['forecast']);
            $this->assertSame(0, $f['periodsUsed']);
        }
    }

    public function test_one_order_counts_from_its_period(): void
    {
        // one sale of 12 on 10 June: the periods before it are not history; 12 ÷ 30 days = 0.4 a day
        $f = $this->run6('average', ['2026-06-10' => 12]);
        $this->assertSame(1, $f['periodsUsed']);
        $this->assertSame(6, $f['firstUsed']);
        $this->assertSame([1 => 12.4, 2 => 12.4, 3 => 12.0], $f['forecast']);
    }

    public function test_a_gap_after_the_first_sale_is_a_real_zero(): void
    {
        // Jan 31 · Feb nothing · Mar 62; horizon Apr–Jun
        [$h, $z] = [Calendar::history('2026-01-01', '2026-03-31'), Calendar::horizon('2026-04-01', '2026-06-30')];
        $avg = Forecaster::run('average', ['2026-01-05' => 31, '2026-03-05' => 62], $h, $z);
        $this->assertSame(3, $avg['periodsUsed']);
        $this->assertSame([1 => 31.0, 2 => 32.033, 3 => 31.0], $avg['forecast']); // 93 ÷ 90 days; × 30, × 31
        $wma = Forecaster::run('wma', ['2026-01-05' => 31, '2026-03-05' => 62], $h, $z, ['wmaWindow' => 3]);
        $this->assertSame([1 => 35.0, 2 => 36.167, 3 => 35.0], $wma['forecast']); // (1×1 + 2×0 + 3×2) ÷ 6 = 7/6
    }

    public function test_returns_are_netted_on_their_day_and_returns_only_is_no_demand(): void
    {
        // Mar 62 sold, 31 back on 20 March: net 31 → average over Jan–Mar with Jan 31 = 62 ÷ 90
        [$h, $z] = [Calendar::history('2026-01-01', '2026-03-31'), Calendar::horizon('2026-04-01', '2026-04-30')];
        $f = Forecaster::run('average', ['2026-01-05' => 31, '2026-03-05' => 62, '2026-03-20' => -31], $h, $z);
        $this->assertEqualsWithDelta(62.0, $f['total'], 1e-9);
        $this->assertSame([1 => 20.667], $f['forecast']);                     // 62 ÷ 90 × 30 = 20.6667
        // 10 sold in January, 12 back in March: the net demand of the window is −2
        $back = Forecaster::run('average', ['2026-01-05' => 10, '2026-03-20' => -12], $h, $z);
        $this->assertSame('none', $back['used']);
        $this->assertSame('returns', $back['reason']['code']);
        $this->assertSame([1 => 0.0], $back['forecast']);
    }

    public function test_a_product_that_started_mid_window_is_averaged_over_its_own_periods(): void
    {
        // first sale in April: Apr 30 · May 31 · Jun 30 = 91 over 91 days = 1 a day (not 91 ÷ 181)
        $f = $this->run6('average', ['2026-04-10' => 30, '2026-05-10' => 31, '2026-06-10' => 30]);
        $this->assertSame(3, $f['periodsUsed']);
        $this->assertSame(4, $f['firstUsed']);
        $this->assertSame([1 => 31.0, 2 => 31.0, 3 => 30.0], $f['forecast']);
    }

    public function test_zero_demand_after_a_sale_keeps_the_rate_falling(): void
    {
        // one sale in January and nothing after: 31 ÷ 181 days = 0.171271 a day; wma over Apr–Jun = 0
        $avg = $this->run6('average', ['2026-01-10' => 31]);
        $this->assertSame([1 => 5.309, 2 => 5.309, 3 => 5.138], $avg['forecast']);   // 31 × 31/181 = 5.3094; 30 × 31/181 = 5.1381
        $wma = $this->run6('wma', ['2026-01-10' => 31], ['wmaWindow' => 3]);
        $this->assertSame('wma', $wma['used']);
        $this->assertSame([1 => 0.0, 2 => 0.0, 3 => 0.0], $wma['forecast']);
    }

    // ───────────── the reorder cadence of a customer ─────────────

    private function regular(): array
    {
        return Cadence::orders(['2026-01-01' => 10, '2026-01-11' => 10, '2026-01-21' => 10, '2026-01-31' => 10]);
    }

    public function test_cadence_on_schedule(): void
    {
        // every 10 days, 10 each; next after 31 Jan = 10 Feb; anchor 5 Feb; horizon 5–28 Feb (one period)
        $p = Cadence::pattern($this->regular(), ['anchor' => '2026-02-05', 'horizonFrom' => '2026-02-05', 'horizonTo' => '2026-02-28', 'minOrders' => 3, 'useTrend' => true],
            Calendar::horizon('2026-02-05', '2026-02-28'));
        $this->assertSame('active', $p['state']);
        $this->assertSame([10, 10, 10], $p['intervals']);
        $this->assertSame('2026-02-10', $p['nextExpected']);
        $this->assertSame(['2026-02-10', '2026-02-20'], array_column($p['events'], 'day'));   // 2 March is after the horizon
        $this->assertEqualsWithDelta(20.0, $p['forecastQty'], 1e-9);
        $this->assertSame(1.0, $p['trendFactor']);
        // 100 × (0.5 × (1 − 0) + 0.2 × (1 − 0) + 0.3 × min(1, 4 ÷ 6)) = 90
        $this->assertEqualsWithDelta(90.0, $p['confidence'], 1e-9);
        $this->assertSame('high', $p['band']);
    }

    public function test_cadence_overdue_expects_one_now_and_lapsed_expects_nothing(): void
    {
        $opt = ['horizonFrom' => '2026-02-15', 'horizonTo' => '2026-02-28', 'minOrders' => 3];
        $p = Cadence::pattern($this->regular(), $opt + ['anchor' => '2026-02-15'], Calendar::horizon('2026-02-15', '2026-02-28'));
        $this->assertSame('overdue', $p['state']);
        $this->assertSame(5, $p['overdueDays']);
        $this->assertSame(['2026-02-15', '2026-02-25'], array_column($p['events'], 'day'));
        $this->assertTrue($p['events'][0]['catchUp']);
        $this->assertEqualsWithDelta(75.0, $p['confidence'], 1e-9);               // 90 × (1 − 5 ÷ 30)
        $lapsed = Cadence::pattern($this->regular(), ['anchor' => '2026-03-05', 'horizonFrom' => '2026-03-05', 'horizonTo' => '2026-03-31', 'minOrders' => 3], Calendar::horizon('2026-03-05', '2026-03-31'));
        $this->assertSame('lapsed', $lapsed['state']);                            // 33 days since the last > 3 × 10
        $this->assertSame([], $lapsed['events']);
        $this->assertSame(0.0, $lapsed['forecastQty']);
    }

    public function test_cadence_returns_and_too_few_orders(): void
    {
        // a return is taken off the order before it; a delivery that came back whole is no order
        $this->assertSame([['day' => '2026-01-01', 'qty' => 6.0], ['day' => '2026-01-11', 'qty' => 10.0]], Cadence::orders(['2026-01-01' => 10, '2026-01-05' => -4, '2026-01-11' => 10]));
        $this->assertSame([], Cadence::orders(['2026-01-01' => 5, '2026-01-02' => -5]));
        $two = Cadence::pattern(Cadence::orders(['2026-01-01' => 10, '2026-01-11' => 10]), ['anchor' => '2026-01-15', 'horizonFrom' => '2026-01-15', 'horizonTo' => '2026-01-31', 'minOrders' => 3],
            Calendar::horizon('2026-01-15', '2026-01-31'));
        $this->assertSame('insufficient', $two['state']);
        $this->assertSame('none', $two['band']);
        $this->assertSame([], $two['events']);
    }

    public function test_cadence_trend_is_capped(): void
    {
        // quantities 10, 10, 20, 20 → newer ÷ older = 2, capped at +25 % → expected 15 × 1.25 = 18.75
        $orders = Cadence::orders(['2026-01-01' => 10, '2026-01-11' => 10, '2026-01-21' => 20, '2026-01-31' => 20]);
        $p = Cadence::pattern($orders, ['anchor' => '2026-02-05', 'horizonFrom' => '2026-02-05', 'horizonTo' => '2026-02-28', 'minOrders' => 3, 'useTrend' => true, 'trendCapPct' => 25],
            Calendar::horizon('2026-02-05', '2026-02-28'));
        $this->assertSame(1.25, $p['trendFactor']);
        $this->assertSame(18.75, $p['expectedQty']);
    }
}
