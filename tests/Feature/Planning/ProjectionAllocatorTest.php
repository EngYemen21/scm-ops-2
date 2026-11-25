<?php

namespace Tests\Feature\Planning;

use App\Planning\Engine\Allocator;
use App\Planning\Engine\Calendar;
use App\Planning\Engine\Num;
use App\Planning\Engine\Projection;
use PHPUnit\Framework\TestCase;

/**
 * The balance period by period, what to buy, the safety stock, the reorder settings, and the budget allocation —
 * against cases computed by hand. Horizon Jul–Sep 2026, a forecast of one a day (31 · 31 · 30).
 */
class ProjectionAllocatorTest extends TestCase
{
    private function horizon(): array
    {
        return Calendar::horizon('2026-07-01', '2026-09-30');
    }

    public function test_the_balance_period_by_period_and_the_first_shortage(): void
    {
        // on hand 40, 20 booked in during August, safety 10
        $p = Projection::run($this->horizon(), [1 => 31, 2 => 31, 3 => 30], [2 => 20], [], ['onHand' => 40, 'safety' => 10, 'maxLevel' => 0, 'avgDaily' => 1]);
        $this->assertSame([9.0, -2.0, -32.0], array_column($p['periods'], 'projected'));       // 40 − 31; 9 + 20 − 31; −2 − 30
        $this->assertSame([1.0, 12.0, 42.0], array_column($p['periods'], 'shortage'));         // below the safety stock of 10
        $this->assertSame('2026-07-31', $p['firstShortage']);                                  // (40 − 10) ÷ 1 a day = 30 days after 1 July
        $this->assertSame(-32.0, $p['minBalance']);
        $this->assertSame(42.0, $p['shortage']);
        $this->assertSame(20.0, $p['bookedIn']);
    }

    public function test_netting_of_booked_deliveries(): void
    {
        $this->assertSame([40.0, 0.0], Projection::outflow('net', 31, 40));     // booked deliveries are part of the forecast
        $this->assertSame([31.0, 31.0], Projection::outflow('net', 31.0, 0.0));
        $this->assertSame([31.0, 31.0], Projection::outflow('gross', 31.0, 40.0)); // ignored
        $this->assertSame([40.0, 0.0], Projection::outflow('ignore', 31.0, 40.0)); // the forecast is only shown
    }

    public function test_what_to_buy_period_by_period(): void
    {
        $opt = ['onHand' => 40, 'safety' => 10, 'leadDays' => 5, 'bufferDays' => 2, 'anchor' => '2026-07-01'];
        $r = Projection::requirements($this->horizon(), [1 => 31, 2 => 31, 3 => 30], [2 => 20], [], $opt);
        // July ends at 9 → 1 to reach 10; August 10 + 20 − 31 = −1 → 11; September 10 − 30 = −20 → 30
        $this->assertSame([1, 11, 30], array_column($r, 'qty'));
        $this->assertSame(['2026-07-31', '2026-08-21', '2026-09-01'], array_column($r, 'needDate'));
        $this->assertSame(['2026-07-24', '2026-08-14', '2026-08-25'], array_column($r, 'orderBy'));   // need − 7 days
        $this->assertSame([0.0, 1.0, 20.0], array_column($r, 'stockout'));
        // a vendor minimum of 20 in multiples of 12: July buys 24 (balance 33), August needs nothing (22), September 24
        $m = Projection::requirements($this->horizon(), [1 => 31, 2 => 31, 3 => 30], [2 => 20], [], $opt + ['moq' => 20, 'multiple' => 12]);
        $this->assertSame([[1, 24], [3, 24]], array_map(fn ($x) => [$x['period'], $x['qty']], $m));
        $this->assertSame('2026-09-13', $m[1]['needDate']);                                  // (22 − 10) ÷ 1 a day after 1 September
        $this->assertSame(['2026-07-24', '2026-09-06'], array_column($m, 'orderBy'));
        // ordered too late: an order-by day before the anchor
        $late = Projection::requirements($this->horizon(), [1 => 31, 2 => 31, 3 => 30], [], [], ['onHand' => 5, 'safety' => 10, 'leadDays' => 10, 'anchor' => '2026-07-01']);
        $this->assertTrue($late[0]['late']);
        $this->assertSame('2026-06-21', $late[0]['orderBy']);
    }

