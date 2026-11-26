<?php

namespace Tests\Feature\Planning;

use App\Planning\Models\Run;
use App\Planning\Services\Computer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * OPS's own confirmed orders as history (history source "sale_order"): the customer reorder cadence with its expected
 * orders and what really happened, one forecast per customer (split), and a computation stopped after one slice and
 * finished by the command with the same figures.
 *
 * C1 orders 20 every 14 days from 1 Jun to 24 Aug (7 orders); C2 ordered 10 once, on 1 Aug. Anchor 18 Sep 2026,
 * history Mar – Aug, forecast Oct – Dec.
 */
class PlanningCadenceTest extends ApiTestCase
{
    use PlanningFixtures;

    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$s) {
            return;
        }
        $k = $this->product('حليب اختبار الإيقاع');
        $c1 = $this->customer('مقهى الإيقاع المنتظم');
        $c2 = $this->customer('مقهى الطلب الواحد');
        foreach (['2026-06-01', '2026-06-15', '2026-06-29', '2026-07-13', '2026-07-27', '2026-08-10', '2026-08-24'] as $day) {
            $this->opsOrder($c1, $k, $day, 20);
        }
        $this->opsOrder($c2, $k, '2026-08-01', 10);
        $this->opsOrder($c2, $k, '2026-08-05', 50, 'cancelled');                       // a cancelled order is no demand
        self::$s = ['k' => $k, 'c1' => $c1, 'c2' => $c2];
    }

    private function newRun(array $more): array
    {
        return $this->expectOk($this->postAs('proc', '/api/planning/runs', $more + [
            'anchorDate' => '2026-09-18', 'historyFrom' => '2026-03-01', 'historyTo' => '2026-08-31', 'forecastFrom' => '2026-10-01', 'forecastTo' => '2026-12-31',
            'historySource' => 'sale_order', 'includeOdoo' => false, 'includeOps' => true, 'productSelection' => 'manual', 'productIds' => [self::$s['k']->id], 'warehouseIds' => [$this->ryd()->id],
            'cadenceMinOrders' => 3, 'cadenceIntervalWindow' => 6, 'cadenceLapseFactor' => 3, 'cadenceUseTrend' => true, 'cadenceTrendCapPct' => 25, 'cadenceFallback' => 'estimate',
        ]));
    }

    public function test_the_cadence_of_each_customer(): void
    {
        $run = $this->newRun(['method' => 'cadence']);
        $this->computed('proc', $run['id']);
        self::$s['cadence'] = $run['id'];
        $i = $this->itemOf('proc', $run['id'], self::$s['k']);
        // C1: last order 24 Aug + 14 = 7 Sep, 11 days overdue on 18 Sep → one order expected now (before the forecast),
        // then every 14 days: 2, 16, 30 Oct · 13, 27 Nov · 11, 25 Dec = 60 · 40 · 40.
        // C2: one order — estimated with the weighted average of its own demand: 10 ÷ 31 a day → 10 · 9.677 · 10
        $this->assertSame([70.0, 49.677, 50.0], array_map(fn ($p) => (float) $p['forecast'], $i['periods']));
        $this->assertEqualsWithDelta(169.677, $i['forecast'], 1e-9);
        $this->assertEqualsWithDelta(24.194, $i['beforeHorizon'], 1e-9);                // 20 + 10/31 × 13
        $this->assertTrue($i['fallback']);
        $this->assertSame('cadence_estimate', $i['explain']['method']['reason']['code']);
        $patterns = collect($i['patterns'])->keyBy('customerId');
        $p1 = $patterns[self::$s['c1']->id];
        $this->assertSame(['overdue', 7, 14.0, 20.0, '2026-09-07', 11, 140.0, 7, 'high'],
            [$p1['state'], $p1['orders'], (float) $p1['avgInterval'], (float) $p1['expectedQty'], $p1['nextExpected'], $p1['overdueDays'], (float) $p1['forecast'], $p1['expectedOrders'], $p1['band']]);
        $this->assertEqualsWithDelta(73.81, $p1['confidence'], 1e-9);                     // 100 × (1 − 11 ÷ 42)
        $p2 = $patterns[self::$s['c2']->id];
        $this->assertSame(['insufficient', 1], [$p2['state'], $p2['orders']]);
        $this->assertEqualsWithDelta(29.677, $p2['forecast'], 1e-9);
        $events = $this->expectOk($this->getAs('proc', "/api/planning/runs/{$run['id']}/events?pattern={$p1['id']}"))['items'];
        $this->assertSame(['2026-09-18', '2026-10-02', '2026-10-16', '2026-10-30', '2026-11-13', '2026-11-27', '2026-12-11', '2026-12-25'], array_column($events, 'expectedDate'));
        $this->assertTrue($events[0]['catchUp']);
        $this->assertFalse($events[0]['inHorizon']);
    }

    public function test_what_really_happened_to_the_expected_orders(): void
    {
        $id = self::$s['cadence'];
        $this->opsOrder(self::$s['c1'], self::$s['k'], '2026-09-17', 18);                // within 7 days of 18 Sep (half the 14-day rhythm)
        $n = $this->expectOk($this->postAs('proc', "/api/planning/runs/{$id}/actuals"));
        $this->assertSame(['checked' => 1, 'ordered' => 1, 'missed' => 0, 'pending' => 0], $n);
        $e = $this->expectOk($this->getAs('proc', "/api/planning/runs/{$id}/events?actual=ordered"))['items'];
        $this->assertCount(1, $e);
        $this->assertSame(['2026-09-17', 18.0, -2.0], [$e[0]['actualDate'], (float) $e[0]['actualQty'], (float) $e[0]['deviation']]);
        $this->assertSame(20.0, (float) $e[0]['qty']);                                   // what the run expected does not change
    }

    public function test_one_forecast_per_customer(): void
    {
        $run = $this->newRun(['method' => 'wma', 'wmaWindow' => 3, 'customerMode' => 'split', 'rollupMethod' => 'pooled']);
        $this->computed('proc', $run['id']);
        $i = $this->itemOf('proc', $run['id'], self::$s['k']);
        // pooled: Jun 60/30 = 2 · Jul 40/31 · Aug 50/31 → (2 + 2 × 1.290323 + 3 × 1.612903) ÷ 6 = 1.569892 a day
        $this->assertTrue($i['isRollup']);
        $this->assertSame([48.667, 47.097, 48.667], array_map(fn ($p) => (float) $p['forecast'], $i['periods']));
        $customers = collect($i['customers'])->keyBy('customerId');
        // C1: (2 + 2 × 40/31 + 3 × 40/31) ÷ 6 = 1.408602 a day; C2: 10/31 a day
        $this->assertEqualsWithDelta(129.592, $customers[self::$s['c1']->id]['forecast'], 1e-9);
        $this->assertEqualsWithDelta(29.677, $customers[self::$s['c2']->id]['forecast'], 1e-9);
        $this->assertEqualsWithDelta(159.269, $i['customerSum'], 1e-9);                     // 129.592 + 29.677
        $this->assertSame(2, count($i['customers']));
    }

    public function test_a_computation_continues_where_it_stood(): void
    {
        $run = $this->newRun(['method' => 'cadence']);
        $id = $run['id'];
        $computer = $this->app->make(Computer::class);
        DB::transaction(fn () => $computer->reset(Run::whereKey($id)->lockForUpdate()->first()));
        $this->assertTrue($computer->advance($id, 30, 1));                                // one slice, then "the server stops"
        $mid = Run::find($id);
        $this->assertSame('computing', $mid->status);
        $this->assertGreaterThan(0, $mid->progress_done);
        $this->assertLessThan($mid->progress_total, $mid->progress_done);
        // a second worker while the first holds the run does nothing
        Run::whereKey($id)->update(['lease_until' => now()->addMinute(), 'lease_token' => 'other']);
        $this->assertFalse($computer->advance($id, 1));
        Run::whereKey($id)->update(['lease_until' => null, 'lease_token' => null]);
        Artisan::call('scm:planning-compute', ['run' => $mid->number, '--seconds' => 60]);
        $this->assertSame('computed', Run::find($id)->status);
        // the same figures as the run computed in one go
        $a = $this->itemOf('proc', $id, self::$s['k']);
        $b = $this->itemOf('proc', self::$s['cadence'], self::$s['k']);
        foreach (['forecast', 'beforeHorizon', 'safety', 'suggestedQty', 'confidence'] as $key) {
            $this->assertSame($b[$key], $a[$key], $key);
        }
        $this->assertSame(1, DB::table('planning_demand')->where('run_id', $id)->distinct()->count('product_id'));
        $this->assertSame(DB::table('planning_demand')->where('run_id', self::$s['cadence'])->sum('qty'), DB::table('planning_demand')->where('run_id', $id)->sum('qty'));
    }

    public function test_a_computation_can_be_stopped(): void
    {
        $run = $this->newRun(['method' => 'average']);
        $computer = $this->app->make(Computer::class);
        DB::transaction(fn () => $computer->reset(Run::whereKey($run['id'])->lockForUpdate()->first()));
        $computer->advance($run['id'], 30, 1);
        $stopped = $this->expectOk($this->postAs('proc', "/api/planning/runs/{$run['id']}/stop"));
        $this->assertSame('draft', $stopped['status']);
        $this->assertSame(0, DB::table('planning_demand')->where('run_id', $run['id'])->count());
        $this->expectRejected($this->postAs('proc', "/api/planning/runs/{$run['id']}/advance"), 'PLANNING_RUN_NOT_COMPUTING', [422]);
    }
}
