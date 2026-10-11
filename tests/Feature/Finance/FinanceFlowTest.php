<?php

namespace Tests\Feature\Finance;

use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Finance\Models\Period;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\Trip;
use App\Models\TripCost;
use Tests\ApiTestCase;

/**
 * Finance acceptance story (docs/finance/ARCHITECTURE.ar.md §6), one sequential narrative sharing state:
 * procure → receive (inventory + GRNI, average cost) → supplier invoice (AP, PPV, VAT) → partial then full payment →
 * sell → load (goods in transit) → partial delivery (invoice, revenue, VAT, COGS) → POD return → receipt (partial, full) →
 * count adjustment → fleet fuel / expense / asset depreciation → manual journal maker-checker → period close →
 * statements balanced and sub-ledgers = general ledger. Every amount is asserted from the posted journals.
 */
class FinanceFlowTest extends ApiTestCase
{
    private static array $s = [];

    private static function n(): string
    {
        return self::$s['n'] ??= substr(self::uid(), -6);
    }

    private static function sku(): string
    {
        return 'FIN-'.self::n();
    }

    /** Signed balance (debit − credit) of a posting role over posted journals. */
    private function gl(string $role, ?string $sourceNumber = null): float
    {
        $q = JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', '!=', 'draft')->where('fin_accounts.role', $role);
        if ($sourceNumber) {
            $q->where('fin_journals.source_number', $sourceNumber);
        }

        return round((float) $q->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    private function journalsOf(string $sourceType, string $number): array
    {
        return $this->expectOk($this->getAs('finance', "/api/finance/journals?sourceType={$sourceType}&sourceNumber={$number}&pageSize=50"))['items'];
    }

    public function test_01_master_data_and_empty_ledger(): void
    {
        $n = self::n();
        $p = $this->expectOk($this->postAs('inv', '/api/products', ['sku' => self::sku(), 'nameAr' => "صنف مالي {$n}", 'nameEn' => "Finance item {$n}", 'storageClass' => 'ambient', 'weightKg' => 2, 'lengthCm' => 30, 'widthCm' => 20, 'heightCm' => 15, 'reorderMin' => 10, 'purchasePrice' => 40]));
        $this->assertNull($p['avgCost'], 'a new product has no average cost until something valued is received');
        $sup = $this->expectOk($this->postAs('proc', '/api/suppliers', ['nameAr' => "مورد مالي {$n}", 'nameEn' => "Finance supplier {$n}", 'cr' => '1010'.$n, 'vat' => '3'.$n.'00000003', 'contact' => '05x', 'leadDays' => 5, 'category' => 'dry', 'terms' => 'آجل 30 يومًا']));
        self::$s['supplier'] = $sup['code'];
        $c = $this->expectOk($this->postAs('sales', '/api/customers', ['nameAr' => "عميل مالي {$n}", 'zone' => 'شمال الرياض', 'contact' => '05x', 'cr' => '2020'.$n, 'terms' => 'آجل 30 يومًا', 'creditLimit' => 200000, 'city' => 'الرياض']));
        self::$s['customer'] = $c['code'];

        // the chart, periods and bank rows exist; nothing has been posted for these partners
        $chart = $this->expectOk($this->getAs('finance', '/api/finance/accounts'));
        $this->assertContains('ar', array_column($chart['items'], 'role'));
        $this->assertContains('grni', array_column($chart['items'], 'role'));
        $this->assertSame(['BANK', 'CASH'], array_column($this->expectOk($this->getAs('finance', '/api/finance/banks'))['items'], 'code'));
        $this->expectRejected($this->getAs('worker', '/api/finance/board'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('proc', '/api/finance/reports/trial-balance'), 'FORBIDDEN', [403]);
    }

    public function test_02_receipt_values_inventory_and_accrues_the_supplier(): void
    {
        $n = self::n();
        $sku = self::sku();
        $po = $this->expectOk($this->postAs('proc', '/api/procurement/po', ['supplierCode' => self::$s['supplier'], 'warehouseCode' => 'RYD', 'dueDate' => '2026-09-25', 'shippingCost' => 100, 'lines' => [['sku' => $sku, 'qty' => 500, 'price' => 38]]]));
        self::$s['po'] = $po['number'];
        $this->expectOk($this->postAs('admin', "/api/procurement/po/{$po['number']}/approve")); // 19,000 SAR → procurement + finance tiers
        $this->expectOk($this->postAs('finance', "/api/procurement/po/{$po['number']}/approve"));
        $shp = self::$s['shp'] = $this->expectOk($this->postAs('proc', "/api/procurement/po/{$po['number']}/send"))['shipment']['number'];
        $this->assertSame([], $this->journalsOf('PurchaseOrder', $po['number']), 'a purchase order is a commitment, not an accounting event');

        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/arrive"));
        $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/inspect"));
        $grn = $this->expectOk($this->postAs('worker', "/api/inbound/shipments/{$shp}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 480, 'damagedQty' => 10, 'rejectedQty' => 0, 'batchNo' => "B-{$n}", 'expiryDate' => '2027-06-30']]]));
        self::$s['grn'] = $grn['number'];

        // 490 units entered stock (480 accepted + 10 damaged) at the PO price: inventory ↑ 18,620 / GRNI ↑ 18,620
        $journals = $this->journalsOf('GoodsReceipt', $grn['number']);
        $this->assertCount(2, $journals, 'one journal per valued movement (accepted, damaged)');
        $this->assertEqualsWithDelta(18620.00, $this->gl('inventory', $grn['number']), 0.001);
        $this->assertEqualsWithDelta(-18620.00, $this->gl('grni', $grn['number']), 0.001);
        $this->assertEquals(38, Product::where('sku', $sku)->value('avg_cost'));
        $detail = $this->expectOk($this->getAs('finance', "/api/finance/journals/{$journals[0]['number']}"));
        $this->assertSame('posted', $detail['status']);
        $this->assertSame(['GoodsReceipt', $grn['number']], [$detail['sourceType'], $detail['sourceNumber']]);
        $this->assertEqualsWithDelta($detail['totalDebit'], $detail['totalCredit'], 0.001);

        // putaway is a bin move: no journal
        $task = $grn['putaways'][0];
        $bin = self::$s['bin'] = $task['suggestedBin']['code'] ?? 'A-06-1-B1';
        $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$task['number']}/confirm", ['scannedBin' => $bin, 'scannedProduct' => $sku]));
        $this->assertCount(2, $this->journalsOf('GoodsReceipt', $grn['number']));
        $this->assertCount(0, Journal::where('source_type', 'PutawayTask')->get());

        $val = $this->expectOk($this->getAs('finance', '/api/finance/reports/inventory-valuation'));
        $ryd = collect($val['rows'])->firstWhere('code', 'RYD');
        $this->assertGreaterThanOrEqual(18620, $ryd['value']);
    }

    public function test_03_supplier_invoice_closes_grni_and_payments_settle_it_partially_then_fully(): void
    {
        $po = self::$s['po'];
        $inv = $this->expectOk($this->postAs('proc', '/api/procurement/invoices', ['po' => $po, 'supplierInvoiceNo' => 'F-'.self::n(), 'invoiceDate' => now()->toDateString(), 'shippingCost' => 100, 'vatPct' => 15, 'lines' => [['lineNo' => 1, 'qty' => 480, 'price' => 38.5]]])); // 480 accepted (the 10 damaged are not invoiceable)
        self::$s['si'] = $inv['number'];
        $this->assertSame('matched', $inv['status'], '38.5 is within the 2% tolerance of 38');
        $this->assertSame([], $this->journalsOf('SupplierInvoice', $inv['number']), 'registration is not yet an accounting event — approval is');

        $approved = $this->expectOk($this->postAs('finance', "/api/procurement/invoices/{$inv['number']}/approve"));
        $this->assertSame('approved', $approved['status']);
        // GRNI closed at PO price 480 × 38 = 18,240 · PPV 240 (480 × 0.5) · freight-in 100 · VAT input (18,480 + 100) × 15% = 2,787 · AP 21,367
        $this->assertEqualsWithDelta(18240.00, $this->gl('grni', $inv['number']), 0.001);
        $this->assertEqualsWithDelta(240.00, $this->gl('ppv', $inv['number']), 0.001);
        $this->assertEqualsWithDelta(100.00, $this->gl('freight_in', $inv['number']), 0.001);
        $this->assertEqualsWithDelta(2787.00, $this->gl('vat_input', $inv['number']), 0.001);
        $this->assertEqualsWithDelta(-21367.00, $this->gl('ap', $inv['number']), 0.001);
        $this->assertEqualsWithDelta(-380.00, $this->gl('grni', self::$s['grn']) + $this->gl('grni', $inv['number']), 0.001, 'the 10 damaged units (10 × 38) stay accrued until the supplier credits them or they are written off');

        // partial payment through the procurement endpoint (amount), then the rest through finance payments
        $p1 = $this->expectOk($this->postAs('finance', "/api/procurement/invoices/{$inv['number']}/pay", ['reference' => 'TRF-1', 'amount' => 10000]));
        $this->assertSame(['approved', '10000.00'], [$p1['status'], $p1['paidAmount']]);
        $this->expectRejected($this->postAs('finance', '/api/finance/payments', ['supplier' => self::$s['supplier'], 'reference' => 'TRF-X', 'allocations' => [['invoice' => $inv['number'], 'amount' => 20000]]]), 'ALLOC_EXCEEDS', [422]);
        $p2 = $this->expectOk($this->postAs('finance', '/api/finance/payments', ['supplier' => self::$s['supplier'], 'reference' => 'TRF-2', 'bankAccount' => 'BANK', 'allocations' => [['invoice' => $inv['number']]]]));
        $this->assertSame(['out', '11367.00', '11367.00'], [$p2['direction'], $p2['amount'], $p2['allocatedAmount']]);
        $this->assertSame('paid', $this->expectOk($this->getAs('finance', "/api/procurement/invoices/{$inv['number']}"))['status']);
        $this->assertEqualsWithDelta(-21367.00, $this->gl('bank', $p1['payments'][0]['number'] ?? $p2['number']) + ($p1['payments'][0]['number'] ?? null ? 0 : $this->gl('bank', $this->expectOk($this->getAs('finance', '/api/finance/payments?partner='.self::$s['supplier']))['items'][1]['number'])), 0.001);

        $stmt = $this->expectOk($this->getAs('finance', '/api/finance/reports/statement/supplier/'.self::$s['supplier']));
        $this->assertEqualsWithDelta(0, $stmt['closing'], 0.001);
        $this->assertCount(3, $stmt['rows'], 'invoice + two payments');
        $this->expectRejected($this->postAs('proc', "/api/procurement/invoices/{$inv['number']}/cancel", ['reason' => 'خطأ تسجيل']), 'INVOICE_PAID', [422]);
    }

    public function test_04_sale_loading_and_partial_delivery_post_goods_in_transit_revenue_and_cogs(): void
    {
        $n = self::n();
        $sku = self::sku();
        $qt = $this->expectOk($this->postAs('sales', '/api/sales/quotations', ['customerCode' => self::$s['customer'], 'validUntil' => '2026-10-15', 'terms' => 'آجل 30 يومًا', 'delivery' => 'توصيل', 'action' => 'sent', 'lines' => [['sku' => $sku, 'qty' => 100, 'price' => 60, 'discPct' => 5]]]));
        $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/approve"));
        $so = $this->expectOk($this->postAs('sales', "/api/sales/quotations/{$qt['number']}/convert", ['warehouseCode' => 'RYD', 'dueDate' => '2026-09-20']));
        self::$s['so'] = $so['number'];
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $foNo = self::$s['fo'] = $fo['number'];
        $foFull = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$foNo}"));
        $task = $foFull['pickLists'][0]['tasks'][0];
        $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => self::$s['bin'], 'scannedProduct' => $sku, 'qty' => 100]));
        $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$foNo}/pack", ['cartons' => 10, 'weightKg' => 200, 'volumeM3' => 0.9]));
        $this->assertCount(0, Journal::where('source_type', 'FulfillmentOrder')->where('source_number', $foNo)->get(), 'pick and pack move bins only');