    public function test_safety_stock_and_reorder_settings(): void
    {
        $this->assertSame(7.0, Projection::safety('days', 1.0, 2.0, 7, null, 5, 2)['qty']);              // 1 a day × 7 days
        $this->assertSame(8.731, Projection::safety('service_level', 1.0, 2.0, 7, '95', 5, 2)['qty']);   // 1.65 × 2 × √(5 + 2) = 8.7310
        $this->assertSame(['min' => 14, 'max' => 44], Projection::minMax(7, 1, 5, 2, 30));                // 7 + 7 · 7 + 37
        $this->assertSame(24, Num::orderQty(1, 20, 12));
        $this->assertSame(0, Num::orderQty(0, 20, 12));
        $this->assertSame(8, Num::days(7.5));
    }

    /** @return array<int, array> three requirements: R1 critical, R2 and R3 normal */
    private function requirements(): array
    {
        $base = ['leadDays' => 5, 'bufferDays' => 0, 'velocity' => 1, 'confidence' => 50, 'customerScore' => 50, 'moq' => 0, 'multiple' => 1];

        return [
            ['id' => 'R1', 'sku' => 'A', 'warehouse' => 'W', 'qty' => 50, 'price' => 10, 'orderBy' => '2026-07-05', 'needDate' => '2026-07-20', 'net' => 50, 'stockout' => 50, 'criticality' => 'critical'] + $base,
            ['id' => 'R2', 'sku' => 'B', 'warehouse' => 'W', 'qty' => 40, 'price' => 20, 'orderBy' => '2026-07-10', 'needDate' => '2026-07-25', 'net' => 40, 'stockout' => 0, 'criticality' => 'normal'] + $base,
            ['id' => 'R3', 'sku' => 'C', 'warehouse' => 'W', 'qty' => 100, 'price' => 5, 'orderBy' => '2026-08-03', 'needDate' => '2026-08-20', 'net' => 100, 'stockout' => 0, 'criticality' => 'normal'] + $base,
        ];
    }

    public function test_allocation_critical_first_partial_funding_and_carry_forward(): void
    {
        $months = [['month' => '2026-07-01', 'amount' => 1000], ['month' => '2026-08-01', 'amount' => 1000]];
        $out = Allocator::run($this->requirements(), $months, ['anchor' => '2026-07-01', 'weights' => ['urgency' => 1], 'urgencyHorizonDays' => 30]);
        $rows = array_map(fn ($r) => [$r['month'], $r['requirementId'], $r['status'], $r['fundedQty'], $r['fundedValue']], $out['rows']);
        // July: R1 (critical) 500, then R2 gets what is left: 500 ÷ 20 = 25 units; August: R2's other 15 (300) first —
        // its urgency is 100 × (1 − 9 ÷ 30) = 70 against R3's 0 — then R3 500; 200 left
        $this->assertSame([
            ['2026-07-01', 'R1', 'funded', 50.0, 500.0],
            ['2026-07-01', 'R2', 'partial', 25.0, 500.0],
            ['2026-08-01', 'R2', 'funded', 15.0, 300.0],
            ['2026-08-01', 'R3', 'funded', 100.0, 500.0],
        ], $rows);
        $this->assertSame('2026-07-01', $out['rows'][2]['carriedFrom']);
        $this->assertSame('2026-08-01', $out['rows'][2]['orderDate']);    // never before the month that pays for it
        $this->assertTrue($out['rows'][2]['atRisk']);                     // 1 Aug + 5 days > need date 25 Jul
        $this->assertSame(70.0, $out['rows'][2]['scores']['urgency']);
        $this->assertTrue($out['rows'][0]['mustFund']);
        $this->assertSame([1800.0, 1800.0, 100.0, 200.0, 3], [$out['totals']['required'], $out['totals']['funded'], $out['totals']['fundedPct'], $out['totals']['unused'], $out['totals']['fundedCount']]);
        $this->assertSame([1000.0, 0.0], [$out['months'][0]['funded'], $out['months'][0]['remaining']]);
    }

