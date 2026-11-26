<?php

namespace Tests\Feature\Planning;

use App\Models\Product;
use App\Planning\Models\Run;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * A forecast run end to end, on records of its own: Odoo deliveries of the copy (through a warehouse a person mapped),
 * OPS stock, a supplier price, a last purchase price — computed through the API and checked against the figures worked
 * out by hand beside each expectation. Then the push of the reorder settings, freezing, the export, and who may do what.
 *
 * Window: history 1 Mar – 31 Aug 2026 (184 days), anchor 18 Sep 2026, forecast Oct – Dec 2026 (31 + 30 + 31 days).
 */
class PlanningRunTest extends ApiTestCase
{
    use PlanningFixtures;

    /** @var array<string, mixed> what the story built */
    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$s) {
            return;
        }
        $p1 = $this->product('زيت اختبار التخطيط');                                  // Odoo history, vendor price, stock 40
        $p2 = $this->product('سكر اختبار التخطيط', ['purchase_price' => 7]);          // no history; last Odoo purchase 6.5, cost 7
        $p3 = $this->product('ملح اختبار التخطيط');                                   // nothing known at all
        $customer = $this->customer('مطعم اختبار التخطيط');
        $vendor = $this->supplier('مورد اختبار التخطيط');
        $this->supplierPrice($vendor, $p1, 10, 5);
        $this->stock($p1, 40);
        $this->odooWarehouse();
        $this->rec('res.partner', 970101, ['name' => 'مطعم اختبار التخطيط', 'commercial_partner_id' => [970101, 'x']], true);
        $this->rec('res.partner', 970102, ['name' => 'مشتريات المطعم', 'commercial_partner_id' => [970101, 'x'], 'parent_id' => [970101, 'x']], true);
        $this->link('customer', 970101, $customer->id, $customer->code);
        $this->link('product', 970201, $p1->id, $p1->sku);
        $this->link('product', 970202, $p2->id, $p2->sku);
        // one delivery a month of as many units as the month has days (1 a day), the August one 36 with 5 back
        $n = 970301;
        foreach (['2026-03' => 31, '2026-04' => 30, '2026-05' => 31, '2026-06' => 30, '2026-07' => 31, '2026-08' => 36] as $month => $qty) {
            $this->odooMove($n++, 970201, 970102, "{$month}-10 08:00:00", $qty);
        }
        $this->odooMove($n++, 970201, 970102, '2026-08-20 08:00:00', 5, true);
        $this->odooMove($n++, 970201, 970102, '2026-02-27 08:00:00', 99);           // before the window: not history
        $this->odooMove($n++, 970201, 970102, '2026-08-31 22:30:00', 7);            // 1 Sep 01:30 in Riyadh: after the window
        $this->rec('purchase.order.line', 970401, ['state' => 'purchase', 'product_id' => [970202, 'سكر'], 'price_unit' => 6.5, 'date_planned' => '2026-08-01 08:00:00', 'order_id' => [970401, 'P970401']]);
        self::$s = ['p1' => $p1, 'p2' => $p2, 'p3' => $p3, 'customer' => $customer, 'vendor' => $vendor];
    }

    private function newRun(array $more = []): array
    {
        $s = self::$s;

        return $this->expectOk($this->postAs('proc', '/api/planning/runs', $more + [
            'name' => 'قصة التخطيط', 'anchorDate' => '2026-09-18', 'historyFrom' => '2026-03-01', 'historyTo' => '2026-08-31', 'forecastFrom' => '2026-10-01', 'forecastTo' => '2026-12-31',
            'method' => 'average', 'historySource' => 'delivery', 'includeReturns' => true, 'includeOps' => true, 'includeOdoo' => true, 'safetyMethod' => 'days', 'safetyDays' => 7,
            'targetCoverDays' => 30, 'useVendorLeadTime' => true, 'defaultLeadDays' => 3, 'planningBufferDays' => 0, 'qtyMultipleSource' => 'one', 'productSelection' => 'manual',
            'productIds' => [$s['p1']->id, $s['p2']->id, $s['p3']->id], 'warehouseIds' => [$this->ryd()->id], 'customerMode' => 'none',
        ]));
    }

    public function test_an_unmapped_odoo_warehouse_is_left_out_and_counted(): void
    {
        DB::table('planning_warehouse_maps')->where('system', 'odoo')->where('external_id', '970001')->delete();
        $run = $this->newRun();
        $done = $this->computed('proc', $run['id']);
        $item = $this->itemOf('proc', $run['id'], self::$s['p1']);
        $this->assertSame(0.0, (float) $item['historyTotal']);
        $this->assertSame('no_history', $item['explain']['method']['reason']['code']);
        $this->assertGreaterThanOrEqual(8, $done['diagnostics']['skipped']['odooWarehouseNotMapped:970001'] ?? 0);
    }

    public function test_the_mapping_is_said_by_a_person(): void
    {
        $this->expectRejected($this->putAs('finance', '/api/planning/warehouse-maps/970001', ['warehouseId' => $this->ryd()->id]), null, [403]);
        $maps = $this->expectOk($this->putAs('proc', '/api/planning/warehouse-maps/970001', ['warehouseId' => $this->ryd()->id]));
        $row = collect($maps['items'])->firstWhere('odooId', 970001);
        $this->assertSame('RYD', $row['warehouse']['code']);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'PLANNING.WAREHOUSE_MAP')->where('entity_id', '970001')->exists());
    }

    public function test_a_run_computes_every_figure_as_worked_out_by_hand(): void
    {
        $this->putAs('proc', '/api/planning/warehouse-maps/970001', ['warehouseId' => $this->ryd()->id]);
        $run = $this->newRun();
        $this->assertSame('draft', $run['status']);
        $done = $this->computed('proc', $run['id']);
        $this->assertSame(3, $done['totals']['items']);
        self::$s['run'] = $run['id'];

        $i = $this->itemOf('proc', $run['id'], self::$s['p1']);
        // history: 31 + 30 + 31 + 30 + 31 + (36 − 5) = 184 over 184 days; the lines before and after the window are not in it
        $this->assertEqualsWithDelta(184.0, $i['historyTotal'], 1e-9);
        $this->assertSame(6, $i['historyPeriodsUsed']);
        $this->assertSame([31.0, 30.0, 31.0, 30.0, 31.0, 31.0], array_map(fn ($h) => (float) $h['qty'], $i['history']));
        $this->assertEqualsWithDelta(5.0, $i['explain']['history']['returns'], 1e-9);
        // 1 a day → Oct 31 · Nov 30 · Dec 31; 13 days between the anchor and 1 October → 13 expected before
        $this->assertSame([31.0, 30.0, 31.0], array_map(fn ($p) => (float) $p['forecast'], $i['periods']));
        $this->assertEqualsWithDelta(92.0, $i['forecast'], 1e-9);
        $this->assertEqualsWithDelta(13.0, $i['beforeHorizon'], 1e-9);
        // stock 40 − 13 = 27 → −4 → −34 → −65; safety 1 × 7 days = 7
        $this->assertEqualsWithDelta(40.0, $i['onHand'], 1e-9);
        $this->assertEqualsWithDelta(7.0, $i['safety'], 1e-9);
        $this->assertSame([-4.0, -34.0, -65.0], array_map(fn ($p) => (float) $p['projected'], $i['periods']));
        $this->assertSame('shortage', $i['status']);
        $this->assertSame('2026-10-21', $i['firstShortage']);                        // (27 − 7) ÷ 1 = 20 days after 1 Oct
        $this->assertEqualsWithDelta(27.0, $i['coverageDays'], 1e-9);
        // supplier: 10 a unit, 5 days; buy 11 (to reach 7 in Oct), 30 (Nov), 31 (Dec)
        $this->assertSame(['vendor', 'vendor'], [$i['priceSource'], $i['leadSource']]);
        $this->assertSame(5.0, (float) $i['leadDays']);
        $this->assertSame([11.0, 30.0, 31.0], array_map(fn ($r) => (float) $r['qty'], $i['requirements']));
        $this->assertSame(['2026-10-16', '2026-10-27', '2026-11-26'], array_map(fn ($r) => $r['orderBy'], $i['requirements']));
        $this->assertSame([4.0, 23.0, 24.0], array_map(fn ($r) => (float) $r['stockout'], $i['requirements'])); // the part below zero: −4, then 7 − 30 = −23, 7 − 31 = −24
        $this->assertEqualsWithDelta(72.0, $i['suggestedQty'], 1e-9);
        $this->assertEqualsWithDelta(720.0, $i['suggestedValue'], 1e-9);
        $this->assertSame('2026-10-16', $i['orderBy']);
        // reorder settings: min = 7 + 1 × 5 = 12; max = 7 + 1 × (5 + 30) = 42; the product has none yet
        $this->assertSame([12.0, 42.0, 'missing'], [(float) $i['suggestedMin'], (float) $i['suggestedMax'], $i['orderpointState']]);
        $this->assertEqualsWithDelta(100.0, $i['confidence'], 1e-9);                 // every month the same rate, six of them

        $p2 = $this->itemOf('proc', $run['id'], self::$s['p2']);
        $this->assertSame(['no_demand', 'last_purchase', 'default'], [$p2['status'], $p2['priceSource'], $p2['leadSource']]);
        $this->assertSame(6.5, (float) $p2['priceUnit']);                           // Odoo's last purchase comes before the product's own 7
        $this->assertSame(3.0, (float) $p2['leadDays']);
        $p3 = $this->itemOf('proc', $run['id'], self::$s['p3']);
        $this->assertSame(['no_demand', 'none'], [$p3['status'], $p3['priceSource']]);

        // the run explains itself: the same computation again gives the same numbers
        $again = $this->newRun();
        $this->computed('proc', $again['id']);
        $j = $this->itemOf('proc', $again['id'], self::$s['p1']);
        foreach (['forecast', 'safety', 'suggestedQty', 'suggestedValue', 'firstShortage', 'suggestedMin', 'suggestedMax', 'confidence'] as $k) {
            $this->assertSame($i[$k], $j[$k], $k);
        }
        $this->assertSame($i['explain']['minMax'], $j['explain']['minMax']);
    }

    public function test_requirements_list_paging_and_export(): void
    {
        $id = self::$s['run'];
        $req = $this->expectOk($this->getAs('proc', "/api/planning/runs/{$id}/requirements?q=".urlencode(self::$s['p1']->sku).'&pageSize=2'));
        $this->assertSame(3, $req['total']);
        $this->assertCount(2, $req['items']);
        $this->assertSame(2, $req['pages']);
        $this->assertEqualsWithDelta(720.0, $req['totals']['value'], 1e-9);
        $csv = $this->getAs('proc', "/api/planning/runs/{$id}/export?format=csv&sheet=items");
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString(self::$s['p1']->sku, $body);
        $x = $this->getAs('proc', "/api/planning/runs/{$id}/export?format=xlsx");
        $x->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
    }

    public function test_push_writes_the_reorder_settings_through_the_products_service(): void
    {
        $id = self::$s['run'];
        $item = $this->itemOf('proc', $id, self::$s['p1']);
        $out = $this->expectOk($this->postAs('proc', "/api/planning/runs/{$id}/push", ['itemIds' => [$item['id']]]));
        $this->assertSame(1, $out['pushed']);
        $p = Product::find(self::$s['p1']->id);
        $this->assertSame([12, 42], [$p->reorder_min, $p->reorder_max]);
        $this->assertSame(1, DB::table('planning_pushes')->where('run_id', $id)->where('product_id', $p->id)->count());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'PRODUCT.EDIT')->where('entity_id', $p->id)->where('field', 'reorderMin')->exists());
        $after = $this->itemOf('proc', $id, self::$s['p1']);
        $this->assertNotNull($after['pushedAt']);
    }

    public function test_a_frozen_run_is_immutable(): void
    {
        $id = self::$s['run'];
        $before = DB::table('planning_items')->where('run_id', $id)->orderBy('id')->get(['id', 'forecast_total_qty', 'suggested_purchase_qty'])->toArray();
        $this->expectOk($this->postAs('proc', "/api/planning/runs/{$id}/freeze"));
        $this->expectRejected($this->postAs('proc', "/api/planning/runs/{$id}/compute"), 'PLANNING_RUN_FROZEN', [422]);
        $this->expectRejected($this->patchAs('proc', "/api/planning/runs/{$id}", ['growthPct' => 10]), 'PLANNING_RUN_FROZEN', [422]);
        $this->expectRejected($this->postAs('proc', "/api/planning/runs/{$id}/freeze"), 'PLANNING_RUN_TRANSITION', [422]);
        $this->assertEquals($before, DB::table('planning_items')->where('run_id', $id)->orderBy('id')->get(['id', 'forecast_total_qty', 'suggested_purchase_qty'])->toArray());
        $copy = $this->expectOk($this->postAs('proc', "/api/planning/runs/{$id}/copy"));
        $this->assertSame('draft', $copy['status']);
        $this->assertSame(7.0, (float) $copy['parameters']['safetyDays']);
        $this->assertSame([self::$s['p1']->id, self::$s['p2']->id, self::$s['p3']->id], $copy['parameters']['productIds']);
    }

    public function test_who_may_do_what(): void
    {
        $this->expectRejected($this->getAs('sales', '/api/planning/runs'), null, [403]);
        $count = Run::count();
        $this->expectRejected($this->postAs('finance', '/api/planning/runs', ['name' => 'x']), null, [403]);
        $this->assertSame($count, Run::count());
        $this->expectOk($this->getAs('finance', '/api/planning/runs'));                 // page.planning reads
        $this->expectRejected($this->postAs('proc', '/api/planning/runs', ['historyFrom' => '2026-09-01', 'historyTo' => '2026-08-01']), 'PLANNING_WINDOW', [400]);
        $this->expectRejected($this->postAs('proc', '/api/planning/runs', ['historyTo' => '2026-10-05', 'forecastFrom' => '2026-10-01', 'forecastTo' => '2026-12-31', 'historyFrom' => '2026-01-01', 'anchorDate' => '2026-09-18']), 'PLANNING_WINDOW', [400]);
        $this->expectRejected($this->postAs('proc', '/api/planning/runs', ['warehouseIds' => ['nope']]), 'PLANNING_UNKNOWN_REF', [400]);
    }
}
