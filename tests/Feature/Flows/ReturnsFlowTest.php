<?php

namespace Tests\Feature\Flows;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\IntegrationEvent;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\Supplier;
use Tests\ApiTestCase;

/** Returns: pending → approved → received (RET-01) → inspect → decision; every decision is a stock movement. */
class ReturnsFlowTest extends ApiTestCase
{
    use FlowFixtures;

    public function test_strict_state_machine_no_decision_before_receiving_and_inspection(): void
    {
        $product = $this->product('RTN-SM');
        $this->stock($product, 'A-07-1-B1', 50);
        $customer = Customer::firstOrFail();
        $r = $this->expectOk($this->postAs('sales', '/api/returns', ['type' => 'cust', 'source' => 'مطاعم البلدة', 'reference' => 'INV-77', 'customerCode' => $customer->code,
            'warehouseCode' => 'RYD', 'reasonCode' => 'cust_reject', 'notes' => 'العميل رفض الاستلام', 'lines' => [['sku' => $product->sku, 'qty' => 3]]]));
        $this->assertStringStartsWith('RTN-', $r['number']);
        $this->assertSame(['pending', 'cust', 'رفض العميل — العميل رفض الاستلام', $customer->id], [$r['status'], $r['type'], $r['reasonAr'], $r['customerId']]);
        $url = "/api/returns/{$r['number']}";

        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/receive"), 'RTN_TRANSITION', [422]); // must be approved first
        $this->expectRejected($this->postAs('wm', "{$url}/inspect"), 'RTN_TRANSITION', [422]);
        $this->assertSame(0, $this->onHand($product, 'RET-01'), 'a return that was not received holds no stock');

        $this->expectRejected($this->postAs('driver', "{$url}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "{$url}/approve"), 'FORBIDDEN', [403]);
        $approved = $this->expectOk($this->postAs('wm', "{$url}/approve"));
        $this->assertSame('approved', $approved['status']);
        $this->assertNotNull($approved['approvedAt']);
        $this->expectRejected($this->postAs('wm', "{$url}/reject"), 'RTN_TRANSITION', [422]);

        $received = $this->expectOk($this->postAs('wm', "{$url}/receive"));
        $this->assertSame(['received', 'RET-01', 'wm'], [$received['status'], $received['receiving']['binCode'], $received['receiving']['receivedBy']]);
        $this->assertSame([['ret', 3, null, 'RET-01']], array_map(fn ($m) => [$m['type'], $m['qty'], $m['srcBin'], $m['dstBin']['code']], $received['movements']));
        $this->assertSame(3, $this->onHand($product, 'RET-01'));
        $this->assertSame(50, $this->inv()->availability($product->id)['total'], 'stock in the returns area is not available');
        $this->expectRejected($this->postAs('wm', "{$url}/receive"), 'RTN_TRANSITION', [422]);
        $this->assertSame(3, $this->onHand($product, 'RET-01'), 'a return cannot be received twice');

        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'qtn']), 'RTN_DECISION_STATE', [422]);
        $this->expectRejected($this->postAs('driver', "{$url}/inspect"), 'FORBIDDEN', [403]);
        $inspected = $this->expectOk($this->postAs('wm', "{$url}/inspect"));  // no body
        $this->assertSame(['inspect', null], [$inspected['status'], $inspected['inspection']['findings']]);

        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'burn']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "{$url}/decide", ['decision' => 'qtn']), 'FORBIDDEN', [403]);
        $closed = $this->expectOk($this->postAs('wm', "{$url}/decide", ['decision' => 'qtn', 'note' => 'بانتظار الجودة']));
        $this->assertSame(['closed', 'qtn', 'wm'], [$closed['status'], $closed['decision'], $closed['decidedBy']]);
        $this->assertSame([null, 'pending', 'approved', 'received', 'inspect'], array_column($closed['history'], 'fromStatus'));
        $this->assertSame(['qtn', 3, 'بانتظار الجودة'], [$closed['decisions'][0]['decision'], $closed['decisions'][0]['qty'], $closed['decisions'][0]['note']]);
        $this->assertSame(['qtn', 3], [$closed['lines'][0]['decision'], $closed['lines'][0]['inspectedQty']]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);

        $qtn = InventoryBalance::where('product_id', $product->id)->where('bin_id', $this->inv()->bin($this->ryd()->id, 'QTN-01')->id)->firstOrFail();
        $this->assertSame([3, true], [$qtn->on_hand, (bool) $qtn->quarantine]);
        $this->assertSame(0, $this->onHand($product, 'RET-01'));
        $this->assertSame(50, $this->inv()->availability($product->id)['total'], 'quarantined stock is not available');
        $this->assertSame(1, AuditLog::where('action', 'RTN.DECIDE')->where('entity_number', $r['number'])->where('new_value', 'qtn')->count());
        $this->assertReconciles();
    }

    public function test_restock_goes_to_a_valid_bin_and_wrong_locations_are_rejected(): void
    {
        $product = $this->product('RTN-RST', tracksExpiry: true);
        $batch = Batch::create(['product_id' => $product->id, 'batch_no' => 'B-'.self::uid(), 'expiry_date' => now()->addYear()]);
        $number = $this->returnInInspection($product, 8, ['batchNo' => $batch->batch_no]);
        $detail = $this->expectOk($this->getAs('sales', "/api/returns/{$number}"));
        $this->assertSame([$batch->batch_no, $batch->id], [$detail['lines'][0]['batchNo'], $detail['lines'][0]['batchId']]);
        $this->assertSame($batch->batch_no, $detail['lines'][0]['batch']['batchNo']);

        $this->expectRejected($this->postAs('wm', "/api/returns/{$number}/decide", ['decision' => 'restock', 'binCode' => 'FZ-01-1-B1']), 'WRONG_LOCATION', [422]);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$number}/decide", ['decision' => 'restock', 'binCode' => 'Z-99-9-B9']), 'BIN_NOT_FOUND', [404]);
        $this->assertSame('inspect', ReturnOrder::where('number', $number)->value('status'), 'a rejected decision changes nothing');
        $this->assertSame(8, $this->onHand($product, 'RET-01'));

        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$number}/decide", ['decision' => 'restock', 'binCode' => 'a-07-1-b2']));
        $this->assertSame('closed', $closed['status']);
        $this->assertSame('A-07-1-B2', $closed['lines'][0]['bin']['code']);
        $this->assertSame([['ret', null, 'RET-01'], ['restock', 'RET-01', 'A-07-1-B2']], array_map(fn ($m) => [$m['type'], $m['srcBin']['code'] ?? null, $m['dstBin']['code'] ?? null], $closed['movements']));
        $this->assertSame([0, 8], [$this->onHand($product, 'RET-01'), $this->onHand($product, 'A-07-1-B2')]);
        $this->assertSame(8, $this->inv()->availability($product->id)['total']);
        $this->assertSame($batch->id, InventoryMovement::where('reference_number', $number)->where('type', 'restock')->value('batch_id'));

        // no bin given: the bin that already holds the product is chosen
        $again = $this->returnInInspection($product, 2, ['batchNo' => $batch->batch_no]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$again}/decide", ['decision' => 'restock']));
        $this->assertSame(10, $this->onHand($product, 'A-07-1-B2'));

        // no bin given and no stock anywhere: the first storage bin of the product's zone class
        $frozen = $this->product('RTN-FZ', storageClass: 'frozen');
        $cold = $this->expectOk($this->postAs('wm', '/api/returns/'.$this->returnInInspection($frozen, 4).'/decide', ['decision' => 'restock']));
        $this->assertStringStartsWith('FZ-', $cold['lines'][0]['bin']['code']);
        $this->assertSame(4, $this->inv()->availability($frozen->id)['total']);
        $this->assertReconciles();
    }

    public function test_damaged_scrap_and_supplier_decisions_move_stock_out_of_the_returns_area(): void
    {
        // damaged → DMG-01 (on hand, never available)
        $dmg = $this->product('RTN-DMG');
        $closed = $this->expectOk($this->postAs('wm', '/api/returns/'.$this->returnInInspection($dmg, 5, type: 'dmg', reason: 'damaged').'/decide', ['decision' => 'dmg']));
        $this->assertSame(['dmg', 'RET-01', 'DMG-01'], [$closed['movements'][1]['type'], $closed['movements'][1]['srcBin']['code'], $closed['movements'][1]['dstBin']['code']]);
        $this->assertSame([0, 5, 0], [$this->onHand($dmg, 'RET-01'), $this->onHand($dmg, 'DMG-01'), $this->inv()->availability($dmg->id)['total']]);

        // scrap → leaves the warehouse
        $scrap = $this->product('RTN-SCR');
        $closed = $this->expectOk($this->postAs('wm', '/api/returns/'.$this->returnInInspection($scrap, 6, reason: 'expired').'/decide', ['decision' => 'dispose']));
        $this->assertSame(['scrap', 'RET-01', null], [$closed['movements'][1]['type'], $closed['movements'][1]['srcBin']['code'], $closed['movements'][1]['dstBin']]);
        $this->assertSame(0, $this->totalOnHand($scrap));

        // return to supplier needs a supplier on the return
        $sup = $this->product('RTN-SUP');
        $noSupplier = $this->returnInInspection($sup, 7);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$noSupplier}/decide", ['decision' => 'sup']), 'SUPPLIER_REQUIRED', [400]);
        $this->assertSame(7, $this->onHand($sup, 'RET-01'), 'the rejected decision is rolled back as a whole');
        $this->expectRejected($this->postAs('wm', '/api/returns', ['type' => 'sup', 'source' => 'مورد', 'warehouseCode' => 'RYD', 'reasonCode' => 'quality', 'lines' => [['sku' => $sup->sku, 'qty' => 1]]]), 'SUPPLIER_REQUIRED', [400]);

        $supplier = Supplier::firstOrFail();
        $withSupplier = $this->returnInInspection($sup, 9, type: 'sup', reason: 'quality', extra: ['supplierCode' => $supplier->code]);
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$withSupplier}/decide", ['decision' => 'sup']));
        $this->assertSame(['supret', null], [$closed['movements'][1]['type'], $closed['movements'][1]['dstBin']]);
        $this->assertSame($supplier->code, $closed['supplier']['code']);
        $this->assertSame(7, $this->totalOnHand($sup), 'only the undecided return is still in RET-01');
        $this->assertSame(1, IntegrationEvent::where('type', 'SupplierReturnCreated')->where('payload->rtn', $withSupplier)->count());
        $this->assertReconciles();
    }

    public function test_create_validation_rejection_listing_and_lookup(): void
    {
        $product = $this->product('RTN-VAL');
        $valid = ['type' => 'cust', 'source' => 'عميل نقدي', 'warehouseCode' => 'RYD', 'reasonCode' => 'other', 'lines' => [['sku' => $product->sku, 'qty' => 2]]];
        $count = ReturnOrder::count();

        $this->expectRejected($this->postAs('driver', '/api/returns', $valid), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', '/api/returns', ['lines' => []] + $valid), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/returns', ['reasonCode' => 'because'] + $valid), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/returns', ['lines' => [['sku' => $product->sku, 'qty' => 0]]] + $valid), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/returns', ['lines' => [['sku' => 'NOPE-SKU', 'qty' => 1]]] + $valid), 'SKU_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('sales', '/api/returns', ['warehouseCode' => 'NOPE'] + $valid), 'WH_NOT_FOUND', [404]);
        $this->assertSame($count, ReturnOrder::count(), 'a rejected request creates nothing');

        $r = $this->expectOk($this->postAs('sales', '/api/returns', $valid + ['attachments' => ['photo-1.jpg']]));
        $this->assertSame(['photo-1.jpg'], $r['attachments']);
        $rejected = $this->expectOk($this->postAs('wm', "/api/returns/{$r['id']}/reject", ['reason' => 'خارج مدة الإرجاع']));
        $this->assertSame('rejected', $rejected['status']);
        $this->assertSame('خارج مدة الإرجاع', collect($rejected['history'])->last()['note']);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$r['number']}/approve"), 'RTN_TRANSITION', [422]);
        $this->assertSame(0, $this->totalOnHand($product));

        $list = $this->expectOk($this->getAs('inv', '/api/returns?status=rejected&type=cust&warehouse=RYD&q='.$r['number']));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame([1, $r['number'], $product->sku], [$list['total'], $list['items'][0]['number'], $list['items'][0]['lines'][0]['product']['sku']]);
        $this->assertSame(0, $this->expectOk($this->getAs('inv', '/api/returns?status=closed&q='.$r['number']))['total']);
        $this->expectRejected($this->getAs('inv', '/api/returns/RTN-NOPE'), 'RTN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/returns/RTN-NOPE/approve'), 'RTN_NOT_FOUND', [404]);
    }

    /** A return taken through approve → receive → inspect over the API; returns its number. */
    private function returnInInspection(Product $product, int $qty, array $line = [], string $type = 'cust', string $reason = 'wrong_product', array $extra = []): string
    {
        $r = $this->expectOk($this->postAs('sales', '/api/returns', $extra + ['type' => $type, 'source' => 'اختبار', 'warehouseCode' => 'RYD', 'reasonCode' => $reason,
            'lines' => [$line + ['sku' => $product->sku, 'qty' => $qty]]]));
        foreach (['approve', 'receive'] as $step) {
            $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/{$step}"));
        }
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/inspect", ['findings' => 'سليم']));

        return $r['number'];
    }
}
