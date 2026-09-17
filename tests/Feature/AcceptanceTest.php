<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FulfillmentOrder;
use App\Models\InboundShipment;
use App\Models\InventoryBalance;
use App\Models\OpsException;
use App\Models\PurchaseOrder;
use App\Models\Trip;
use App\Models\Vehicle;
use Tests\ApiTestCase;

/**
 * END-TO-END ACCEPTANCE SCENARIO — everything created from scratch through the real API, across every domain:
 * product → supplier → PR → approval → RFQ → supplier quotations → comparison → award → PO → approval tiers → expected
 * inbound → receiving (ordered 500, received 490 = accepted 480 + damaged 10, shortage 10) → GRN → staging → putaway →
 * available 480 → quotation → sales order → reservation + FEFO allocation → fulfillment → partial pick → full pick →
 * pack → outbound staging → trip → vehicle → driver → wrong-vehicle scan → loading → dispatch → arrive → partial
 * delivery → POD → return → receive → inspect → restock → reconciliation → traceability → persistence.
 *
 * The steps are one story: they run in order and share state. Persistence is real (MySQL).
 */
class AcceptanceTest extends ApiTestCase
{
    /** @var array<string,mixed> state shared between the steps */
    private static array $s = [];

    private static function n(): string
    {
        // 6 digits: the supplier CR ('1010' + n) must be 10 digits and the VAT number 15.
        return self::$s['n'] ??= substr(self::uid(), -6);
    }

    private static function sku(): string
    {
        return 'ACC-'.self::n();
    }

    public function test_1_master_data(): void
    {
        $n = self::n();
        $p = $this->expectOk($this->postAs('inv', '/api/products', ['sku' => self::sku(), 'nameAr' => "صنف القبول {$n}", 'nameEn' => "Acceptance item {$n}", 'storageClass' => 'ambient', 'weightKg' => 2.5, 'lengthCm' => 30, 'widthCm' => 20, 'heightCm' => 15, 'tracksExpiry' => true, 'reorderMin' => 50, 'purchasePrice' => 40]));
        $this->assertSame(self::sku(), $p['sku']);
        $sup = $this->expectOk($this->postAs('proc', '/api/suppliers', ['nameAr' => "مورد القبول {$n}", 'nameEn' => "Acceptance supplier {$n}", 'cr' => '1010'.$n, 'vat' => '3'.$n.'00000003', 'contact' => 'قسم المبيعات · 05x', 'leadDays' => 5, 'category' => 'dry']));
        self::$s['supplier'] = $sup['code'];
        $this->assertMatchesRegularExpression('/^SUP-/', $sup['code']);
        $c = $this->expectOk($this->postAs('sales', '/api/customers', ['nameAr' => "عميل القبول {$n}", 'zone' => 'شمال الرياض', 'contact' => '05x', 'cr' => '2020'.$n, 'terms' => 'آجل 30 يومًا', 'creditLimit' => 200000, 'city' => 'الرياض']));
        self::$s['customer'] = $c['code'];
        $this->assertMatchesRegularExpression('/^CUS-/', $c['code']);
        // a driver cannot create products
        $this->expectRejected($this->postAs('driver', '/api/products', ['sku' => 'X'.$n, 'nameAr' => 'x', 'nameEn' => 'x', 'weightKg' => 1, 'lengthCm' => 1, 'widthCm' => 1, 'heightCm' => 1]), 'FORBIDDEN', [403]);
    }

