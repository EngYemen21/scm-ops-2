<?php

namespace Tests\Feature\Insights;

use App\Finance\Services\FinanceReportsService;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Tests\ApiTestCase;

/**
 * Brand owners, their franchisees and the brand dashboard. Two owners (A, B), a user who is neither, a manager
 * (admin, brandowner.manage). Figures come from this story's own documents: Odoo's history up to the cutover day and
 * OPS invoices after it — each figure is checked by hand and against the list behind it.
 */
class BrandOwnersTest extends ApiTestCase
{
    use InsightsFixtures;

    private static ?array $s = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$s !== null) {
            return;
        }
        $tag = self::tag();
        $brandA = "QA-A-{$tag}";
        $brandB = "QA-B-{$tag}";
        $uA = $this->makeUser(['page.insights']);
        $uB = $this->makeUser(['page.insights']);
        $uN = $this->makeUser(['page.insights']);
        $pA1 = $this->makeBrandProduct($brandA, 12);
        $pA2 = $this->makeBrandProduct($brandA, 60);
        $pX = $this->makeBrandProduct($brandB);
        $c1 = $this->makeCustomer();
        $c2 = $this->makeCustomer();
        $c3 = $this->makeCustomer();
        self::$s = compact('tag', 'brandA', 'brandB', 'uA', 'uB', 'uN', 'pA1', 'pA2', 'pX', 'c1', 'c2', 'c3');
    }

    private function s(string $k): mixed
    {
        return self::$s[$k];
    }

    public function test_1_owners_franchisees_and_their_rules(): void
    {
        $this->setCutover('2026-06-30');
        $a = $this->makeOwner($this->s('uA'), $this->s('brandA'), [[$this->s('c1'), 1000, '2026-01-01'], [$this->s('c2'), 500, '2026-08-01']]);
        $b = $this->makeOwner($this->s('uB'), $this->s('brandB'), [[$this->s('c3'), 0, null]]);
        self::$s['a'] = $a['id'];
        self::$s['b'] = $b['id'];
        $this->assertSame('active', $a['state']);
        $this->assertSame(2, $a['productCount'], 'the two products whose brand is the brand name');
        $this->assertCount(2, $a['franchisees']);
        $this->assertSame('1000.00', collect($a['franchisees'])->firstWhere('customer.id', $this->s('c1')->id)['monthlyTarget']);

        // one user is one owner; one customer is one franchisee of an owner
        $this->expectRejected($this->postAs('admin', '/api/insights/brands', ['name' => 'مكرر', 'userId' => $this->s('uA')->id]), 'BRAND_USER_TAKEN', [409]);
        $this->expectRejected($this->postAs('admin', "/api/insights/brands/{$a['id']}/franchisees", ['customerId' => $this->s('c1')->code]), 'FRANCHISEE_EXISTS', [409]);
        // state machines refuse illegal moves
        $this->expectRejected($this->postAs('admin', "/api/insights/brands/{$a['id']}/state", ['to' => 'draft']), 'BRAND_TRANSITION', [422]);
        $this->expectRejected($this->postAs('admin', "/api/insights/brands/{$a['id']}/state", ['to' => 'suspended']), 'REASON_REQUIRED', [400]);
        $spare = $this->makeUser(['page.insights']);
        $x = $this->expectOk($this->postAs('admin', '/api/insights/brands', ['name' => 'مؤقت', 'userId' => $spare->id]));
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$x['id']}/state", ['to' => 'archived', 'reason' => 'لم يعد شريكًا']));
        $this->expectRejected($this->postAs('admin', "/api/insights/brands/{$x['id']}/state", ['to' => 'active']), 'BRAND_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/insights/brands/{$x['id']}", ['name' => 'جديد']), 'BRAND_ARCHIVED', [422]);
        $f3 = collect($b['franchisees'])->first();
        $this->expectOk($this->postAs('admin', "/api/insights/franchisees/{$f3['id']}/status", ['to' => 'weak']));
        $this->expectRejected($this->postAs('admin', "/api/insights/franchisees/{$f3['id']}/status", ['to' => 'stopped']), 'REASON_REQUIRED', [400]);
        $this->expectOk($this->postAs('admin', "/api/insights/franchisees/{$f3['id']}/status", ['to' => 'stopped', 'reason' => 'أغلق الفرع']));
        $this->expectRejected($this->postAs('admin', "/api/insights/franchisees/{$f3['id']}/status", ['to' => 'active']), 'FRANCHISEE_TRANSITION', [422]);
        $this->expectRejected($this->patchAs('admin', "/api/insights/franchisees/{$f3['id']}", ['monthlyTarget' => 5]), 'FRANCHISEE_STOPPED', [422]);
        // products and categories named on the owner, changes audited
        $pA3 = $this->makeBrandProduct(null);
        $u = $this->expectOk($this->patchAs('admin', "/api/insights/brands/{$a['id']}", ['productIds' => [$pA3->id], 'allowMargin' => true]));
        $this->assertSame(3, $u['productCount']);
        $this->assertTrue($u['allowMargin']);
        $this->expectOk($this->patchAs('admin', "/api/insights/brands/{$a['id']}", ['productIds' => [], 'allowMargin' => false]));
        $this->assertSame(1, AuditLog::where('entity_id', $a['id'])->where('action', 'BRAND_OWNER.CREATE')->count());
        $this->assertSame(2, AuditLog::where('entity_id', $a['id'])->where('action', 'BRAND_OWNER.UPDATE')->count());
        $this->assertTrue(AuditLog::where('entity_id', $x['id'])->where('action', 'STATUS')->exists());
    }

    public function test_2_who_sees_what(): void
    {
        [$a, $b] = [$this->s('a'), $this->s('b')];
        [$uA, $uB, $uN] = [$this->s('uA')->username, $this->s('uB')->username, $this->s('uN')->username];
        // each owner sees his own brand only
        $listA = $this->expectOk($this->getAs($uA, '/api/insights/brands'));
        $this->assertSame([$a], array_column($listA['items'], 'id'));
        $this->expectOk($this->getAs($uA, "/api/insights/brands/{$a}/dashboard"));
        $this->expectRejected($this->getAs($uA, "/api/insights/brands/{$b}"), 'BRAND_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs($uB, "/api/insights/brands/{$a}/dashboard"), 'BRAND_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs($uB, "/api/insights/brands/{$a}/lines?figure=sales"), 'BRAND_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs($uB, "/api/insights/alerts?owner={$a}"), 'BRAND_FORBIDDEN', [403]);
        // a user who is neither sees no brand
        $this->expectRejected($this->getAs($uN, '/api/insights/brands'), 'BRAND_FORBIDDEN', [403]);
        $this->expectRejected($this->getAs($uN, "/api/insights/brands/{$a}/dashboard"), 'BRAND_FORBIDDEN', [403]);
        $this->assertFalse($this->expectOk($this->getAs($uN, '/api/insights/me'))['outsider']);
        // an owner is an outsider: none of the company's own figures
        $me = $this->expectOk($this->getAs($uA, '/api/insights/me'));
        $this->assertTrue($me['outsider']);
        $this->assertSame($a, $me['brandOwner']['id']);
        foreach (['/api/insights/operations/summary', '/api/insights/expiry/log', '/api/insights/expiry/levels', '/api/insights/operations/slow-moving', '/api/insights/stock-card?sku='.$this->s('pA1')->sku] as $url) {
            $this->expectRejected($this->getAs($uA, $url), 'INSIGHTS_OUTSIDER', [403]);
        }
        // changes need brandowner.manage
        $this->expectRejected($this->postAs($uA, '/api/insights/brands', ['name' => 'x', 'userId' => $uA]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs($uA, "/api/insights/brands/{$a}", ['allowMargin' => true]), 'FORBIDDEN', [403]);
        // a suspended owner's user sees nothing until he is active again
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$a}/state", ['to' => 'suspended', 'reason' => 'مراجعة العقد']));
        $this->expectRejected($this->getAs($uA, "/api/insights/brands/{$a}/dashboard"), 'BRAND_NOT_ACTIVE', [403]);
        $this->assertSame([], $this->expectOk($this->getAs($uA, '/api/insights/brands'))['items']);
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$a}/state", ['to' => 'active']));
    }

    public function test_3_the_dashboard_figures_and_the_lists_behind_them(): void
    {
        $a = $this->s('a');
        [$c1, $c2, $c3, $pA1, $pA2, $pX] = [$this->s('c1'), $this->s('c2'), $this->s('c3'), $this->s('pA1'), $this->s('pA2'), $this->s('pX')];
        $n = (int) self::uid();
        $partner1 = 800000 + $n % 100000;
        $odooA1 = 900000 + $n % 100000;
        $this->linkOdoo('customer', $partner1, $c1->id);
        $this->linkOdoo('product', $odooA1, $pA1->id);
        // Odoo's history (cutover 2026-06-30): 500 − 100 counts; an unlinked product and a July invoice do not
        $this->odooInvoice($partner1, '2026-05-10', [[$odooA1, 5, 500], [$odooA1 + 1, 3, 300]], 'out_invoice', '2026-06-09', 115);
        $this->odooInvoice($partner1, '2026-06-05', [[$odooA1, 1, 100]], 'out_refund');
        $this->odooInvoice($partner1, '2026-07-05', [[$odooA1, 4, 400]]);
        // OPS after the cutover: 200 (+ 50 of another brand), 300, credit note 40; a June OPS invoice and a non-franchisee do not count
        $i1 = $this->invoice($c1, '2026-08-10', [[$pA1, 10, 20, 12], [$pX, 5, 10, 8]], 'invoice', '2026-08-10', 0, 'paid');
        $i1->update(['paid_amount' => $i1->total]);
        $this->invoice($c2, '2026-08-15', [[$pA2, 3, 100, 60]], 'invoice', '2026-09-01');
        $this->invoice($c1, '2026-08-20', [[$pA1, 2, 20, 12]], 'credit_note');
        $this->invoice($c2, '2026-06-20', [[$pA2, 10, 100, 60]], 'invoice', '2026-06-20', 0, 'paid');
        $this->invoice($c3, '2026-08-11', [[$pA1, 7, 20, 12]]);

        $d = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/dashboard?from=2026-01-01&to=2026-09-30"));
        $this->assertCount(9, $d['period']['months']);
        $this->assertSame('2026-06-30', $d['cutover']['date']);
        $k = $d['kpis'];
        $this->assertEqualsWithDelta(860.00, $k['purchases'], 0.001);
        $this->assertEqualsWithDelta(1000.00, $k['invoiced'], 0.001);
        $this->assertEqualsWithDelta(140.00, $k['returns'], 0.001);
        $this->assertEqualsWithDelta(10000.00, $k['target'], 0.001, '1000 × 9 months + 500 × 2 months');
        $this->assertEqualsWithDelta(8.6, $k['achievementPct'], 0.001);
        $this->assertSame(2, $k['franchisees']);
        $this->assertEquals(['sales' => 460, 'cogs' => 276, 'margin' => 184, 'marginPct' => 40], $k['margin']);
        $this->assertSame(2, $k['overdue']['count']);
        $this->assertEqualsWithDelta(362.50, $k['overdue']['amount'], 0.001, '300 open in OPS + 500 × 115 / 920 in Odoo');
        $trend = collect($d['trend'])->keyBy('month');
        $this->assertEqualsWithDelta(500.0, $trend['2026-05']['purchases'], 0.001);
        $this->assertEqualsWithDelta(-100.0, $trend['2026-06']['purchases'], 0.001);
        $this->assertEqualsWithDelta(0.0, $trend['2026-07']['purchases'], 0.001);
        $this->assertEqualsWithDelta(460.0, $trend['2026-08']['purchases'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $trend['2026-08']['target'], 0.001);
        $f1 = collect($d['franchisees'])->firstWhere('customer.id', $c1->id);
        $this->assertEqualsWithDelta(560.0, $f1['purchases'], 0.001);
        $this->assertSame('2026-08-10', $f1['lastPurchase']);
        $this->assertSame($pA1->id, $d['topProducts'][0]['product']['id']);
        $this->assertArrayHasKey('purchases', $d['definitions']);

        // every figure is the total of the list behind it
        $sales = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/lines?figure=sales&from=2026-01-01&to=2026-09-30&pageSize=2"));
        $this->assertSame(5, $sales['total']);
        $this->assertCount(2, $sales['items']);
        $this->assertEqualsWithDelta($k['purchases'], $sales['totals']['amount'], 0.001);
        $this->assertSame('2026-08-20', $sales['items'][0]['date'], 'newest first');
        $returns = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/lines?figure=returns&from=2026-01-01&to=2026-09-30"));
        $this->assertEqualsWithDelta(-$k['returns'], $returns['totals']['amount'], 0.001);
        $overdue = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/lines?figure=overdue"));
        $this->assertEqualsWithDelta($k['overdue']['amount'], $overdue['totals']['amount'], 0.001);
        $this->assertSame(['odoo', 'ops'], array_column($overdue['items'], 'source'), 'oldest due first');
        $month = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/lines?figure=sales&month=2026-08&customer={$c1->code}"));
        $this->assertEqualsWithDelta(160.0, $month['totals']['amount'], 0.001);

        // OPS figures agree with the finance profitability report to the halala
        $prof = collect(app(FinanceReportsService::class)->profitability('customer', '2026-07-01', '2026-09-30')['rows'])->firstWhere('code', $c2->code);
        $ops = $this->expectOk($this->getAs('admin', "/api/insights/brands/{$a}/lines?figure=sales&from=2026-07-01&to=2026-09-30&customer={$c2->id}&source=ops"));
        $this->assertEqualsWithDelta($prof['revenue'], $ops['totals']['amount'], 0.001);
        $this->assertEqualsWithDelta($prof['cogs'], $ops['totals']['cogs'], 0.001);
        $this->assertEqualsWithDelta(300.0, $prof['revenue'], 0.001);

        // the owner himself: no payments, no margin (his flags), documents yes
        $uA = $this->s('uA')->username;
        $own = $this->expectOk($this->getAs($uA, "/api/insights/brands/{$a}/dashboard?from=2026-01-01&to=2026-09-30"));
        $this->assertEqualsWithDelta(860.0, $own['kpis']['purchases'], 0.001);
        $this->assertNull($own['kpis']['margin']);
        $this->assertNull($own['kpis']['payments']);
        $this->expectRejected($this->getAs($uA, "/api/insights/brands/{$a}/lines?figure=payments"), 'BRAND_ACCESS_PAYMENTS', [403]);
        $mine = $this->expectOk($this->getAs($uA, "/api/insights/brands/{$a}/lines?figure=sales&from=2026-01-01&to=2026-09-30"));
        $this->assertArrayNotHasKey('cogs', $mine['items'][0]);
        $this->assertArrayHasKey('document', $mine['items'][0]);
        // margin flag alone is not enough: margin also needs inventory.view_cost
        $this->expectOk($this->patchAs('admin', "/api/insights/brands/{$a}", ['allowMargin' => true, 'allowInvoice' => false]));
        $own = $this->expectOk($this->getAs($uA, "/api/insights/brands/{$a}/dashboard?from=2026-01-01&to=2026-09-30"));
        $this->assertNull($own['kpis']['margin']);
        $this->assertNull($own['kpis']['overdue']);
        $this->expectRejected($this->getAs($uA, "/api/insights/brands/{$a}/lines?figure=overdue"), 'BRAND_ACCESS_INVOICES', [403]);
        $this->assertArrayNotHasKey('document', $this->expectOk($this->getAs($uA, "/api/insights/brands/{$a}/lines?figure=sales&from=2026-01-01&to=2026-09-30"))['items'][0]);
        $this->expectOk($this->patchAs('admin', "/api/insights/brands/{$a}", ['allowMargin' => false, 'allowInvoice' => true]));
        $this->assertNotNull(User::find($this->s('uA')->id));
        $this->assertTrue(Customer::whereKey($c1->id)->exists() && Product::whereKey($pA1->id)->exists());
    }
}
