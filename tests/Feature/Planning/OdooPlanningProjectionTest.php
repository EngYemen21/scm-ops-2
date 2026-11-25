<?php

namespace Tests\Feature\Planning;

use App\Planning\OdooPlanningProjection;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From the copy of Odoo's demand-forecast module to the planning section. The Odoo user cannot read these models today,
 * so no real record exists: the copy is built here with records of exactly the shape of docs/odoo/shapes/unreadable.txt
 * (the twelve models), and Odoo itself is never called. Proven: the plan writes nothing; the apply makes each record
 * once and links products and warehouses by identity; a second apply changes nothing; who owns what afterwards; a run
 * deleted in Odoo is archived, not removed.
 */
class OdooPlanningProjectionTest extends ApiTestCase
{
    use PlanningFixtures;

    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['odoo.enabled' => false, 'odoo.project.planning' => false]);
        if (self::$s) {
            return;
        }
        $p = $this->product('منتج من تشغيل أودو');
        $c = $this->customer('عميل من تشغيل أودو');
        $this->link('product', 970901, $p->id, $p->sku);
        $this->link('customer', 970902, $c->id, $c->code);
        $this->odooWarehouse();
        $this->putAs('proc', '/api/planning/warehouse-maps/970001', ['warehouseId' => $this->ryd()->id]);
        foreach (array_keys(OdooPlanningProjection::TABLES) as $model) {
            $this->odooModel($model);
        }
        $prod = [970901, "[{$p->sku}] منتج من تشغيل أودو"];
        $wh = [970001, 'مستودع اختبار التخطيط'];
        $this->rec('b2b.demand.forecast', 9701, ['name' => 'DF/2026/0007', 'state' => 'computed', 'company_id' => [1, 'Co'], 'date_ref' => '2026-09-01', 'date_from' => '2026-03-01',
            'date_to' => '2026-08-31', 'forecast_date_from' => '2026-09-01', 'forecast_date_to' => '2026-11-30', 'method' => 'wma', 'wma_window' => 3, 'growth_factor_pct' => 5.0,
            'demand_netting' => 'net', 'history_source' => 'delivery', 'safety_stock_method' => 'service_level', 'service_level' => '95', 'safety_stock_days' => 0.0, 'target_cover_days' => 30.0,
            'use_vendor_lead_time' => true, 'default_lead_days' => 7.0, 'qty_multiple_source' => 'uom_po', 'orderpoint_trigger' => 'manual', 'product_selection' => 'manual',
            'product_ids' => [970901, 970999], 'warehouse_ids' => [970001], 'customer_mode' => 'none', 'item_count' => 1, 'total_forecast_qty' => 90.0, 'total_suggested_purchase_value' => 450.5,
            'user_id' => [2, 'مخطط أودو'], 'cadence_fallback' => 'estimate', 'rollup_method' => 'pooled']);
        $this->rec('b2b.demand.forecast.item', 9711, ['run_id' => [9701, 'DF/2026/0007'], 'product_id' => $prod, 'warehouse_id' => $wh, 'partner_id' => false, 'is_rollup' => false,
            'qty_on_hand' => 12.0, 'forecast_total_qty' => 90.0, 'status' => 'shortage', 'suggested_purchase_qty' => 45.0, 'suggested_purchase_date' => '2026-09-10',
            'suggested_purchase_value' => 450.5, 'method_fallback' => true, 'fallback_reason' => 'Seasonal fell back', 'orderpoint_state' => 'differs', 'vendor_id' => false]);
        $this->rec('b2b.demand.forecast.line', 9721, ['run_id' => [9701, 'x'], 'item_id' => [9711, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh, 'period_index' => 1, 'period_label' => '2026-09',
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'day_count' => 30, 'forecast_demand_qty' => 30.0, 'projected_balance_qty' => -18.0, 'odoo_forecast_qty' => 5.0]);
        $this->rec('b2b.demand.forecast.history', 9731, ['run_id' => [9701, 'x'], 'item_id' => [9711, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh, 'period_index' => 6, 'period_label' => '2026-08',
            'date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'day_count' => 31, 'qty' => 31.0, 'source' => 'delivery']);
        $this->rec('b2b.demand.forecast.pattern', 9741, ['run_id' => [9701, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh, 'partner_id' => [970902, 'عميل'], 'order_count' => 5, 'state' => 'active',
            'confidence_band' => 'high', 'customer_criticality' => 'high', 'avg_interval_days' => 7.0, 'next_expected_date' => '2026-09-05']);
        $this->rec('b2b.demand.forecast.event', 9751, ['run_id' => [9701, 'x'], 'pattern_id' => [9741, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh, 'partner_id' => [970902, 'عميل'],
            'expected_date' => '2026-09-05', 'qty' => 6.0, 'actual_state' => 'ordered', 'actual_qty' => 7.0]);
        $this->rec('b2b.demand.forecast.requirement', 9761, ['run_id' => [9701, 'x'], 'item_id' => [9711, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh, 'need_date' => '2026-09-17',
            'trigger_date' => '2026-09-10', 'qty' => 45.0, 'price_unit' => 10.011, 'price_source' => 'vendor', 'value' => 450.5, 'product_criticality' => 'critical']);
        $this->rec('b2b.demand.forecast.budget', 9771, ['name' => 'Q4', 'run_id' => [9701, 'x'], 'state' => 'allocated', 'is_primary' => true, 'weight_urgency' => 30, 'budget_total' => 1000.0, 'funded_value' => 450.5]);
        $this->rec('b2b.demand.forecast.budget.line', 9781, ['budget_id' => [9771, 'Q4'], 'month' => '2026-09-01', 'amount' => 1000.0, 'funded_value' => 450.5]);
        $this->rec('b2b.demand.forecast.allocation', 9791, ['budget_id' => [9771, 'Q4'], 'budget_line_id' => [9781, 'x'], 'requirement_id' => [9761, 'x'], 'month' => '2026-09-01', 'status' => 'funded',
            'funded_qty' => 45.0, 'funded_value' => 450.5, 'rank' => 1, 'reason' => false]);
        $this->rec('b2b.purchase.plan', 9801, ['name' => 'PP/0003', 'state' => 'approved', 'run_id' => [9701, 'x'], 'budget_id' => [9771, 'Q4'], 'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
            'rfq_grouping' => 'week', 'approved_by_id' => [2, 'مدير']]);
        $this->rec('b2b.purchase.plan.line', 9811, ['plan_id' => [9801, 'PP/0003'], 'allocation_id' => [9791, 'x'], 'requirement_id' => [9761, 'x'], 'product_id' => $prod, 'warehouse_id' => $wh,
            'qty' => 45.0, 'price_unit' => 10.011, 'subtotal' => 450.5, 'order_date' => '2026-09-10', 'status' => 'confirmed', 'purchase_order_id' => [55, 'P00055']]);
        self::$s = ['p' => $p, 'c' => $c];
    }

    private function projection(): OdooPlanningProjection
    {
        return $this->app->make(OdooPlanningProjection::class);
    }

    public function test_the_plan_counts_and_writes_nothing(): void
    {
        $before = DB::table('planning_runs')->where('source_system', 'odoo')->count();
        $plan = $this->projection()->plan();
        $this->assertTrue($plan['dryRun']);
        $this->assertSame(12, $plan['counts']['created'] ?? 0);
        $this->assertSame($before, DB::table('planning_runs')->where('source_system', 'odoo')->count());
    }

    public function test_apply_makes_every_record_once_and_links_by_identity(): void
    {
        $out = $this->projection()->apply();
        $this->assertSame(12, $out['counts']['created'] ?? 0, json_encode($out));
        $run = DB::table('planning_runs')->where('source_system', 'odoo')->where('external_ref', '9701')->first();
        $this->assertSame(['O-DF/2026/0007', 'computed', 'wma', 'pack', 'service_level', '95'], [$run->number, $run->status, $run->method, $run->qty_multiple_source, $run->safety_method, $run->service_level]);
        $this->assertSame([self::$s['p']->id], json_decode($run->product_ids, true));               // 970999 has no OPS product
        $this->assertSame([$this->ryd()->id], json_decode($run->warehouse_ids, true));
        $this->assertSame(1, json_decode($run->extra, true)['unlinked']['products']);
        $item = DB::table('planning_items')->where('source_system', 'odoo')->where('external_ref', '9711')->first();
        $this->assertSame([$run->id, self::$s['p']->id, $this->ryd()->id, self::$s['p']->sku, 'shortage'], [$item->run_id, $item->product_id, $item->warehouse_id, $item->sku, $item->status]);
        $this->assertSame(450.5, (float) $item->suggested_purchase_value);
        $pattern = DB::table('planning_patterns')->where('external_ref', '9741')->where('source_system', 'odoo')->first();
        $this->assertSame(self::$s['c']->id, $pattern->customer_id);
        $alloc = DB::table('planning_allocations')->where('source_system', 'odoo')->where('external_ref', '9791')->first();
        $this->assertSame([DB::table('planning_requirements')->where('external_ref', '9761')->where('source_system', 'odoo')->value('id'), $run->id], [$alloc->requirement_id, $alloc->run_id]);
        $line = DB::table('planning_plan_lines')->where('source_system', 'odoo')->where('external_ref', '9811')->first();
        $this->assertSame($alloc->id, $line->allocation_id);
        $this->assertSame('O-PP/0003', DB::table('planning_plans')->where('id', $line->plan_id)->value('number'));
        // the API reads it like any run
        $api = $this->expectOk($this->getAs('proc', "/api/planning/runs/{$run->id}"));
        $this->assertSame('odoo', $api['sourceSystem']);
        self::$s['run'] = $run->id;

        $again = $this->projection()->apply();
        $this->assertSame(['unchanged' => 12], $again['counts']);
    }

    public function test_what_odoo_owns(): void
    {
        $id = self::$s['run'];
        config(['odoo.enabled' => true, 'odoo.project.planning' => true]);
        $this->expectRejected($this->patchAs('proc', "/api/planning/runs/{$id}", ['growthPct' => 1]), 'PLANNING_OWNED_BY_SOURCE', [422]);
        $budget = DB::table('planning_budgets')->where('run_id', $id)->value('id');
        $this->expectRejected($this->postAs('proc', "/api/planning/budgets/{$budget}/allocate"), 'PLANNING_OWNED_BY_SOURCE', [422]);
        config(['odoo.enabled' => false, 'odoo.project.planning' => false]);              // handed over: still never recomputed here
        $this->expectRejected($this->postAs('proc', "/api/planning/runs/{$id}/compute"), 'PLANNING_RUN_FROM_SOURCE', [422]);
        $this->expectOk($this->postAs('proc', "/api/planning/runs/{$id}/copy"));
    }

    public function test_a_run_deleted_in_odoo_is_archived(): void
    {
        DB::table('odoo_records')->where('model', 'b2b.demand.forecast')->where('odoo_id', 9701)->update(['deleted_at' => now()]);
        $out = $this->projection()->apply();
        $this->assertSame(1, $out['counts']['archived'] ?? 0);
        $run = DB::table('planning_runs')->where('id', self::$s['run'])->first();
        $this->assertFalse((bool) $run->active);
        $this->assertNotNull($run->source_deleted_at);
        $this->assertSame(1, DB::table('planning_items')->where('run_id', $run->id)->count());      // its rows stay with it
    }
}