    public function test_2_procure_to_expected_inbound(): void
    {
        $n = self::n();
        $sku = self::sku();
        $pr = $this->expectOk($this->postAs('wm', '/api/procurement/pr', ['warehouseCode' => 'RYD', 'needDate' => '2026-09-25', 'priority' => 'urgent', 'justification' => 'سيناريو القبول', 'lines' => [['sku' => $sku, 'qty' => 500]]]));
        $prNo = $pr['number'];
        $this->expectOk($this->postAs('wm', "/api/procurement/pr/{$prNo}/submit"));
        $this->expectRejected($this->postAs('driver', "/api/procurement/pr/{$prNo}/approve"), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$prNo}/approve", ['note' => 'معتمد']));
        $rfq = $this->expectOk($this->postAs('proc', "/api/procurement/pr/{$prNo}/to-rfq", ['closeDate' => '2026-09-20', 'supplierCodes' => [self::$s['supplier'], 'SUP-033'], 'invitedRule' => 'manual', 'deliveryWarehouseCode' => 'RYD']));
        $rfqNo = $rfq['number'] ?? $rfq['rfq']['number'];
        foreach ([[self::$s['supplier'], 38, 5], ['SUP-033', 36, 12]] as [$supplier, $price, $lead]) {
            $this->expectOk($this->postAs('proc', '/api/procurement/quotations', ['supplierCode' => $supplier, 'rfqNumber' => $rfqNo, 'supplierRef' => "Q-{$n}-{$supplier}", 'validUntil' => '2026-10-30', 'leadDays' => $lead, 'paymentTerms' => 'آجل 30 يومًا', 'attachmentName' => 'quote.pdf', 'lines' => [['sku' => $sku, 'qty' => 500, 'price' => $price, 'vatPct' => 15]]]));
        }
        $cmp = $this->expectOk($this->getAs('proc', "/api/procurement/rfq/{$rfqNo}/comparison"));
        $this->assertCount(2, $cmp['quotes']);
        $this->assertNotEmpty($cmp['rec']);
        $chosen = collect($cmp['quotes'])->first(fn ($q) => $q['supplier']['code'] === self::$s['supplier']);
        $this->assertNotNull($chosen);
        $award = $this->expectOk($this->postAs('proc', "/api/procurement/rfq/{$rfqNo}/award", ['quotation' => $chosen['number'], 'warehouseCode' => 'RYD', 'dueDate' => '2026-09-25']));
        $poNo = self::$s['po'] = $award['po']['number'] ?? $award['number'] ?? $award['poNumber'];
        $this->assertMatchesRegularExpression('/^PO-/', $poNo);
        $po = $this->expectOk($this->getAs('proc', "/api/procurement/po/{$poNo}"));
        $this->assertSame(500, array_sum(array_column($po['lines'], 'qty')));

        // approval chain: total 19,000 + VAT → proc + finance
        $this->expectRejected($this->postAs('worker', "/api/procurement/po/{$poNo}/approve"), 'FORBIDDEN', [403]);
        if ($po['status'] === 'draft') {
            $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNo}/submit"));
        }
        foreach (['proc', 'finance', 'gm'] as $role) {
            if ($this->expectOk($this->getAs('proc', "/api/procurement/po/{$poNo}"))['status'] !== 'pending') {
                break;
            }
            $this->expectOk($this->postAs($role, "/api/procurement/po/{$poNo}/approve", ['note' => "اعتماد {$role}"]));
        }
        $this->assertSame('approved', $this->expectOk($this->getAs('proc', "/api/procurement/po/{$poNo}"))['status']);

