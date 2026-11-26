<?php

namespace Tests\Feature\Insights;

use App\Insights\Models\BrandAlert;
use App\Insights\Services\BrandAlertsService;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * The eight alerts of a brand dashboard, computed on this story's own brand (clock 2026-09-18: the last complete
 * month is 2026-08). Each rule is met by exactly one designed case; a second run raises nothing; read / unread.
 */
class BrandAlertsTest extends ApiTestCase
{
    use InsightsFixtures;

    public function test_the_eight_rules_raise_each_occurrence_once(): void
    {
        $this->setCutover('2025-12-31');   // this story's history is all OPS
        $tag = self::tag();
        $brand = "QA-AL-{$tag}";
        $u = $this->makeUser(['page.insights']);
        $other = $this->makeUser(['page.insights']);
        $p = $this->makeBrandProduct($brand, 50);
        [$f1, $f2, $f3, $f4] = [$this->makeCustomer(), $this->makeCustomer(), $this->makeCustomer(), $this->makeCustomer()];
        $owner = $this->makeOwner($u, $brand, [[$f1, 1000, '2026-01-01'], [$f2, 500, '2026-01-01'], [$f3, 0, '2026-01-01'], [$f4, 0, '2026-01-01']]);
        $this->makeOwner($other, "QA-AL2-{$tag}");

        foreach (['05', '06', '07'] as $m) {
            $this->invoice($f1, "2026-{$m}-15", [[$p, 10, 100, 50]], 'invoice', null, 0, 'paid');
            $this->invoice($f2, "2026-{$m}-15", [[$p, 6, 100, 50]], 'invoice', null, 0, 'paid');
        }
        $this->invoice($f1, '2026-08-25', [[$p, 3, 100, 50]], 'invoice', null, 0, 'paid');      // drop, target not met, product drop
        $this->invoice($f1, '2026-08-26', [[$p, 1, 40, 50]], 'credit_note', null, 0, 'paid');   // returns 40 / 300 = 13.3 %
        $this->invoice($f2, '2026-08-28', [[$p, 6, 100, 50]], 'invoice', null, 0, 'paid');      // 600 / 500 = 120 %
        $this->invoice($f4, '2026-08-05', [[$p, 1, 100, 50]], 'invoice', '2026-09-01');         // overdue; then silent 44 days
        $wh = DB::table('warehouses')->value('id');
        foreach ([1, 2] as $i) {                                                                    // two cancelled orders of f2
            $so = (string) Str::ulid();
            DB::table('sales_orders')->insert(['id' => $so, 'number' => "QSO-{$tag}-{$i}", 'customer_id' => $f2->id, 'warehouse_id' => $wh, 'status' => 'cancelled', 'date' => now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_order_lines')->insert(['id' => (string) Str::ulid(), 'so_id' => $so, 'line_no' => 1, 'product_id' => $p->id, 'qty' => 2, 'price' => 100]);
        }

        // only a holder of brandowner.manage runs the rules on demand
        $this->expectRejected($this->postAs($u->username, '/api/insights/alerts/run', ['owner' => $owner['id']]), 'FORBIDDEN', [403]);
        $run = $this->expectOk($this->postAs('admin', '/api/insights/alerts/run', ['owner' => $owner['id']]));
        $this->assertSame(['cancelled_orders' => 1, 'high_returns' => 1, 'no_purchase' => 2, 'overdue_invoice' => 1, 'product_drop' => 1, 'purchase_drop' => 1, 'target_exceeded' => 1, 'target_not_met' => 1],
            collect($run['byType'])->sortKeys()->all());
        $this->assertSame(9, $run['raised']);
        $alerts = BrandAlert::where('owner_id', $owner['id'])->get();
        $this->assertSame([$f1->id], $alerts->where('alert_type', 'purchase_drop')->pluck('customer_id')->all());
        $this->assertSame([$f2->id], $alerts->where('alert_type', 'target_exceeded')->pluck('customer_id')->all());
        $this->assertEqualsCanonicalizing([$f3->id, $f4->id], $alerts->where('alert_type', 'no_purchase')->pluck('customer_id')->all());
        $drop = $alerts->firstWhere('alert_type', 'purchase_drop');
        $this->assertSame('2026-08', $drop->data['month']);
        $this->assertEquals(260, $drop->data['purchases']);
        $this->assertEquals(1000, $drop->data['average']);
        $this->assertSame('warning', $drop->severity);
        $this->assertEquals(13.3, $alerts->firstWhere('alert_type', 'high_returns')->data['returnsPct']);
        $this->assertSame(1, Notification::where('user_id', $u->id)->where('entity_id', $owner['id'])->count(), 'one notice to the owner per run');

        // a second run, and the scheduled command, raise nothing more
        $this->assertSame(0, $this->expectOk($this->postAs('admin', '/api/insights/alerts/run', ['owner' => $owner['id']]))['raised']);
        $this->assertSame(0, app(BrandAlertsService::class)->run(null, $owner['id'])['raised']); // what scm:insights-alerts calls
        $this->assertSame(9, BrandAlert::where('owner_id', $owner['id'])->count());

        // the rules are configurable; a disabled rule raises nothing
        $this->expectRejected($this->putAs('admin', '/api/insights/alert-rules/purchase_drop', ['window' => 13]), 'ALERT_WINDOW', [400]);
        $this->expectRejected($this->putAs($u->username, '/api/insights/alert-rules/no_purchase', ['enabled' => false]), 'FORBIDDEN', [403]);
        $this->expectOk($this->putAs('admin', '/api/insights/alert-rules/no_purchase', ['enabled' => false]));
        $f5 = $this->makeCustomer();
        $this->expectOk($this->postAs('admin', "/api/insights/brands/{$owner['id']}/franchisees", ['customerId' => $f5->id, 'dateStart' => '2026-01-01']));
        $this->assertSame(0, $this->expectOk($this->postAs('admin', '/api/insights/alerts/run', ['owner' => $owner['id']]))['raised']);
        $rule = $this->expectOk($this->putAs('admin', '/api/insights/alert-rules/no_purchase', ['enabled' => true, 'window' => 45]));
        $this->assertStringContainsString('45', $rule['rule']['en']);
        $this->assertSame(['no_purchase' => 1], $this->expectOk($this->postAs('admin', '/api/insights/alerts/run', ['owner' => $owner['id']]))['byType'], 'f5 (260 days); f4 is 44 days < 45 and was raised already');
        $this->expectOk($this->putAs('admin', '/api/insights/alert-rules/no_purchase', ['window' => 30]));

        // reading: the owner reads his alerts; another owner cannot
        $list = $this->expectOk($this->getAs($u->username, '/api/insights/alerts?pageSize=5'));
        $this->assertSame(10, $list['total']);
        $this->assertSame(10, $list['unread']);
        $first = $list['items'][0]['id'];
        $this->expectRejected($this->postAs($other->username, "/api/insights/alerts/{$first}/read"), 'BRAND_FORBIDDEN', [403]);
        $this->assertTrue($this->expectOk($this->postAs($u->username, "/api/insights/alerts/{$first}/read"))['read']);
        $this->assertSame(9, $this->expectOk($this->getAs($u->username, '/api/insights/alerts?read=0'))['total']);
        $this->assertFalse($this->expectOk($this->postAs($u->username, "/api/insights/alerts/{$first}/unread"))['read']);
        $this->assertSame(10, $this->expectOk($this->postAs($u->username, "/api/insights/brands/{$owner['id']}/alerts/read-all"))['updated']);
        $this->assertSame(0, $this->expectOk($this->getAs($u->username, '/api/insights/alerts'))['unread']);
        $this->assertSame(0, $this->expectOk($this->getAs($other->username, '/api/insights/alerts'))['total']);
        $byType = $this->expectOk($this->getAs('admin', "/api/insights/alerts?owner={$owner['id']}&type=overdue_invoice"));
        $this->assertSame(1, $byType['total']);
        $this->assertSame('CustomerInvoice', $byType['items'][0]['action']['model']);
    }
}
