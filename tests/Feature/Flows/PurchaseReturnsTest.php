<?php

namespace Tests\Feature\Flows;

use App\Finance\Models\DebitNote;
use App\Finance\Models\Journal;
use App\Models\AuditLog;
use App\Models\IntegrationEvent;
use App\Models\InventoryBalance;
use App\Models\InventoryHold;
use App\Models\ReturnOrder;
use Tests\ApiTestCase;

/**
 * Returns taken from stock (`source_mode = stock`): a purchase return to a supplier and damaged goods moved out of
 * their bin. requested → approved (the quantity is held where it lies) → shipped → closed. Nothing is created in
 * RET-01, the shelf row really goes down, and a purchase return ends with a supplier debit note.
 */
class PurchaseReturnsTest extends ApiTestCase
{
    use FlowFixtures, ReturnsFixtures;

    public function test_purchase_return_holds_the_stock_ships_it_and_issues_the_debit_note(): void
    {
        $supplier = $this->newSupplier();
        $product = $this->product('PRT-A', tracksExpiry: true);
        $batchNo = 'PB-'.self::uid();
        // two receipts of the same batch: 100 @ 10 then 100 @ 14 → average cost 12; the second order is the one returned on
        $this->receipt($supplier, $product, 100, 10.0, 'A-07-1-B1', $batchNo, 15, daysAgo: 3);
        [$po, $grn, $batch] = $this->receipt($supplier, $product, 100, 14.0, 'A-07-1-B1', $batchNo, 15);
        $this->assertEqualsWithDelta(12.0, (float) $product->refresh()->avg_cost, 0.0001);
        $base = ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality'];
        $line = fn (array $l = []) => ['lines' => [$l + ['sku' => $product->sku, 'qty' => 20, 'fromBin' => 'A-07-1-B1']]];

        // ── creation: the line names the bin the goods leave from
        $this->expectRejected($this->postAs('driver', '/api/returns', $base + $line()), 'FORBIDDEN', [403]);
        $rejected = $this->expectRejected($this->postAs('proc', '/api/returns', $base + ['lines' => [['sku' => $product->sku, 'qty' => 20]]]), 'FROM_BIN_REQUIRED', [422]);
        $this->assertSame('lines.0.fromBin', $rejected['details']['field']);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['fromBin' => 'Z-99-9-B9'])), 'BIN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['fromBin' => 'RET-01'])), 'FROM_BIN_INVALID', [422]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['fromBin' => 'A-07-1-B2'])), 'NO_STOCK', [422]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['qty' => 201])), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['batchNo' => 'NOPE'])), 'BATCH_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['unitPrice' => -1])), 'INVALID_INPUT', [400]);
        $other = $this->newSupplier();
        [$foreignPo] = $this->receipt($other, $this->product('PRT-X'), 5, 3.0, 'A-07-1-B2');
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + ['poNumber' => $foreignPo->number] + $line()), 'REFERENCE_MISMATCH', [422]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + ['grnNumber' => 'GRN-NOPE'] + $line()), 'GRN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + ['poNumber' => 'PO-NOPE'] + $line()), 'PO_NOT_FOUND', [404]);
        $this->assertSame(0, ReturnOrder::where('supplier_id', $supplier->id)->count(), 'a rejected request creates nothing');

        $r = $this->expectOk($this->postAs('proc', '/api/returns', $base + ['grnNumber' => $grn->number] + $line()));  // procurement creates it
        $this->assertSame(['pending', 'sup', 'stock', $po->id, $grn->id, $supplier->id], [$r['status'], $r['type'], $r['sourceMode'], $r['poId'], $r['grnId'], $r['supplierId']]);
        $url = "/api/returns/{$r['number']}";
        $detail = $this->expectOk($this->getAs('proc', $url));
        $this->assertSame(['A-07-1-B1', $batch->id, $batchNo, null], [$detail['lines'][0]['fromBin']['code'], $detail['lines'][0]['batchId'], $detail['lines'][0]['batchNo'], $detail['lines'][0]['unitPrice']], 'the only batch in the bin is the batch');
        $this->assertSame([true, null, true, $po->number, $grn->number], [$detail['flow']['fromStock'], $detail['flow']['blocked'], $detail['flow']['cancel']['allowed'], $detail['po']['number'], $detail['grn']['number']]);
        $this->assertSame([200, 0], [$this->inv()->availability($product->id)['total'], InventoryHold::where('owner_id', $r['id'])->count()], 'a request holds nothing');

        // ── approval holds the quantity in its bin
        $this->expectRejected($this->postAs('proc', "{$url}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$url}/ship"), 'RTN_TRANSITION', [422]); // not approved yet
        $approved = $this->expectOk($this->postAs('wm', "{$url}/approve"));
        $this->assertSame('approved', $approved['status']);
        $this->assertSame([['A-07-1-B1', $batchNo, 20]], array_map(fn ($h) => [$h['bin'], $h['batchNo'], $h['qty']], $approved['holds']));
        $row = InventoryBalance::where('product_id', $product->id)->where('batch_id', $batch->id)->firstOrFail();
        $this->assertSame([200, 20, 180], [$row->on_hand, $row->reserved, $this->inv()->availability($product->id)['total']], 'held stock cannot be sold or moved');
        $this->assertSame(0, $this->onHand($product, 'RET-01'), 'nothing is created in the returns area');
        $this->assertReconciles();
        $this->expectRejected($this->postAs('wm', "{$url}/receive"), 'RTN_FROM_STOCK', [422]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $base + $line(['qty' => 181])), 'INSUFFICIENT_AVAILABLE', [422]); // the held 20 are not free

        // ── shipping: the goods leave to the supplier and the debit note is issued
        $this->expectRejected($this->postAs('proc', "{$url}/ship"), 'FORBIDDEN', [403]);
        $shipped = $this->expectOk($this->postAs('wm', "{$url}/ship", ['carrier' => 'ناقل المورد', 'note' => 'سلّمت للمندوب']));
        $this->assertSame(['closed', 'sup', 'ناقل المورد', 'سلّمت للمندوب'], [$shipped['status'], $shipped['decision'], $shipped['carrier'], $shipped['shipNote']]);
        $this->assertNotNull($shipped['shippedAt']);
        $this->assertSame([null, 'pending', 'approved', 'shipped'], array_column($shipped['history'], 'fromStatus'));
        $this->assertSame([['supret', 20, 'A-07-1-B1', null]], array_map(fn ($m) => [$m['type'], $m['qty'], $m['srcBin']['code'], $m['dstBin']], $shipped['movements']), 'the shelf row itself goes down');
        $this->assertSame([['sup', 20, 1, $supplier->code]], array_map(fn ($d) => [$d['decision'], $d['qty'], $d['lineNo'], $d['supplier']['code']], $shipped['decisions']));
        $this->assertSame([], $shipped['holds']);
        $row->refresh();
        $this->assertSame([180, 0, 180], [$row->on_hand, $row->reserved, $this->inv()->availability($product->id)['total']]);
        $this->assertReconciles();
        $this->expectRejected($this->postAs('wm', "{$url}/ship"), 'RTN_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/cancel", ['reason' => 'تراجع المورد']), 'RTN_TRANSITION', [422]);
        $this->assertSame(1, IntegrationEvent::where('type', 'SupplierReturnCreated')->where('payload->rtn', $r['number'])->count());
        $this->assertSame(1, AuditLog::where('action', 'RTN.SHIP')->where('entity_number', $r['number'])->count());

        // ── the debit note: 20 × 14 (the order's price) = 280 + 15 % VAT = 322; the stock left the books at 20 × 12 = 240
        $this->assertCount(1, $shipped['debitNotes']);
        $dn = $shipped['debitNotes'][0];
        $this->assertMatchesRegularExpression('/^DN-\d{4}-\d{5}$/', $dn['number']);
        $this->assertSame(['issued', '280.00', '42.00', '322.00', '240.00', $supplier->code], [$dn['status'], $dn['subtotal'], $dn['vatAmount'], $dn['total'], $dn['costTotal'], $dn['supplier']['code']]);
        $this->assertEquals([$product->sku, 20, 14, 280, 240, 'po'], [$dn['lines'][0]['sku'], $dn['lines'][0]['qty'], $dn['lines'][0]['price'], $dn['lines'][0]['total'], $dn['lines'][0]['cost'], $dn['lines'][0]['priceSource']]);
        $this->assertSame(DebitNote::where('number', $dn['number'])->value('id'), ReturnOrder::where('number', $r['number'])->value('debit_note_id'));
        // finance invariants: payable ↓ by exactly the note total, inventory ↓ by the cost; the note's journal carries only the difference
        $this->assertEqualsWithDelta(322.00, $this->payableDebit($supplier), 0.001);
        $this->assertEqualsWithDelta(-240.00, $this->gl('inventory', $r['number']), 0.001);
        $this->assertEqualsWithDelta(240.00, $this->gl('ap', $r['number']), 0.001);
        $this->assertEqualsWithDelta(82.00, $this->gl('ap', $dn['number']), 0.001);
        $this->assertEqualsWithDelta(-40.00, $this->gl('ppv', $dn['number']), 0.001);
        $this->assertEqualsWithDelta(-42.00, $this->gl('vat_input', $dn['number']), 0.001);
        $this->assertSame(1, Journal::where('source_type', 'DebitNote')->where('source_number', $dn['number'])->count(), 'one journal per note');
        $list = $this->expectOk($this->getAs('proc', '/api/returns?type=sup&sourceMode=stock&supplier='.$supplier->code));
        $this->assertSame([1, $r['number'], [['number' => $dn['number'], 'total' => '322.00', 'status' => 'issued']]], [$list['total'], $list['items'][0]['number'], $list['items'][0]['debitNotes']]);
    }

    public function test_price_on_the_line_wins_and_a_price_below_cost_swaps_the_variance_side(): void
    {
        $supplier = $this->newSupplier();
        $product = $this->product('PRT-P');
        [$po] = $this->receipt($supplier, $product, 50, 10.0, 'A-07-1-B2', null, 5); // VAT 5 % on this order
        $r = $this->expectOk($this->postAs('proc', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged',
            'poNumber' => $po->number, 'lines' => [['sku' => $product->sku, 'qty' => 10, 'fromBin' => 'A-07-1-B2', 'unitPrice' => 8.5]]]));
        $this->assertSame('8.5000', $r['lines'][0]['unitPrice'] ?? $this->expectOk($this->getAs('proc', "/api/returns/{$r['number']}"))['lines'][0]['unitPrice']);
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/ship"));  // no body
        $dn = $closed['debitNotes'][0];
        // 10 × 8.5 = 85 + 5 % = 89.25; cost 10 × 10 = 100 → the supplier credits LESS than the books carried: variance is a debit
        $this->assertSame(['85.00', '4.25', '89.25', '100.00', 'return'], [$dn['subtotal'], $dn['vatAmount'], $dn['total'], $dn['costTotal'], $dn['lines'][0]['priceSource']]);
        $this->assertEqualsWithDelta(89.25, $this->payableDebit($supplier), 0.001, 'payable ↓ by the note total, whatever the sign of the difference');
        $this->assertEqualsWithDelta(-10.75, $this->gl('ap', $dn['number']), 0.001);
        $this->assertEqualsWithDelta(15.00, $this->gl('ppv', $dn['number']), 0.001);
        $this->assertEqualsWithDelta(-4.25, $this->gl('vat_input', $dn['number']), 0.001);
        $this->assertEqualsWithDelta(-100.00, $this->gl('inventory', $r['number']), 0.001);
        $this->assertSame(40, $this->inv()->availability($product->id)['total']);
        $this->assertReconciles();
    }

    public function test_cancel_releases_the_holds_and_damaged_goods_move_to_the_damaged_area(): void
    {
        $product = $this->product('PRT-D');
        $this->stockAt($product, 'A-07-1-B1', 30, 4.0);
        $base = ['type' => 'dmg', 'source' => 'المستودع', 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged'];
        $line = fn (int $qty, string $bin = 'A-07-1-B1') => ['lines' => [['sku' => $product->sku, 'qty' => $qty, 'fromBin' => $bin]]];

        // approved → cancelled gives the quantity back
        $r = $this->expectOk($this->postAs('wm', '/api/returns', $base + $line(12)));
        $url = "/api/returns/{$r['number']}";
        $this->assertSame(['pending', 'stock'], [$r['status'], $r['sourceMode']]);
        $this->expectOk($this->postAs('wm', "{$url}/approve"));
        $this->assertSame(18, $this->inv()->availability($product->id)['total']);
        $this->expectRejected($this->postAs('wm', '/api/returns', $base + $line(19)), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->expectRejected($this->postAs('sales', "{$url}/cancel", ['reason' => 'خطأ']), 'FORBIDDEN', [403]);
        $cancelled = $this->expectOk($this->postAs('wm', "{$url}/cancel", ['reason' => 'تبيّن أنها سليمة']));
        $this->assertSame(['cancelled', []], [$cancelled['status'], $cancelled['holds']]);
        $this->assertSame([30, 0], [$this->inv()->availability($product->id)['total'], InventoryHold::where('owner_id', $r['id'])->where('status', 'active')->count()]);
        $this->assertSame(1, InventoryHold::where('owner_id', $r['id'])->where('status', 'released')->count());
        $this->expectRejected($this->postAs('wm', "{$url}/ship"), 'RTN_TRANSITION', [422]);
        $this->assertReconciles();

        // two requests may ask for more than the bin holds; only the first approval gets the stock
        $first = $this->expectOk($this->postAs('wm', '/api/returns', $base + $line(20)));
        $second = $this->expectOk($this->postAs('wm', '/api/returns', $base + $line(20)));
        $this->expectOk($this->postAs('wm', "/api/returns/{$first['number']}/approve"));
        $rejected = $this->expectRejected($this->postAs('wm', "/api/returns/{$second['number']}/approve"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([1, 10], [$rejected['details']['lineNo'], $rejected['details']['available']]);
        $this->assertSame(['pending', 0], [ReturnOrder::where('number', $second['number'])->value('status'), InventoryHold::where('owner_id', $second['id'])->count()]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "/api/returns/{$second['number']}/cancel", ['reason' => 'الكمية غير متوفرة']))['status']);

        // ship: bin → DMG-01, an internal move (stays on hand, leaves the available stock, no journal)
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$first['number']}/ship"));
        $this->assertSame(['closed', 'dmg', []], [$closed['status'], $closed['decision'], $closed['debitNotes']]);
        $this->assertSame([['dmg', 20, 'A-07-1-B1', 'DMG-01']], array_map(fn ($m) => [$m['type'], $m['qty'], $m['srcBin']['code'], $m['dstBin']['code']], $closed['movements']));
        $this->assertSame([10, 20, 30, 10], [$this->onHand($product, 'A-07-1-B1'), $this->onHand($product, 'DMG-01'), $this->totalOnHand($product), $this->inv()->availability($product->id)['total']]);
        $this->assertSame(0, Journal::where('source_type', 'ReturnOrder')->where('source_number', $first['number'])->count(), 'a move inside the warehouse is not an accounting event');
        $this->assertSame(0, $this->onHand($product, 'RET-01'));
        $this->assertReconciles();

        // what is already in the damaged area is not "returned as damaged" again
        $this->expectRejected($this->postAs('wm', '/api/returns', $base + $line(5, 'DMG-01')), 'FROM_BIN_INVALID', [422]);
        // … but it can go back to the supplier it came from
        $supplier = $this->newSupplier();
        $fromDamaged = $this->expectOk($this->postAs('wm', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged',
            'lines' => [['sku' => $product->sku, 'qty' => 20, 'fromBin' => 'DMG-01']]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$fromDamaged['number']}/approve"));
        $done = $this->expectOk($this->postAs('wm', "/api/returns/{$fromDamaged['number']}/ship"));
        $this->assertSame(['closed', 0, 10], [$done['status'], $this->onHand($product, 'DMG-01'), $this->totalOnHand($product)]);
        // no purchase order for this supplier: claimed at the cost the books carried (20 × 4) + 15 % VAT
        $this->assertSame(['80.00', '92.00', '80.00', 'cost'], [$done['debitNotes'][0]['subtotal'], $done['debitNotes'][0]['total'], $done['debitNotes'][0]['costTotal'], $done['debitNotes'][0]['lines'][0]['priceSource']]);
        $this->assertEqualsWithDelta(92.00, $this->payableDebit($supplier), 0.001);
        $this->assertReconciles();
    }

    public function test_stock_rows_offer_what_the_supplier_delivered(): void
    {
        $supplier = $this->newSupplier();
        $mine = $this->product('PRT-S1', tracksExpiry: true);
        $batchNo = 'SR-'.self::uid();
        [$po, $grn] = $this->receipt($supplier, $mine, 40, 7.5, 'A-07-1-B1', $batchNo);
        $foreign = $this->product('PRT-S2');
        $this->stockAt($foreign, 'A-07-1-B2', 15, 3.0);

        $this->expectRejected($this->getAs('driver', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('proc', '/api/returns/stock-rows'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('proc', '/api/returns/stock-rows?warehouse=RYD&supplier=SUP-NOPE'), 'SUPPLIER_NOT_FOUND', [404]);

        $out = $this->expectOk($this->getAs('proc', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}"));
        $this->assertSame('supplier', $out['scope']);
        $this->assertEquals([[$mine->sku, 'A-07-1-B1', $batchNo, 40, 40, 7.5, 'po', $po->number]],
            array_map(fn ($r) => [$r['sku'], $r['bin'], $r['batchNo'], $r['onHand'], $r['available'], $r['price'], $r['priceSource'], $r['poNumber']], $out['rows']));
        $this->assertSame([[$grn->number, $po->number]], array_map(fn ($g) => [$g['number'], $g['poNumber']], $out['receipts']['grns']));
        $this->assertSame([$po->number], array_column($out['receipts']['pos'], 'number'));
        $this->assertCount(1, $this->expectOk($this->getAs('proc', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}&grn={$grn->number}"))['rows']);

        // stock from before the receipts were kept in the system: the whole warehouse, searched
        $all = $this->expectOk($this->getAs('proc', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}&scope=all&q={$foreign->sku}"));
        $this->assertEquals(['all', [[$foreign->sku, 'A-07-1-B2', 15, 3, 'cost']]], [$all['scope'], array_map(fn ($r) => [$r['sku'], $r['bin'], $r['available'], $r['price'], $r['priceSource']], $all['rows'])]);

        // a held quantity is shown as not available
        $r = $this->expectOk($this->postAs('proc', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality',
            'lines' => [['sku' => $mine->sku, 'qty' => 15, 'fromBin' => 'A-07-1-B1', 'batchNo' => $batchNo]]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $row = $this->expectOk($this->getAs('proc', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}"))['rows'][0];
        $this->assertSame([40, 15, 25], [$row['onHand'], $row['reserved'], $row['available']]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/cancel", ['reason' => 'اختبار العرض']));

        // a bin holding two batches of the product: the line must say which one leaves
        $second = 'SR2-'.self::uid();
        $this->receipt($supplier, $mine, 10, 7.5, 'A-07-1-B1', $second);
        $twoBatches = ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality'];
        $this->expectRejected($this->postAs('proc', '/api/returns', $twoBatches + ['lines' => [['sku' => $mine->sku, 'qty' => 5, 'fromBin' => 'A-07-1-B1']]]), 'BATCH_REQUIRED', [400]);
        $this->expectRejected($this->postAs('proc', '/api/returns', $twoBatches + ['lines' => [['sku' => $mine->sku, 'qty' => 11, 'fromBin' => 'A-07-1-B1', 'batchNo' => $second]]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $picked = $this->expectOk($this->postAs('proc', '/api/returns', $twoBatches + ['lines' => [['sku' => $mine->sku, 'qty' => 10, 'fromBin' => 'A-07-1-B1', 'batchNo' => $second]]]));
        $this->assertSame($second, $this->expectOk($this->getAs('proc', "/api/returns/{$picked['number']}"))['lines'][0]['batchNo']);
        $this->assertCount(2, $this->expectOk($this->getAs('proc', "/api/returns/stock-rows?warehouse=RYD&supplier={$supplier->code}"))['rows'], 'one row per batch');
        $this->assertReconciles();
    }

    public function test_a_supplier_return_created_the_old_way_can_only_be_cancelled(): void
    {
        // a `sup` return without a source bin (data from before this release): approving it would create stock from nothing
        $supplier = $this->newSupplier();
        $product = $this->product('PRT-L');
        $legacy = ReturnOrder::create(['number' => self::code('RTN-L'), 'type' => 'sup', 'source_ar' => 'مورد', 'source_en' => 'Supplier', 'supplier_id' => $supplier->id, 'warehouse_id' => $this->ryd()->id,
            'status' => 'pending', 'reason_code' => 'quality', 'reason_ar' => 'مشكلة جودة', 'reason_en' => 'quality']);
        $legacy->lines()->create(['line_no' => 1, 'product_id' => $product->id, 'qty' => 5]);
        $url = "/api/returns/{$legacy->number}";
        $flow = $this->expectOk($this->getAs('wm', $url))['flow'];
        $this->assertSame(['FROM_BIN_REQUIRED', true], [$flow['blocked']['code'], $flow['cancel']['allowed']], 'the screen says why and offers the way out');
        $this->expectRejected($this->postAs('wm', "{$url}/approve"), 'FROM_BIN_REQUIRED', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/ship"), 'RTN_NOT_FROM_STOCK', [422]);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "{$url}/cancel", ['reason' => 'يُعاد إنشاؤه من المخزون']))['status']);
        $this->assertSame(0, $this->totalOnHand($product));
    }
}