        $sent = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$poNo}/send"));
        self::$s['shp'] = $sent['shipment']['number'];
        $this->assertMatchesRegularExpression('/^SHP-/', self::$s['shp']);
        $this->postAs('proc', "/api/procurement/po/{$poNo}/send"); // pressing "send" again must not create a second shipment
        $this->assertSame(1, InboundShipment::whereHas('po', fn ($q) => $q->where('number', $poNo))->count());
    }

    public function test_3_receive_grn_putaway(): void
    {
        $n = self::n();
        $sku = self::sku();
        $shp = self::$s['shp'];
        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/arrive"));
        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/inspect"));
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$shp}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 495, 'damagedQty' => 10, 'batchNo' => "B-{$n}", 'expiryDate' => '2027-06-30']]]), 'OVER_RECEIPT');
        $grn = $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 480, 'damagedQty' => 10, 'rejectedQty' => 0, 'batchNo' => "B-{$n}", 'expiryDate' => '2027-06-30']]]));
        self::$s['grn'] = $grn['number'];
        $this->assertSame([480, 10, 10], [$grn['lines'][0]['acceptedQty'], $grn['lines'][0]['damagedQty'], $grn['lines'][0]['remainingQty']]);
        $kinds = OpsException::where('entity_number', $grn['number'])->pluck('kind')->sort()->values()->all();
        $this->assertSame(['damage', 'shortage'], $kinds);
        $po = PurchaseOrder::where('number', self::$s['po'])->firstOrFail();
        $this->assertSame(['partial', 10], [$po->status, $po->open_qty]);

        // received goods are NOT sellable before putaway
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable'] ?? 0);
        $task = $grn['putaways'][0];
        $this->expectRejected($this->postAs('worker', "/api/inbound/putaway/{$task['number']}/confirm", ['scannedBin' => 'FZ-01-1-B2', 'scannedProduct' => $sku]), 'WRONG_LOCATION');
        $bin = self::$s['bin'] = $task['suggestedBin']['code'] ?? 'A-06-1-B1';
        $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$task['number']}/confirm", ['scannedBin' => $bin, 'scannedProduct' => $sku]));
        $this->assertSame(480, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable']);
        $this->assertSame(10, $this->binQty($sku, 'DMG-01'));
        $this->assertTrue($this->expectOk($this->getAs('admin', '/api/inventory/reconciliation?warehouse=RYD'))['ok']);
    }

    public function test_4_quote_to_order_to_fulfillment(): void
    {
        $sku = self::sku();
        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => self::$s['customer'], 'validUntil' => '2026-10-15', 'terms' => 'آجل 30 يومًا', 'delivery' => 'توصيل — نافذة 09–13', 'action' => 'sent', 'lines' => [['sku' => $sku, 'qty' => 100, 'price' => 60, 'discPct' => 5]]]));
        $qtNo = $qt['number'];
        $this->assertEqualsWithDelta(100 * 60 * 0.95 * 1.15, $qt['totals']['total'], 0.05);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qtNo}/convert"), 'QT_NOT_APPROVED');
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qtNo}/approve"));
        $so = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qtNo}/convert", ['warehouseCode' => 'RYD', 'dueDate' => '2026-09-20']));
        self::$s['so'] = $so['number'];
        $this->assertSame('allocated', $so['status']);
        $this->assertSame(100, $so['lines'][0]['reservedQty']);
        $this->assertSame(self::$s['bin'], $so['lines'][0]['allocations'][0]['bin']['code']);
        $this->assertSame(380, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable']);
        $this->expectRejected($this->postAs('sales', "/api/sales/quotations/{$qtNo}/convert"), 'QT_CONVERTED');
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        self::$s['fo'] = $fo['number'];
        $this->assertMatchesRegularExpression('/^FO-/', $fo['number']);
    }

    public function test_5_pick_pack_stage(): void
    {
        $sku = self::sku();
        $foNo = self::$s['fo'];
        $bin = self::$s['bin'];
        $fo = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$foNo}"));
        $task = $fo['pickLists'][0]['tasks'][0];
        $this->assertSame(100, $task['qty']);
        $p1 = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => $bin, 'scannedProduct' => $sku, 'qty' => 60]));
        $this->assertSame(40, $p1['remaining']);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => $bin, 'scannedProduct' => $sku, 'qty' => 41]), 'OVER_PICK');
        $p2 = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => $bin, 'scannedProduct' => $sku, 'qty' => 40]));
        $this->assertTrue($p2['orderDone']);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$foNo}/pack", ['cartons' => 10, 'weightKg' => 250, 'volumeM3' => 0.9]));
        $this->assertSame(100, $this->binQty($sku, 'STG-OUT'));
        $this->assertSame('packed', FulfillmentOrder::where('number', $foNo)->value('status'));
    }

    public function test_6_trip_load_dispatch(): void
    {
        $n = self::n();
        $foNo = self::$s['fo'];
        $trip = $this->expectOk($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'شمال الرياض — قبول', 'foNumbers' => [$foNo], 'tempNeed' => 'dry']));
        $tripNo = self::$s['trip'] = $trip['number'] ?? $trip['trip']['number'];
        $this->assertMatchesRegularExpression('/^TRP-/', $tripNo);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$tripNo}/assign", ['vehicleCode' => 'V-15']));  // in maintenance
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$tripNo}/assign", ['driverCode' => 'DRV-11'])); // blocked documents

        // fleet master data through the API: a dry truck and a driver with a real login
        $veh = self::$s['veh'] = 'V-T'.substr($n, -4);
        $drv = 'DRV-T'.substr($n, -4);
        $drvUser = self::$s['drvUser'] = 'drv-'.$n;
        $this->expectOk($this->postAs('disp', '/api/transport/vehicles', ['code' => $veh, 'plateAr' => 'ق ب ل '.substr($n, -4), 'plateEn' => 'QBL '.substr($n, -4), 'vin' => "VIN{$n}TEST0001", 'brand' => 'Isuzu', 'model' => 'NPR', 'kind' => 'dry', 'ownership' => 'owned', 'maxKg' => 3000, 'maxCbm' => 16, 'pallets' => 8, 'warehouseCode' => 'RYD', 'regExpiry' => '2028-01-01', 'insuranceExpiry' => '2028-01-01', 'inspectionExpiry' => '2028-01-01', 'opCardExpiry' => '2028-01-01']));
        $this->expectOk($this->postAs('disp', '/api/transport/drivers', ['code' => $drv, 'nameAr' => "سائق القبول {$n}", 'nameEn' => 'Acceptance driver', 'employeeNo' => "EMP-{$n}", 'mobile' => '05x', 'licenseNo' => "L-{$n}", 'licenseType' => 'ثقيل', 'licenseExpiry' => '2028-06-01', 'iqamaExpiry' => '2028-06-01', 'shift' => 'am', 'username' => $drvUser, 'password' => env('SEED_PASSWORD')]));
        $rec = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$tripNo}/recommend"));
        $candidates = $rec['candidates'] ?? $rec['items'] ?? $rec;
        $mine = collect($candidates)->first(fn ($c) => ($c['vehicle']['code'] ?? $c['code'] ?? null) === $veh);
        $this->assertTrue($mine['ok'] ?? false, 'the new dry truck must be recommended for a dry trip');
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$tripNo}/assign", ['vehicleCode' => $veh, 'driverCode' => $drv]));

        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$tripNo}/load", ['foNumber' => $foNo, 'scannedVehicle' => 'XYZ 9999']), 'WRONG_VEHICLE');
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$tripNo}/load", ['foNumber' => $foNo, 'scannedVehicle' => $veh, 'scannedOrder' => $foNo]));
        // the wrong-vehicle scan left a critical exception that blocks dispatch until someone resolves it
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$tripNo}/dispatch"), 'BLOCKING_EXCEPTIONS');
        foreach (OpsException::where('entity_number', $tripNo)->where('status', '!=', 'resolved')->where('severity', 'c')->get() as $e) {
            $this->expectOk($this->postAs('disp', "/api/exceptions/{$e->number}/resolve", ['note' => 'مسح خاطئ — تم التحقق']));
        }
        $d = $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$tripNo}/dispatch"));
        $this->assertSame('onroute', $d['status']);
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$tripNo}/dispatch"), 'DISPATCH_DUPLICATE', [409]);
        $this->assertSame('onroute', Vehicle::where('code', $veh)->value('state'));
    }

    public function test_7_deliver_return_reconcile(): void
    {
        $sku = self::sku();
        $driver = self::$s['drvUser'];
        $tripNo = self::$s['trip'];
        $foNo = self::$s['fo'];
        // the dispatcher cannot execute deliveries (server-side RBAC)
        $this->expectRejected($this->postAs('disp', "/api/delivery/trips/{$tripNo}/start"), 'FORBIDDEN', [403]);
        $view = $this->expectOk($this->getAs($driver, '/api/delivery/my-trips'));
        $this->assertSame($tripNo, $view['current']['number']);
        $stop = collect($view['current']['stops'])->first(fn ($s) => ($s['fo']['number'] ?? null) === $foNo);
        $this->expectRejected($this->postAs($driver, "/api/delivery/stops/{$stop['id']}/deliver", ['receiverName' => 'x']), 'ARRIVE_FIRST');
        $this->expectOk($this->postAs($driver, "/api/delivery/trips/{$tripNo}/start"));
        $this->expectOk($this->postAs($driver, "/api/delivery/stops/{$stop['id']}/arrive", ['gps' => ['lat' => 24.77, 'lng' => 46.74]]));
        $pod = $this->expectOk($this->postAs($driver, "/api/delivery/stops/{$stop['id']}/partial", ['receiverName' => 'م. ناصر القحطاني', 'deliveredQty' => 80, 'signature' => 'sig.png', 'photo' => 'door.jpg', 'gps' => ['lat' => 24.77, 'lng' => 46.74]]));
        $this->assertSame([80, 20], [$pod['delivered'], $pod['returned']]);
        $this->assertSame('integration_pending', $pod['attachments']['signature'], 'no upload happened, so none is claimed');
        $rtn = self::$s['rtn'] = $pod['return'];
        $this->assertMatchesRegularExpression('/^RTN-/', $rtn);
        $this->expectRejected($this->postAs($driver, "/api/delivery/stops/{$stop['id']}/deliver", ['receiverName' => 'x']), 'POD_DUPLICATE', [409]);
        $this->assertSame('partial', Trip::where('number', $tripNo)->value('status'));

        $this->expectRejected($this->postAs('wm', "/api/returns/{$rtn}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE');
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/receive"));
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/inspect", ['findings' => 'سليم']));
        $this->assertSame('closed', $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/decide", ['decision' => 'restock']))['status']);
        $this->assertSame(400, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable'], '480 − 100 + 20');
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$tripNo}/close"));
        $this->assertTrue($this->expectOk($this->getAs('admin', '/api/inventory/reconciliation'))['ok']);

        // traceability: SO → FO → POD → return, and the GRN's movements
        $so = $this->expectOk($this->getAs('sales', '/api/sales/orders/'.self::$s['so']));
        $this->assertSame($foNo, $so['fos'][0]['number']);
        $this->assertCount(1, $so['pods']);
        $this->assertSame($rtn, $so['returns'][0]['number']);
        $this->assertGreaterThan(0, $this->expectOk($this->getAs('wm', '/api/inventory/trace/'.self::$s['grn']))['count']);
    }

    /** Every test method boots a fresh application instance, so this is what a backend restart would see. */
    public function test_8_persistence(): void
    {
        $this->assertSame('partial', $this->expectOk($this->getAs('sales', '/api/sales/orders/'.self::$s['so']))['status']);
        $this->assertSame(480, $this->expectOk($this->getAs('wm', '/api/inbound/grns/'.self::$s['grn']))['lines'][0]['acceptedQty']);
        $documents = [self::$s['po'], self::$s['grn'], self::$s['so'], self::$s['fo'], self::$s['trip'], self::$s['rtn']];
        $this->assertGreaterThan(10, AuditLog::whereIn('entity_number', $documents)->count(), 'every step left an audit trail');
    }

    private function binQty(string $sku, string $binCode): int
    {
        return (int) InventoryBalance::whereHas('product', fn ($q) => $q->where('sku', $sku))->whereHas('bin', fn ($q) => $q->where('code', $binCode))->sum('on_hand');
    }
}