        $trip = $this->expectOk($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'شمال الرياض — مالية', 'foNumbers' => [$foNo], 'tempNeed' => 'dry']));
        $tripNo = self::$s['trip'] = $trip['number'] ?? $trip['trip']['number'];
        $veh = self::$s['veh'] = 'V-F'.substr($n, -4);
        $drv = 'DRV-F'.substr($n, -4);
        $drvUser = self::$s['drvUser'] = 'fdrv-'.$n;
        $this->expectOk($this->postAs('disp', '/api/transport/vehicles', ['code' => $veh, 'plateAr' => 'م ا ل '.substr($n, -4), 'plateEn' => 'MAL '.substr($n, -4), 'vin' => "VIN{$n}FIN00001", 'brand' => 'Isuzu', 'model' => 'NPR', 'kind' => 'dry', 'ownership' => 'owned', 'maxKg' => 3000, 'maxCbm' => 16, 'pallets' => 8, 'warehouseCode' => 'RYD', 'regExpiry' => '2028-01-01', 'insuranceExpiry' => '2028-01-01', 'inspectionExpiry' => '2028-01-01', 'opCardExpiry' => '2028-01-01']));
        $this->expectOk($this->postAs('disp', '/api/transport/drivers', ['code' => $drv, 'nameAr' => "سائق مالي {$n}", 'nameEn' => 'Finance driver', 'employeeNo' => "EMPF-{$n}", 'mobile' => '05x', 'licenseNo' => "LF-{$n}", 'licenseType' => 'ثقيل', 'licenseExpiry' => '2028-06-01', 'iqamaExpiry' => '2028-06-01', 'shift' => 'am', 'username' => $drvUser, 'password' => env('SEED_PASSWORD')]));
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$tripNo}/assign", ['vehicleCode' => $veh, 'driverCode' => $drv]));
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$tripNo}/load", ['foNumber' => $foNo, 'scannedVehicle' => $veh, 'scannedOrder' => $foNo]));
        // loading takes 100 units out of the warehouse at the average cost: goods in transit 3,800 / inventory −3,800
        $this->assertEqualsWithDelta(3800.00, $this->gl('goods_in_transit', $foNo), 0.001);
        $this->assertEqualsWithDelta(-3800.00, $this->gl('inventory', $foNo), 0.001);
        foreach (OpsException::where('entity_number', $tripNo)->where('status', '!=', 'resolved')->where('severity', 'c')->get() as $e) {
            $this->expectOk($this->postAs('disp', "/api/exceptions/{$e->number}/resolve", ['note' => 'تم التحقق']));
        }
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$tripNo}/dispatch"));
        $this->assertCount(0, Journal::where('source_type', 'Trip')->get(), 'dispatch is not an accounting event');

        $view = $this->expectOk($this->getAs($drvUser, '/api/delivery/my-trips'));
        $stop = collect($view['current']['stops'])->first(fn ($s) => ($s['fo']['number'] ?? null) === $foNo);
        $this->expectOk($this->postAs($drvUser, "/api/delivery/trips/{$tripNo}/start"));
        $this->expectOk($this->postAs($drvUser, "/api/delivery/stops/{$stop['id']}/arrive", ['gps' => ['lat' => 24.77, 'lng' => 46.74]]));
        $pod = $this->expectOk($this->postAs($drvUser, "/api/delivery/stops/{$stop['id']}/partial", ['receiverName' => 'أبو فهد', 'deliveredQty' => 80, 'signature' => 'sig.png', 'gps' => ['lat' => 24.77, 'lng' => 46.74]]));
        self::$s['rtn'] = $pod['return'];

        // control transferred for 80 units: invoice 80 × 60 × 0.95 = 4,560 + VAT 684 = 5,244 · COGS 80 × 38 = 3,040 out of goods in transit
        $invoices = $this->expectOk($this->getAs('finance', '/api/finance/invoices?customer='.self::$s['customer']))['items'];
        $this->assertCount(1, $invoices);
        $ci = self::$s['ci'] = $invoices[0]['number'];
        $this->assertSame(['invoice', 'issued', '4560.00', '684.00', '5244.00', '3040.00'], [$invoices[0]['kind'], $invoices[0]['status'], $invoices[0]['subtotal'], $invoices[0]['vatAmount'], $invoices[0]['total'], $invoices[0]['cogsTotal']]);
        $this->assertEqualsWithDelta(5244.00, $this->gl('ar', $ci), 0.001);
        $this->assertEqualsWithDelta(-4560.00, $this->gl('revenue', $ci), 0.001);
        $this->assertEqualsWithDelta(-684.00, $this->gl('vat_output', $ci), 0.001);
        $this->assertEqualsWithDelta(3040.00, $this->gl('cogs', $ci), 0.001);
        $this->assertEqualsWithDelta(-3040.00, $this->gl('goods_in_transit', $ci), 0.001);
        $detail = $this->expectOk($this->getAs('finance', "/api/finance/invoices/{$ci}"));
        $this->assertSame([80, '60.00', '38.0000'], [$detail['lines'][0]['qty'], $detail['lines'][0]['price'], $detail['lines'][0]['unitCost']]);
        $this->assertSame(self::$s['fo'], $detail['fo']['number']);
        $this->assertSame(1, Journal::where('event_key', "ci:{$detail['id']}")->count(), 'exactly one invoice journal per POD');
    }

    public function test_05_pod_return_comes_back_from_goods_in_transit_and_receipts_settle_the_invoice(): void
    {
        $rtn = self::$s['rtn'];
        $ci = self::$s['ci'];
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/receive"));
        // 20 undelivered units: inventory 760 / goods in transit −760 → goods in transit is now 0 for this order
        $this->assertEqualsWithDelta(760.00, $this->gl('inventory', $rtn), 0.001);
        $this->assertEqualsWithDelta(-760.00, $this->gl('goods_in_transit', $rtn), 0.001);
        $this->assertEqualsWithDelta(0, $this->gl('goods_in_transit'), 0.001);
        $this->assertCount(0, Journal::where('source_type', 'CustomerInvoice')->where('kind', 'auto')->where('memo', 'like', 'إشعار دائن%')->get(), 'undelivered goods were never invoiced → no credit note');
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/inspect", ['findings' => 'سليم']));
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/decide", ['decision' => 'restock']));
        $this->assertCount(1, $this->journalsOf('ReturnOrder', $rtn), 'restock is a bin move inside the warehouse');

        // receipts: 3,000 then the balance; over-allocation refused
        $this->expectRejected($this->postAs('finance', '/api/finance/receipts', ['customer' => self::$s['customer'], 'amount' => 100, 'allocations' => [['invoice' => $ci, 'amount' => 9999]]]), 'ALLOC_EXCEEDS', [422]);
        $r1 = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => self::$s['customer'], 'amount' => 3000, 'method' => 'bank', 'reference' => 'DEP-1', 'allocations' => [['invoice' => $ci, 'amount' => 3000]]]));
        $this->assertSame(['in', '3000.00', '3000.00', 'posted'], [$r1['direction'], $r1['amount'], $r1['allocatedAmount'], $r1['status']]);
        $this->assertSame('partially_paid', $this->expectOk($this->getAs('finance', "/api/finance/invoices/{$ci}"))['status']);
        $r2 = $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => self::$s['customer'], 'amount' => 2244, 'bankAccount' => 'CASH', 'reference' => 'CASH-1', 'allocations' => [['invoice' => $ci, 'amount' => 2244]]]));
        $this->assertSame('paid', $this->expectOk($this->getAs('finance', "/api/finance/invoices/{$ci}"))['status']);
        $this->assertEqualsWithDelta(2244.00, $this->gl('cash', $r2['number']), 0.001);
        $stmt = $this->expectOk($this->getAs('finance', '/api/finance/reports/statement/customer/'.self::$s['customer']));
        $this->assertEqualsWithDelta(0, $stmt['closing'], 0.001);
        $this->assertEquals([5244, 2244, 0], [$stmt['rows'][0]['balance'], $stmt['rows'][1]['balance'], $stmt['rows'][2]['balance']]);

        // a receipt voided after the fact re-opens the invoice and reverses the journal
        $void = $this->expectOk($this->postAs('gm', "/api/finance/payments/{$r2['number']}/void", ['reason' => 'شيك مرتجع']));
        $this->assertSame('void', $void['status']);
        $this->assertSame('partially_paid', $this->expectOk($this->getAs('finance', "/api/finance/invoices/{$ci}"))['status']);
        $this->assertEqualsWithDelta(2244.00, $this->expectOk($this->getAs('finance', '/api/finance/reports/statement/customer/'.self::$s['customer']))['closing'], 0.001);
        $this->assertSame('reversed', Journal::where('source_number', $r2['number'])->where('kind', 'auto')->value('status'));
        $aging = $this->expectOk($this->getAs('finance', '/api/finance/reports/aging/customer'));
        $this->assertEqualsWithDelta(2244.00, collect($aging['rows'])->firstWhere('code', self::$s['customer'])['total'], 0.001);
        $this->expectRejected($this->postAs('finance', "/api/finance/invoices/{$ci}/cancel", ['reason' => 'اختبار الإلغاء']), 'CI_SETTLED', [422]);
    }

    public function test_06_count_adjustment_fleet_costs_and_depreciation(): void
    {
        $n = self::n();
        $sku = self::sku();
        $veh = self::$s['veh'];
        $tripNo = self::$s['trip'];
        // 5 units written off: inventory adjustment 190 / inventory −190 (5 × 38)
        $adj = $this->expectOk($this->postAs('wm', '/api/inventory/adjust', ['sku' => $sku, 'warehouseCode' => 'RYD', 'binCode' => self::$s['bin'], 'batchNo' => "B-{$n}", 'qtyDelta' => -5, 'reason' => 'فرق جرد']));
        $adjJournal = Journal::where('event_key', "mv:{$adj['id']}")->first();
        $this->assertNotNull($adjJournal);
        $this->assertEqualsWithDelta(190.00, (float) $adjJournal->total_debit, 0.001);

        // fuel on the trip (accrued until paid) and a toll paid from the cash box → trip cost sheet
        $this->expectRejected($this->postAs('finance', '/api/transport/fuel', ['vehicleCode' => $veh, 'date' => now()->toDateString(), 'odometer' => 1200, 'liters' => 100, 'cost' => 300]), 'FORBIDDEN', [403]);
        $fuel = $this->expectOk($this->postAs('disp', '/api/transport/fuel', ['vehicleCode' => $veh, 'date' => now()->toDateString(), 'odometer' => 1200, 'liters' => 100, 'cost' => 300, 'station' => 'أرامكو', 'tripNumber' => $tripNo]));
        $this->assertEqualsWithDelta(300.00, $this->gl('fuel', $fuel['number']), 0.001);
        $this->assertEqualsWithDelta(-300.00, $this->gl('accrued_expenses', $fuel['number']), 0.001);
        $toll = $this->expectOk($this->postAs('finance', '/api/finance/expenses', ['account' => '6130', 'amount' => 50, 'settlement' => 'bank', 'bankAccount' => 'CASH', 'vehicle' => $veh, 'trip' => $tripNo, 'description' => 'رسوم طريق']));
        $this->assertSame(['posted', '50.00'], [$toll['status'], $toll['total']]);
        $costs = TripCost::whereIn('trip_id', Trip::where('number', $tripNo)->pluck('id'))->first();
        $this->assertEquals([300.0, 50.0], [(float) $costs->fuel, (float) $costs->tolls], 'the trip cost sheet is rebuilt from linked fuel and expenses');
        $fleet = $this->expectOk($this->getAs('finance', '/api/finance/reports/fleet-costs?from='.now()->startOfMonth()->toDateString()));
        $v = collect($fleet['vehicles'])->firstWhere('code', $veh);
        $this->assertEquals([300.0, 50.0, 350.0], [$v['fuel'], $v['other'], $v['total']]);

        // maintenance closes with its final cost
        $mo = $this->expectOk($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $veh, 'kind' => 'corrective', 'desc' => 'فرامل', 'shop' => 'ورشة', 'startDate' => now()->toDateString(), 'cost' => 0]));
        $this->assertSame([], $this->journalsOf('MaintenanceOrder', $mo['number']), 'an open order has no final cost yet');
        $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$mo['number']}/close", ['endDate' => now()->toDateString(), 'parts' => 400, 'labor' => 100]));
        $this->assertEqualsWithDelta(500.00, $this->gl('maintenance', $mo['number']), 0.001);

        // the truck as a fixed asset (carried in): 120,000 − 20,000 over 60 months = 1,666.67 per month
        $asset = $this->expectOk($this->postAs('finance', '/api/finance/assets', ['nameAr' => "شاحنة {$veh}", 'vehicle' => $veh, 'acquiredOn' => now()->subMonths(2)->startOfMonth()->toDateString(), 'cost' => 120000, 'salvageValue' => 20000, 'usefulLifeMonths' => 60, 'fundedBy' => 'opening']));
        self::$s['asset'] = $asset['code'];
        $this->assertEqualsWithDelta(120000.00, $this->gl('vehicles'), 0.001);
        $this->expectRejected($this->postAs('finance', '/api/finance/assets', ['nameAr' => 'مكرر', 'vehicle' => $veh, 'acquiredOn' => now()->toDateString(), 'cost' => 1, 'usefulLifeMonths' => 12]), 'ASSET_EXISTS', [409]);
        $dep = $this->expectOk($this->postAs('finance', '/api/finance/assets/depreciate', ['period' => now()->format('Y-m')]));
        $this->assertCount(3, $dep['posted'], 'acquisition month, last month, this month');
        $this->assertEqualsWithDelta(5000.01, $dep['total'], 0.02);
        $again = $this->expectOk($this->postAs('finance', '/api/finance/assets/depreciate', ['period' => now()->format('Y-m')]));
        $this->assertCount(0, $again['posted'], 'depreciation is idempotent per asset and month');
        $this->assertEqualsWithDelta(-$dep['total'], $this->gl('accumulated_depreciation'), 0.02);
    }

    public function test_07_manual_journal_maker_checker_period_close_and_reports(): void
    {
        $draft = $this->expectOk($this->postAs('finance', '/api/finance/journals', ['date' => now()->toDateString(), 'memo' => 'مصروف إداري مستحق', 'lines' => [['account' => '6210', 'debit' => 100, 'description' => 'اشتراك'], ['account' => '2150', 'credit' => 100]]]));
        $this->assertSame(['draft', 'manual'], [$draft['status'], $draft['kind']]);
        $this->expectRejected($this->postAs('finance', '/api/finance/journals', ['date' => now()->toDateString(), 'memo' => 'غير متوازن', 'lines' => [['account' => '6210', 'debit' => 100], ['account' => '2150', 'credit' => 90]]]), 'JOURNAL_UNBALANCED', [422]);
        $this->expectRejected($this->postAs('finance', '/api/finance/journals', ['date' => now()->toDateString(), 'memo' => 'حساب تحكم بلا طرف', 'lines' => [['account' => '1120', 'debit' => 100], ['account' => '2150', 'credit' => 100]]]), 'PARTNER_REQUIRED', [422]);
        $this->expectRejected($this->postAs('finance', "/api/finance/journals/{$draft['number']}/approve"), 'JOURNAL_SELF_APPROVAL', [422]);
        $this->expectRejected($this->postAs('proc', "/api/finance/journals/{$draft['number']}/approve"), 'FORBIDDEN', [403]);
        $posted = $this->expectOk($this->postAs('gm', "/api/finance/journals/{$draft['number']}/approve", ['note' => 'موافق']));
        $this->assertSame(['posted', 'gm'], [$posted['status'], $posted['approvedBy']]);
        $this->expectRejected($this->patchAs('finance', "/api/finance/journals/{$draft['number']}", ['date' => now()->toDateString(), 'memo' => 'تعديل بعد الترحيل', 'lines' => [['account' => '6210', 'debit' => 1], ['account' => '2150', 'credit' => 1]]]), 'JOURNAL_STATE', [422]);
        $rev = $this->expectOk($this->postAs('gm', "/api/finance/journals/{$draft['number']}/reverse", ['reason' => 'قيد بالخطأ']));
        $this->assertSame(['reversal', 'posted'], [$rev['kind'], $rev['status']]);
        $this->assertSame('reversed', $this->expectOk($this->getAs('finance', "/api/finance/journals/{$draft['number']}"))['status']);

        // periods: the oldest open one closes first; a closed period refuses postings; reopening needs a reason and gl.close
        $periods = $this->expectOk($this->getAs('finance', '/api/finance/periods'))['items'];
        $oldest = collect($periods)->sortBy('code')->first()['code'];
        $this->assertNotSame(now()->format('Y-m'), $oldest, 'depreciation created earlier periods');
        $this->expectRejected($this->postAs('finance', '/api/finance/periods/'.now()->format('Y-m').'/close'), 'PERIOD_ORDER', [422]);
        $this->expectRejected($this->postAs('proc', "/api/finance/periods/{$oldest}/close"), 'FORBIDDEN', [403]);
        $this->assertSame('closed', $this->expectOk($this->postAs('finance', "/api/finance/periods/{$oldest}/close", ['notes' => 'إقفال شهري']))['status']);
        $inClosed = Period::where('code', $oldest)->first()->starts_on->toDateString();
        $this->expectRejected($this->postAs('finance', '/api/finance/journals', ['date' => $inClosed, 'memo' => 'في فترة مغلقة', 'lines' => [['account' => '6210', 'debit' => 1], ['account' => '2150', 'credit' => 1]]]), 'PERIOD_CLOSED', [422]);
        $this->expectRejected($this->postAs('finance', "/api/finance/periods/{$oldest}/reopen", ['reason' => 'x']), null, [400]);
        $this->assertSame('open', $this->expectOk($this->postAs('finance', "/api/finance/periods/{$oldest}/reopen", ['reason' => 'قيد تسوية متأخر']))['status']);

        // statements from the journals: trial balance and balance sheet balance; P&L carries the story's figures
        $tb = $this->expectOk($this->getAs('finance', '/api/finance/reports/trial-balance?from=2000-01-01&to='.now()->toDateString()));
        $this->assertTrue($tb['balanced']);
        $bs = $this->expectOk($this->getAs('finance', '/api/finance/reports/balance-sheet'));
        $this->assertTrue($bs['balanced']);
        $pl = $this->expectOk($this->getAs('finance', '/api/finance/reports/income-statement?from=2000-01-01&to='.now()->toDateString()));
        $this->assertGreaterThanOrEqual(4560, $pl['totals']['revenue']);
        $this->assertGreaterThanOrEqual(3040 + 240 + 100 + 190, $pl['totals']['cogs']);
        $prof = $this->expectOk($this->getAs('finance', '/api/finance/reports/profitability?by=product&from=2000-01-01'));
        $row = collect($prof['rows'])->firstWhere('code', self::sku());
        $this->assertEquals([80, 4560.0, 3040.0, 1520.0], [$row['qty'], $row['revenue'], $row['cogs'], $row['grossProfit']]);
        $vat = $this->expectOk($this->getAs('finance', '/api/finance/reports/vat?from=2000-01-01'));
        $this->assertGreaterThanOrEqual(684.00, $vat['outputVat']);
        $this->assertGreaterThanOrEqual(2787.00, $vat['inputVat']);
        $this->assertEqualsWithDelta(-684.00, $this->gl('vat_output', self::$s['ci']), 0.001);
        $ledger = $this->expectOk($this->getAs('finance', '/api/finance/reports/ledger/1130?from=2000-01-01'));
        $this->assertNotEmpty($ledger['rows']);
        $this->assertSame('GoodsReceipt', $ledger['rows'][0]['sourceType'], 'the account ledger drills down to the source document');

        // the auditor's first question: sub-ledgers equal the general ledger
        $rec = $this->expectOk($this->getAs('finance', '/api/finance/reconciliation'));
        foreach ($rec['checks'] as $c) {
            $this->assertTrue($c['ok'], "{$c['key']} sub-ledger {$c['subLedger']} ≠ GL {$c['gl']}");
        }
        $this->assertTrue($rec['trialBalanceBalanced']);
        $board = $this->expectOk($this->getAs('gm', '/api/finance/board'));
        $this->assertSame(['cash', 'receivables', 'payables', 'monthToDate', 'inventory', 'vat', 'period', 'journalsToday', 'generatedAt'], array_keys($board));
    }
}
