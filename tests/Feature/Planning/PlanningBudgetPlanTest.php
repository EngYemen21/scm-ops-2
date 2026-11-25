<?php

namespace Tests\Feature\Planning;

use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * From a computed run to purchase requests: the run's own budget allocated when it is computed, a scenario of its own
 * (urgency only, partial funding), a purchase plan through its states, and its lines turned into ONE purchase request,
 * once. Requirements of the story (worked out in PlanningRunTest): 11 by 16 Oct, 30 by 27 Oct, 31 by 26 Nov, 10 a unit.
 */
class PlanningBudgetPlanTest extends ApiTestCase
{
    use PlanningFixtures;

    private static array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$s) {
            return;
        }
        $p = $this->product('أرز اختبار الخطة');
        $vendor = $this->supplier('مورد اختبار الخطة');
        $this->supplierPrice($vendor, $p, 10, 5);
        $this->stock($p, 40);
        $this->odooWarehouse();
        $this->rec('res.partner', 970111, ['name' => 'عميل الخطة', 'commercial_partner_id' => [970111, 'x']], true);
        $this->link('product', 970203, $p->id, $p->sku);
        $n = 970601;
        foreach (['2026-03' => 31, '2026-04' => 30, '2026-05' => 31, '2026-06' => 30, '2026-07' => 31, '2026-08' => 31] as $month => $qty) {
            $this->odooMove($n++, 970203, 970111, "{$month}-10 08:00:00", $qty);
        }
        $this->putAs('proc', '/api/planning/warehouse-maps/970001', ['warehouseId' => $this->ryd()->id]);
        $run = $this->expectOk($this->postAs('proc', '/api/planning/runs', [
            'anchorDate' => '2026-09-18', 'historyFrom' => '2026-03-01', 'historyTo' => '2026-08-31', 'forecastFrom' => '2026-10-01', 'forecastTo' => '2026-12-31', 'method' => 'average',
            'safetyMethod' => 'days', 'safetyDays' => 7, 'targetCoverDays' => 30, 'defaultLeadDays' => 3, 'productSelection' => 'manual', 'productIds' => [$p->id], 'warehouseIds' => [$this->ryd()->id],
            'budgetMonthlyAmount' => 500,
        ]));
        $this->computed('proc', $run['id']);
        self::$s = ['p' => $p, 'vendor' => $vendor, 'run' => $run['id']];
    }

    public function test_the_runs_own_budget_is_allocated_when_it_is_computed(): void
    {
        $budgets = $this->expectOk($this->getAs('proc', '/api/planning/runs/'.self::$s['run'].'/budgets'))['items'];
        $this->assertCount(1, $budgets);
        $b = $budgets[0];
        $this->assertTrue($b['isPrimary']);
        $this->assertEquals('allocated', $b['status']);
        // Oct 500: 110 + 300 (90 left, carried) · Nov 500 + 90: 310 (280 left) · Dec 500 + 280 = 780 unused
        $this->assertEquals([1500.0, 720.0, 720.0, 100.0, 780.0], [$b['budgetTotal'], $b['required'], $b['funded'], $b['fundedPct'], $b['unused']]);
        $this->assertEquals([['2026-10', 500.0, 0.0, 410.0, 90.0], ['2026-11', 500.0, 90.0, 310.0, 280.0], ['2026-12', 500.0, 280.0, 0.0, 780.0]],
            array_map(fn ($l) => [$l['month'], $l['amount'], $l['carriedIn'], $l['funded'], $l['remaining']], $b['lines']));
        self::$s['primary'] = $b['id'];
    }

    public function test_a_scenario_of_urgency_only_with_partial_funding(): void
    {
        $b = $this->expectOk($this->postAs('proc', '/api/planning/runs/'.self::$s['run'].'/budgets', [
            'name' => 'سيناريو ضيق', 'weights' => ['urgency' => 1, 'stockout' => 0, 'criticality' => 0, 'velocity' => 0, 'confidence' => 0, 'customer' => 0, 'leadTime' => 0],
            'allowPartial' => true, 'lines' => [['month' => '2026-10', 'amount' => 200], ['month' => '2026-11', 'amount' => 0], ['month' => '2026-12', 'amount' => 0]],
        ]));
        $this->assertEquals('draft', $b['status']);
        $b = $this->expectOk($this->postAs('proc', "/api/planning/budgets/{$b['id']}/allocate"));
        // Oct 200: 11 × 10 = 110 first (order by 16 Oct is the more urgent), then 9 of the 30 (90); the November 31 gets nothing
        $this->assertEquals([720.0, 200.0, 520.0, 27.78], [$b['required'], $b['funded'], $b['deferred'], $b['fundedPct']]);
        $this->assertEquals(['requirements' => 3, 'funded' => 1, 'partial' => 1, 'deferred' => 1, 'criticalDeferred' => 0, 'atRisk' => 0], $b['counts']);
        $rows = $this->expectOk($this->getAs('proc', "/api/planning/budgets/{$b['id']}/allocations"))['items'];
        $this->assertEquals([['funded', 11.0], ['partial', 9.0], ['deferred', 0.0]], array_map(fn ($a) => [$a['status'], (float) $a['fundedQty']], $rows));
        $this->assertEquals('no_budget', $rows[2]['reason']['code']);
        $this->expectRejected($this->patchAs('proc', "/api/planning/budgets/{$b['id']}", ['weights' => ['urgency' => 0]]), 'PLANNING_WEIGHTS_ZERO', [400]);
        // a change takes it back to "not allocated"
        $again = $this->expectOk($this->patchAs('proc', "/api/planning/budgets/{$b['id']}", ['urgencyHorizonDays' => 45]));
        $this->assertEquals('draft', $again['status']);
        $this->assertEquals(0, DB::table('planning_allocations')->where('budget_id', $b['id'])->count());
    }

    public function test_a_purchase_plan_through_its_states_to_one_purchase_request(): void
    {
        $plan = $this->expectOk($this->postAs('proc', '/api/planning/budgets/'.self::$s['primary'].'/plans', ['rfqGrouping' => 'plan', 'name' => 'خطة الربع']));
        $this->assertEquals('draft', $plan['status']);
        $this->assertEquals([11.0, 30.0, 31.0], array_map(fn ($l) => (float) $l['qty'], $plan['lines']));
        $this->assertEquals(720.0, $plan['totals']['planned']);
        // what the plan was made from keeps its figures
        $this->expectRejected($this->patchAs('proc', '/api/planning/runs/'.self::$s['run'], ['growthPct' => 5]), 'PLANNING_RUN_HAS_PLAN', [422]);
        $this->expectRejected($this->postAs('proc', '/api/planning/budgets/'.self::$s['primary'].'/allocate'), 'PLANNING_BUDGET_HAS_PLAN', [422]);
        $this->expectRejected($this->postAs('proc', '/api/planning/budgets/'.self::$s['primary'].'/plans', []), 'PLANNING_PLAN_EMPTY', [422]);   // its allocations are taken

        $first = $plan['lines'][0]['id'];
        $plan = $this->expectOk($this->patchAs('proc', "/api/planning/plans/{$plan['id']}/lines/{$first}", ['qty' => 12]));
        $this->assertEquals([12.0, 120.0], [(float) $plan['lines'][0]['qty'], $plan['lines'][0]['subtotal']]);
        $this->assertEquals(['submit' => 'to_approve'], ['submit' => $this->expectOk($this->postAs('proc', "/api/planning/plans/{$plan['id']}/submit"))['status']]);
        $this->expectRejected($this->patchAs('proc', "/api/planning/plans/{$plan['id']}/lines/{$first}", ['qty' => 13]), 'PLANNING_PLAN_STATE', [422]);
        $this->expectRejected($this->postAs('proc', "/api/planning/plans/{$plan['id']}/approve"), null, [403]);          // proc may plan, not approve
        $back = $this->expectOk($this->postAs('gm', "/api/planning/plans/{$plan['id']}/return", ['reason' => 'راجع الكميات']));
        $this->assertEquals('draft', $back['status']);
        $this->expectOk($this->postAs('admin', "/api/planning/plans/{$plan['id']}/submit"));
        $this->expectRejected($this->postAs('admin', "/api/planning/plans/{$plan['id']}/approve"), 'PLANNING_PLAN_SELF_APPROVAL', [403]);
        $approved = $this->expectOk($this->postAs('gm', "/api/planning/plans/{$plan['id']}/approve", ['note' => 'موافق']));
        $this->assertEquals('approved', $approved['status']);
        $this->expectRejected($this->postAs('proc', "/api/planning/plans/{$plan['id']}/submit"), 'PLANNING_PLAN_TRANSITION', [422]);

        $prs = DB::table('purchase_requisitions')->count();
        $this->expectRejected($this->postAs('gm', "/api/planning/plans/{$plan['id']}/request"), null, [403]);
        $done = $this->expectOk($this->postAs('proc', "/api/planning/plans/{$plan['id']}/request"));
        $this->assertCount(1, $done['requests']);                                       // one vendor, one warehouse, the whole plan
        $this->assertEquals($prs + 1, DB::table('purchase_requisitions')->count());
        $pr = DB::table('purchase_requisitions')->where('number', $done['requests'][0]['number'])->first();
        $this->assertEquals('submitted', $pr->status);
        $this->assertEquals([12, 30, 31], DB::table('pr_lines')->where('pr_id', $pr->id)->orderBy('line_no')->pluck('qty')->map(fn ($q) => (int) $q)->all());
        $this->assertEquals(['rfq', 'rfq', 'rfq'], array_column($done['lines'], 'status'));
        $this->assertEquals(3, DB::table('planning_plan_lines')->where('plan_id', $plan['id'])->whereNotNull('pr_line_id')->distinct()->count('pr_line_id'));
        // once: a second call finds nothing left, and nothing more is made
        $this->expectRejected($this->postAs('proc', "/api/planning/plans/{$plan['id']}/request"), 'PLANNING_PLAN_NOTHING_TO_REQUEST', [422]);
        $this->assertEquals($prs + 1, DB::table('purchase_requisitions')->count());
        $this->expectRejected($this->postAs('proc', "/api/planning/plans/{$plan['id']}/cancel", ['reason' => 'لم تعد لازمة']), 'PLANNING_PLAN_REQUESTED', [422]);
        $closed = $this->expectOk($this->postAs('proc', "/api/planning/plans/{$plan['id']}/close", ['note' => 'اكتملت']));
        $this->assertEquals('done', $closed['status']);
        $this->assertEquals(['draft', 'to_approve', 'draft', 'to_approve', 'approved', 'done'],
            DB::table('status_history')->where('entity_type', 'PurchasePlan')->where('entity_id', $plan['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
    }
}