    public function test_allocation_without_partial_funding_defers_what_does_not_fit(): void
    {
        $months = [['month' => '2026-07-01', 'amount' => 1000], ['month' => '2026-08-01', 'amount' => 1000]];
        $out = Allocator::run($this->requirements(), $months, ['anchor' => '2026-07-01', 'weights' => ['urgency' => 1], 'allowPartial' => false, 'urgencyHorizonDays' => 30]);
        $rows = array_map(fn ($r) => [$r['month'], $r['requirementId'], $r['status'], $r['fundedQty']], $out['rows']);
        // July: R1 500; R2 (800) does not fit the 500 left → waits whole; August: 1000 + 500 carried = 1500 → R2 800, then R3 500 of the 700 left
        $this->assertSame([['2026-07-01', 'R1', 'funded', 50.0], ['2026-08-01', 'R2', 'funded', 40.0], ['2026-08-01', 'R3', 'funded', 100.0]], $rows);
        $this->assertSame(500.0, $out['months'][1]['carriedIn']);
        // nothing carried: unused July money is lost, R3 no longer fits and is left unfunded
        $tight = Allocator::run($this->requirements(), $months, ['anchor' => '2026-07-01', 'weights' => ['urgency' => 1], 'allowPartial' => false, 'carryUnused' => false, 'urgencyHorizonDays' => 30]);
        $last = end($tight['rows']);
        $this->assertSame(['R3', 'deferred', 'no_budget'], [$last['requirementId'], $last['status'], $last['reason']['code']]);
        $this->assertSame([1300.0, 500.0, 1], [$tight['totals']['funded'], $tight['totals']['deferred'], $tight['totals']['deferredCount']]);
        // a requirement without a price is never funded and says why
        $reqs = $this->requirements();
        $reqs[2]['price'] = 0;
        $noPrice = Allocator::run($reqs, $months, ['anchor' => '2026-07-01', 'weights' => ['urgency' => 1]]);
        $this->assertContains('no_price', array_map(fn ($r) => $r['reason']['code'] ?? null, $noPrice['rows']));
    }

    public function test_scores_and_weights(): void
    {
        $months = [['month' => '2026-07-01', 'amount' => 100000]];
        $w = ['urgency' => 30, 'stockout' => 25, 'criticality' => 20, 'velocity' => 10, 'confidence' => 5, 'customer' => 5, 'leadTime' => 5];
        $out = Allocator::run(array_slice($this->requirements(), 0, 2), $months, ['anchor' => '2026-07-01', 'weights' => $w, 'urgencyHorizonDays' => 30]);
        $r1 = $out['rows'][0];
        // R1: urgency 100 × (1 − 4/30) = 86.67 · stockout 100 · criticality 100 · velocity (1×10 vs top 1×20) 50 · confidence 50 · customer 50 · lead time 100
        $this->assertSame(['urgency' => 86.67, 'stockout' => 100.0, 'criticality' => 100.0, 'velocity' => 50.0, 'confidence' => 50.0, 'customer' => 50.0, 'leadTime' => 100.0], $r1['scores']);
        // Σ w × s ÷ Σ w = (30×86.667 + 25×100 + 20×100 + 10×50 + 5×50 + 5×50 + 5×100) ÷ 100 = 8600 ÷ 100 = 86
        $this->assertSame(86.0, $r1['score']);
    }
}
