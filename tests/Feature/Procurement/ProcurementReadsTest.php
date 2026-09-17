<?php

namespace Tests\Feature\Procurement;

use App\Models\GoodsReceipt;
use App\Models\GrnLine;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Replenishment suggestions and their conversions, the procurement dashboard and supplier performance. */
class ProcurementReadsTest extends ApiTestCase
{
    use ProcurementFixtures;

    public function test_suggestions_formula_reasons_and_filters(): void
    {
        $supplier = $this->makeSupplier(91);
        $empty = $this->makeProduct(40, 50);
        $this->linkPreferred($empty, $supplier, 39, 3);
        $covered = $this->makeProduct(40, 0);
        $short = $this->makeProduct(12.5, 50, ['storage_class' => 'chilled', 'shelf_life_days' => 14]);
        $inv = $this->app->make(InventoryService::class);
        $ryd = Warehouse::where('code', 'RYD')->firstOrFail();
        DB::transaction(fn () => $inv->post(null, null, ['type' => 'opening', 'productId' => $short->id, 'qty' => 30, 'to' => ['warehouseId' => $ryd->id, 'binId' => $inv->bin($ryd->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'Test', 'number' => 'T-PROC']]));
        $this->assertTrue($inv->reconcile()['ok']);

        $all = $this->expectOk($this->getAs('sales', '/api/procurement/suggestions?limit=1000'));
        $this->assertSame(['items', 'total', 'urgent', 'params', 'generatedAt'], array_keys($all));
        $this->assertSame(['windowDays' => 28, 'coverDays' => 8, 'defaultLeadDays' => 5, 'warehouse' => null], $all['params']);
        foreach ($all['items'] as $it) {
            $this->assertGreaterThan(0, $it['sug']);
            $this->assertNotEmpty($it['why']);
            $this->assertSame($it['avail'] === 0, $it['urgent']);
        }
        $this->assertSame($all['urgent'], count(array_filter($all['items'], fn ($i) => $i['urgent'])));
        $urgentFlags = array_column($all['items'], 'urgent');
        $sorted = $urgentFlags;
        rsort($sorted);
        $this->assertSame($sorted, $urgentFlags, 'urgent suggestions come first');
        $this->assertNotContains($covered->sku, array_column($all['items'], 'sku'), 'nothing to suggest when the need is zero');

        $one = $this->expectOk($this->getAs('sales', "/api/procurement/suggestions?sku={$empty->sku}"))['items'][0];
        $this->assertSame([$empty->sku, $empty->id, 'ambient', 'RYD', 0, 0, 3, 50, 0, 50, true], [$one['sku'], $one['productId'], $one['storageClass'], $one['warehouse'], $one['avail'], $one['reserved'], $one['lead'], $one['safety'], $one['incoming'], $one['sug'], $one['urgent']]);
        $this->assertEquals([0, 39], [$one['adc'], $one['price']]);
        $this->assertEquals(['code' => $supplier->code, 'nameAr' => $supplier->name_ar, 'nameEn' => $supplier->name_en, 'score' => 91], $one['supplier']);
        $this->assertSame(['نافد تمامًا', 'مستهلك يومي 0 × مهلة توريد 3 أيام + مخزون أمان 50 + تغطية 8 أيام − متاح 0'], $one['why']);
        $this->assertSame(['Out of stock', 'ADC 0 × lead 3 + safety 50 + 8-day cover − available 0'], $one['whyE']);

        $low = $this->expectOk($this->getAs('sales', "/api/procurement/suggestions?sku={$short->sku}"))['items'][0];
        $this->assertSame([30, 20, false, 5, null], [$low['avail'], $low['sug'], $low['urgent'], $low['lead'], $low['supplier']]);
        $this->assertEquals(12.5, $low['price']);
        $this->assertSame('المتاح 30 أقل من حد الطلب 50', $low['why'][0]);
        $this->assertContains('مبرد قصير العمر (14 يومًا)، طلب صغير متكرر أفضل من مخزون كبير', $low['why']);
        $this->assertContains('لا يوجد مورد مفضل — يُنصح بطلب عروض أسعار', $low['why']);

        // the stock sits in RYD: from JED's point of view the product is out of stock
        $jed = $this->expectOk($this->getAs('sales', "/api/procurement/suggestions?sku={$short->sku}&warehouse=jed"));
        $this->assertSame(['JED', 'JED', 0, 50, true], [$jed['params']['warehouse'], $jed['items'][0]['warehouse'], $jed['items'][0]['avail'], $jed['items'][0]['sug'], $jed['items'][0]['urgent']]);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/procurement/suggestions?sku={$short->sku}&urgent=true"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/procurement/suggestions?sku={$empty->sku}&urgent=true"))['total']);
        $limited = $this->expectOk($this->getAs('sales', '/api/procurement/suggestions?limit=1'));
        $this->assertCount(1, $limited['items']);
        $this->assertSame($all['total'], $limited['total']);
        $this->expectRejected($this->getAs('sales', '/api/procurement/suggestions?limit=0'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('sales', '/api/procurement/suggestions?warehouse=NOPE'), 'WAREHOUSE_NOT_FOUND', [404]);
    }

    public function test_suggestion_conversions_never_skip_the_approval_chain(): void
    {
        $supplier = $this->makeSupplier(91);
        $product = $this->makeProduct(40, 50);
        $this->linkPreferred($product, $supplier, 39, 3);
        $orphan = $this->makeProduct(0, 10, ['purchase_price' => null]);
        $covered = $this->makeProduct(40, 0);

        $this->expectRejected($this->postAs('sales', "/api/procurement/suggestions/{$product->sku}/po"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "/api/procurement/suggestions/{$product->sku}/rfq"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/suggestions/{$covered->sku}/pr"), 'NO_SUGGESTION', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/suggestions/{$product->sku}/pr", ['qty' => 0]), 'INVALID_INPUT', [400]);

        // → PR: body-less, submitted straight to procurement review
        $pr = $this->expectOk($this->postAs('wm', "/api/procurement/suggestions/{$product->sku}/pr"));
        $this->assertSame(['submitted', 'urgent', 'ops', 'RYD', 50, '39.00'], [$pr['status'], $pr['priority'], $pr['costCenter'], $pr['warehouse']['code'], $pr['lines'][0]['qty'], $pr['lines'][0]['estPrice']]);
        $this->assertStringStartsWith(self::future(3), $pr['needDate']);
        $this->assertStringContainsString('نافد تمامًا', $pr['justification']);
        $this->assertSame(['proc'], array_column($pr['approvals'], 'roleKey'));
        $custom = $this->expectOk($this->postAs('wm', "/api/procurement/suggestions/{$product->sku}/pr", ['qty' => 7, 'warehouseCode' => 'JED', 'priority' => 'low', 'justification' => 'تجربة', 'costCenter' => 'cc-1', 'needDate' => self::future(20)]));
        $this->assertSame([7, 'JED', 'low', 'تجربة', 'cc-1'], [$custom['lines'][0]['qty'], $custom['warehouse']['code'], $custom['priority'], $custom['justification'], $custom['costCenter']]);

        // → RFQ: default rule cat (the preferred supplier's category), closes in 3 days
        $rfq = $this->expectOk($this->postAs('proc', "/api/procurement/suggestions/{$product->sku}/rfq"));
        $this->assertSame(['open', 'cat', 'RYD', 50], [$rfq['status'], $rfq['invitedRule'], $rfq['warehouse']['code'], $rfq['lines'][0]['qty']]);
        $this->assertStringStartsWith(self::future(3), $rfq['closeDate']);
        $this->assertSame([$supplier->code], array_map(fn ($s) => $s['supplier']['code'], $rfq['suppliers']));

        // → PO: always a draft; submitting starts the chain
        $po = $this->expectOk($this->postAs('proc', "/api/procurement/suggestions/{$product->sku}/po"));
        $this->assertSame(['draft', '1950.00', 'اقتراح تزويد', $supplier->code, 'RYD'], [$po['status'], $po['total'], $po['reference'], $po['supplier']['code'], $po['warehouse']['code']]);
        $this->assertStringStartsWith(self::future(3), $po['dueDate']);
        $this->assertSame(['proc'], array_column($po['approvals'], 'roleKey'));
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"), 'PO_NOT_PENDING', [422]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"), 'PO_TRANSITION', [422]);
        $this->expectRejected($this->postAs('finance', "/api/procurement/po/{$po['number']}/submit"), 'FORBIDDEN', [403]);
        $pending = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/submit"));
        $this->assertSame(['pending', 0], [$pending['status'], $pending['approvalStep']]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/po/{$po['number']}/submit"), 'PO_TRANSITION', [422]);

        // once the PO is approved its quantity shows as incoming
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"));
        $now = $this->expectOk($this->getAs('proc', "/api/procurement/suggestions?sku={$product->sku}"))['items'][0];
        $this->assertSame(50, $now['incoming']);
        $this->assertContains('قيد التوريد 50 ضمن أوامر شراء مفتوحة', $now['why']);

        // a draft can be dropped
        $draft = $this->expectOk($this->postAs('proc', "/api/procurement/suggestions/{$product->sku}/po", ['qty' => 5, 'price' => 10, 'warehouseCode' => 'DMM', 'dueDate' => self::future(15)]));
        $this->assertSame(['draft', '50.00', 'DMM'], [$draft['status'], $draft['total'], $draft['warehouse']['code']]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('proc', "/api/procurement/po/{$draft['number']}/cancel", ['reason' => 'مسودة غير لازمة']))['status']);

        // no preferred supplier / no price → the buyer must provide them
        $count = PurchaseOrder::count();
        $this->expectRejected($this->postAs('proc', "/api/procurement/suggestions/{$orphan->sku}/po"), 'SUPPLIER_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/suggestions/{$orphan->sku}/po", ['supplierCode' => $supplier->code]), 'PRICE_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', "/api/procurement/suggestions/{$orphan->sku}/po", ['supplierCode' => $supplier->code, 'price' => 0]), 'INVALID_INPUT', [400]);
        $this->assertSame($count, PurchaseOrder::count());
        $priced = $this->expectOk($this->postAs('proc', "/api/procurement/suggestions/{$orphan->sku}/po", ['supplierCode' => $supplier->code, 'price' => 4.5]));
        $this->assertSame(['draft', '45.00'], [$priced['status'], $priced['total']]);
    }

    public function test_dashboard_counters(): void
    {
        $supplier = $this->makeSupplier(91);
        $product = $this->makeProduct(100);
        $before = $this->expectOk($this->getAs('gm', '/api/procurement/dashboard'));
        $this->assertSame(['pendingPrApprovals', 'openRfqs', 'poPendingApproval', 'expectedInboundsThisWeek', 'posSentAwaitingConfirmation', 'urgentSuggestions', 'generatedAt'], array_keys($before));

        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'justification' => 'لوحة المشتريات', 'lines' => [['sku' => $product->sku, 'qty' => 1]]]));
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$pr['number']}/submit"));
        $this->expectOk($this->postAs('proc', '/api/procurement/rfq', ['lines' => [['sku' => $product->sku, 'qty' => 1]], 'invitedRule' => 'manual', 'supplierCodes' => [$supplier->code], 'closeDate' => self::future(2)]));
        $waiting = $this->makePo($supplier->code, $product->sku, 100, 100); // 10,000 → proc, finance
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$waiting['number']}/approve"));
        $sent = $this->makePo($supplier->code, $product->sku, 10, 100, ['dueDate' => self::future(2)]);
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$sent['number']}/approve"));
        $shipment = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$sent['number']}/send"))['shipment'];

        $after = $this->expectOk($this->getAs('gm', '/api/procurement/dashboard'));
        $this->assertSame($before['pendingPrApprovals'] + 1, $after['pendingPrApprovals']);
        $this->assertSame($before['openRfqs'] + 1, $after['openRfqs']);
        $this->assertSame($before['posSentAwaitingConfirmation'] + 1, $after['posSentAwaitingConfirmation']);
        $this->assertSame($before['poPendingApproval']['count'] + 1, $after['poPendingApproval']['count']);
        $this->assertEquals($before['poPendingApproval']['total'] + 10000, $after['poPendingApproval']['total']);
        $finance = fn (array $d) => collect($d['poPendingApproval']['bySteps'])->firstWhere('roleKey', 'finance') ?? ['count' => 0, 'total' => 0];
        $this->assertSame($finance($before)['count'] + 1, $finance($after)['count']);
        $this->assertEquals($finance($before)['total'] + 10000, $finance($after)['total']);
        $this->assertSame('المالية', $finance($after)['labelAr']);
        $this->assertSame($before['expectedInboundsThisWeek']['count'] + 1, $after['expectedInboundsThisWeek']['count']);
        $item = collect($after['expectedInboundsThisWeek']['items'])->firstWhere('number', $shipment['number']);
        $this->assertSame(['id' => $shipment['id'], 'number' => $shipment['number'], 'eta' => $shipment['eta'], 'po' => ['number' => $sent['number']], 'supplier' => ['code' => $supplier->code, 'nameAr' => $supplier->name_ar, 'nameEn' => $supplier->name_en], 'warehouse' => ['code' => 'RYD']], $item);
        $this->assertIsInt($after['urgentSuggestions']);
    }

    public function test_supplier_performance_is_computed_from_receipts(): void
    {
        $supplier = $this->makeSupplier(91, ['otif' => 88, 'fill_rate' => 93, 'lead_days' => 6, 'orders_count' => 12, 'total_value' => 54000]);
        $product = $this->makeProduct(100);

        $stored = $this->expectOk($this->getAs('sales', "/api/suppliers/{$supplier->code}/performance"));
        $this->assertSame(['supplier', 'otif', 'fillRate', 'leadDays', 'score', 'ordersCount', 'totalValue', 'openPos', 'basis', 'stored'], array_keys($stored));
        $this->assertEquals([88, 93, 6, 91, 12, 54000, 0, 'stored'], [$stored['otif'], $stored['fillRate'], $stored['leadDays'], $stored['score'], $stored['ordersCount'], $stored['totalValue'], $stored['openPos'], $stored['basis']['source']]);
        $this->assertSame([$supplier->id, $supplier->code, true, false], [$stored['supplier']['id'], $stored['supplier']['code'], $stored['supplier']['active'], $stored['supplier']['isNew']]);

        // two sent POs: one received in full and on time, one received short
        $receipts = [];
        foreach ([[10, 10], [20, 15]] as [$ordered, $accepted]) {
            $po = $this->makePo($supplier->code, $product->sku, $ordered, 100);
            $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/approve"));
            $shipment = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"))['shipment'];
            $grn = GoodsReceipt::create(['number' => self::code('GRNT'), 'shipment_id' => $shipment['id'], 'po_id' => $po['id'], 'supplier_id' => $supplier->id, 'warehouse_id' => $po['warehouseId'], 'posted_by' => 'test', 'posted_at' => now()]);
            GrnLine::create(['grn_id' => $grn->id, 'line_no' => 1, 'po_line_id' => $po['lines'][0]['id'], 'product_id' => $product->id, 'received_qty' => $accepted, 'accepted_qty' => $accepted]);
            $receipts[$po['number']] = $grn->number;
        }
        $this->makePo($supplier->code, $product->sku, 1, 100); // pending: counted as an order, not as a delivery
        $this->expectOk($this->postAs('proc', "/api/procurement/po/{$this->makePo($supplier->code, $product->sku, 1, 100)['number']}/cancel", ['reason' => 'لا يُحتسب']));

        $perf = $this->expectOk($this->getAs('sales', "/api/suppliers/{$supplier->id}/performance"));
        $this->assertEquals([50, 83, 3, 3100, 3], [$perf['otif'], $perf['fillRate'], $perf['ordersCount'], $perf['totalValue'], $perf['openPos']]);
        $this->assertSame(['source' => 'computed', 'ordersWithGrn' => 2, 'onTimeInFull' => 1, 'orderedQty' => 30, 'acceptedQty' => 25], $perf['basis']);
        $this->assertLessThan(1, $perf['leadDays']);
        $this->assertEquals(['otif' => 88, 'fillRate' => 93, 'leadDays' => 6, 'ordersCount' => 12, 'totalValue' => 54000], $perf['stored']);

        // traceability PO → shipment → GRN
        $number = array_key_first($receipts);
        $po = $this->expectOk($this->getAs('sales', "/api/procurement/po/{$number}"));
        $this->assertSame([$receipts[$number]], $po['trace'][0]['grns']);
        $this->assertSame([$receipts[$number]], array_column($po['grns'], 'number'));
        $this->assertSame(['lines' => 1], $po['grns'][0]['_count']);
        $this->assertSame(['id', 'number', 'postedAt'], array_keys($po['shipments'][0]['grns'][0]));
        $this->assertSame(['lines' => 1, 'grns' => 1], $this->expectOk($this->getAs('sales', "/api/procurement/po?q={$number}"))['items'][0]['_count']);

        $this->expectRejected($this->getAs('sales', '/api/suppliers/SUP-NOPE/performance'), 'SUPPLIER_NOT_FOUND', [404]);
    }
}
