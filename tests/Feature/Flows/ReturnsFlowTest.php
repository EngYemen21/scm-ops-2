<?php

namespace Tests\Feature\Flows;

use App\Finance\Models\CustomerInvoice;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\IntegrationEvent;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\ProductSupplier;
use App\Models\ReturnDecision;
use App\Models\ReturnOrder;
use App\Services\Core\SettingsService;
use App\Services\Returns\ReturnsService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Returns of goods that arrive: pending → approved → received (what really arrived → RET-01) → inspect (per line) →
 * decision per line, a line may be split; every decision part is a stock movement. pending | approved → cancelled.
 * Returns taken from stock are in PurchaseReturnsTest.
 */
class ReturnsFlowTest extends ApiTestCase
{
    use FlowFixtures, ReturnsFixtures;

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
        $this->expectRejected($this->postAs('wm', "{$url}/ship"), 'RTN_NOT_FROM_STOCK', [422]); // shipping is for returns taken from stock
        $this->assertSame(0, $this->onHand($product, 'RET-01'), 'a return that was not received holds no stock');

        $this->expectRejected($this->postAs('driver', "{$url}/approve"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "{$url}/approve"), 'FORBIDDEN', [403]);
        $approved = $this->expectOk($this->postAs('wm', "{$url}/approve"));
        $this->assertSame('approved', $approved['status']);
        $this->assertNotNull($approved['approvedAt']);
        $this->expectRejected($this->postAs('wm', "{$url}/reject"), 'RTN_TRANSITION', [422]);

        $received = $this->expectOk($this->postAs('wm', "{$url}/receive"));  // no body: everything arrived
        $this->assertSame(['received', 'RET-01', 'wm'], [$received['status'], $received['receiving']['binCode'], $received['receiving']['receivedBy']]);
        $this->assertSame([['ret', 3, null, 'RET-01']], array_map(fn ($m) => [$m['type'], $m['qty'], $m['srcBin'], $m['dstBin']['code']], $received['movements']));
        $this->assertSame([3, 3], [$received['lines'][0]['receivedQty'], $received['lines'][0]['pendingQty']]);
        $this->assertSame(3, $this->onHand($product, 'RET-01'));
        $this->assertSame(50, $this->inv()->availability($product->id)['total'], 'stock in the returns area is not available');
        $this->expectRejected($this->postAs('wm', "{$url}/receive"), 'RTN_TRANSITION', [422]);
        $this->assertSame(3, $this->onHand($product, 'RET-01'), 'a return cannot be received twice');
        $this->expectRejected($this->postAs('wm', "{$url}/cancel", ['reason' => 'خطأ إدخال']), 'RTN_TRANSITION', [422]); // goods are in: decide, do not cancel

        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'qtn']), 'RTN_DECISION_STATE', [422]);
        $this->expectRejected($this->postAs('driver', "{$url}/inspect"), 'FORBIDDEN', [403]);
        $inspected = $this->expectOk($this->postAs('wm', "{$url}/inspect"));  // no body
        $this->assertSame(['inspect', null], [$inspected['status'], $inspected['inspection']['findings']]);
        $this->expectRejected($this->postAs('wm', "{$url}/inspect"), 'RTN_TRANSITION', [422]); // a second empty click changes nothing

        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['decision' => 'burn']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "{$url}/decide", ['decision' => 'qtn']), 'FORBIDDEN', [403]);
        $closed = $this->expectOk($this->postAs('wm', "{$url}/decide", ['decision' => 'qtn', 'note' => 'بانتظار الجودة']));
        $this->assertSame(['closed', 'qtn', 'wm'], [$closed['status'], $closed['decision'], $closed['decidedBy']]);
        $this->assertSame([null, 'pending', 'approved', 'received', 'inspect'], array_column($closed['history'], 'fromStatus'));
        $this->assertSame(['qtn', 3, 'بانتظار الجودة', 1], [$closed['decisions'][0]['decision'], $closed['decisions'][0]['qty'], $closed['decisions'][0]['note'], $closed['decisions'][0]['lineNo']]);
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
        $this->assertFalse($detail['lines'][0]['needsBatch'], 'the line already carries its batch');

        // a typed bin that fits NO line is a mistake, not something to ignore
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

    public function test_one_decision_with_a_bin_uses_it_only_for_the_lines_it_fits(): void
    {
        // the audit: one typed bin was checked against every line and the whole decision failed on the first product of another storage class
        $ambient = $this->product('RTN-MIX-A');
        $frozen = $this->product('RTN-MIX-F', storageClass: 'frozen');
        $r = $this->expectOk($this->postAs('sales', '/api/returns', ['type' => 'cust', 'source' => 'عميل مختلط', 'warehouseCode' => 'RYD', 'reasonCode' => 'wrong_product',
            'lines' => [['sku' => $ambient->sku, 'qty' => 5], ['sku' => $frozen->sku, 'qty' => 2]]]));
        foreach (['approve', 'receive', 'inspect'] as $step) {
            $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/{$step}"));
        }
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/decide", ['decision' => 'restock', 'binCode' => 'A-07-1-B2']));
        $this->assertSame(['closed', 'restock'], [$closed['status'], $closed['decision']]);
        $this->assertSame('A-07-1-B2', $closed['lines'][0]['bin']['code'], 'the typed bin fits the ambient line');
        $this->assertStringStartsWith('FZ-', $closed['lines'][1]['bin']['code'], 'the frozen line is placed automatically in its own zone');
        $this->assertSame([5, 2], [$this->inv()->availability($ambient->id)['total'], $this->inv()->availability($frozen->id)['total']]);
        $this->assertSame([1, 2], array_column($closed['decisions'], 'lineNo'));
        $this->assertReconciles();
    }

    public function test_partial_receipt_inspection_per_line_and_a_split_decision(): void
    {
        $a = $this->product('RTN-PL-A');
        $b = $this->product('RTN-PL-B');
        $r = $this->expectOk($this->postAs('sales', '/api/returns', ['type' => 'cust', 'source' => 'عميل جزئي', 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged',
            'lines' => [['sku' => $a->sku, 'qty' => 10], ['sku' => $b->sku, 'qty' => 4]]]));
        $url = "/api/returns/{$r['number']}";
        $this->expectOk($this->postAs('wm', "{$url}/approve"));

        // ── receipt: only what arrived enters RET-01
        $this->expectRejected($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 11]]]), 'BAD_QTY', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 9, 'receivedQty' => 1]]]), 'LINE_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 1], ['lineNo' => 1, 'receivedQty' => 2]]]), 'LINE_DUPLICATE', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 0], ['lineNo' => 2, 'receivedQty' => 0]]]), 'NOTHING_RECEIVED', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => -1]]]), 'INVALID_INPUT', [400]);
        $this->assertSame(['approved', 0], [ReturnOrder::where('number', $r['number'])->value('status'), $this->totalOnHand($a)], 'a rejected receipt changes nothing');

        $received = $this->expectOk($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 7], ['lineNo' => 2, 'receivedQty' => 0]]]));
        $this->assertSame('received', $received['status']);
        $this->assertSame([[10, 7, 7], [4, 0, 0]], array_map(fn ($l) => [$l['qty'], $l['receivedQty'], $l['pendingQty']], $received['lines']));
        $this->assertSame([['ret', 7, 'RET-01']], array_map(fn ($m) => [$m['type'], $m['qty'], $m['dstBin']['code']], $received['movements']), 'one movement, for the quantity that arrived');
        $this->assertSame([7, 0], [$this->onHand($a, 'RET-01'), $this->totalOnHand($b)]);
        $this->assertStringContainsString('نقص', (string) $received['receiving']['notes']);
        $exception = OpsException::where('entity_number', $r['number'])->where('kind', 'shortage')->firstOrFail();
        $this->assertSame(['wm', 'open'], [$exception->owner_role, $exception->status], 'the shortfall is somebody\'s to explain');

        // ── inspection: quantity, condition and note per line are stored
        $this->expectRejected($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 1, 'inspectedQty' => 8, 'condition' => 'good']]]), 'BAD_QTY', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 1, 'inspectedQty' => 7, 'condition' => 'weird']]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 5, 'inspectedQty' => 1, 'condition' => 'good']]]), 'LINE_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 1, 'inspectedQty' => 1, 'condition' => 'good'], ['lineNo' => 1, 'inspectedQty' => 2, 'condition' => 'good']]]), 'LINE_DUPLICATE', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 2, 'inspectedQty' => 1, 'condition' => 'good']]]), 'BAD_QTY', [400]); // nothing of line 2 arrived
        $this->assertSame('received', ReturnOrder::where('number', $r['number'])->value('status'));
        $inspected = $this->expectOk($this->postAs('wm', "{$url}/inspect", ['findings' => 'كرتونتان مبللتان', 'lines' => [['lineNo' => 1, 'inspectedQty' => 6, 'condition' => 'damaged', 'note' => 'تلف في التغليف']]]));
        $this->assertSame(['inspect', 'كرتونتان مبللتان'], [$inspected['status'], $inspected['inspection']['findings']]);
        $this->assertSame([6, 'damaged', 'تلف في التغليف'], [$inspected['lines'][0]['inspectedQty'], $inspected['lines'][0]['condition'], $inspected['lines'][0]['inspectNote']]);
        // the inspector corrects the record while the return is still in inspection
        $fixed = $this->expectOk($this->postAs('wm', "{$url}/inspect", ['lines' => [['lineNo' => 1, 'inspectedQty' => 7, 'condition' => 'opened']]]));
        $this->assertSame(['inspect', 7, 'opened', 'كرتونتان مبللتان'], [$fixed['status'], $fixed['lines'][0]['inspectedQty'], $fixed['lines'][0]['condition'], $fixed['inspection']['findings']]);
        $this->assertSame(1, collect($fixed['history'])->where('toStatus', 'inspect')->count(), 'a correction is not a second transition');

        // ── decision per line, the line split over three decisions
        $split = fn (array $parts, array $line2 = []) => ['lines' => [['lineNo' => 1, 'parts' => $parts], ['lineNo' => 2, 'parts' => $line2]]];
        $this->expectRejected($this->postAs('wm', "{$url}/decide", $split([['qty' => 4, 'decision' => 'restock'], ['qty' => 2, 'decision' => 'qtn']])), 'PARTS_SUM', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", $split([['qty' => 7, 'decision' => 'restock']], [['qty' => 1, 'decision' => 'qtn']])), 'PARTS_SUM', [422]); // nothing of line 2 arrived
        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['lines' => [['lineNo' => 2, 'parts' => []]]]), 'LINE_MISSING', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['lines' => []]), 'INVALID_INPUT', [400]); // neither one decision nor a decision per line
        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['lines' => [['lineNo' => 1, 'parts' => [['qty' => 7, 'decision' => 'restock']]], ['lineNo' => 7, 'parts' => []]]]), 'LINE_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", ['lines' => [['lineNo' => 1, 'parts' => [['qty' => 3, 'decision' => 'restock']]], ['lineNo' => 1, 'parts' => [['qty' => 4, 'decision' => 'qtn']]]]]), 'LINE_DUPLICATE', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", $split([['qty' => 5, 'decision' => 'restock'], ['qty' => 2, 'decision' => 'qtn', 'binCode' => 'A-07-1-B1']])), 'BIN_NOT_APPLICABLE', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", $split([['qty' => 7, 'decision' => 'restock', 'binCode' => 'FZ-01-1-B1']])), 'WRONG_LOCATION', [422]);
        $this->expectRejected($this->postAs('wm', "{$url}/decide", $split([['qty' => 0, 'decision' => 'restock'], ['qty' => 7, 'decision' => 'qtn']])), 'INVALID_INPUT', [400]);
        $this->assertSame(['inspect', 7], [ReturnOrder::where('number', $r['number'])->value('status'), $this->onHand($a, 'RET-01')], 'a rejected decision is rolled back as a whole');

        $closed = $this->expectOk($this->postAs('wm', "{$url}/decide", $split([
            ['qty' => 4, 'decision' => 'restock', 'binCode' => 'A-07-1-B1', 'note' => 'سليم'], ['qty' => 2, 'decision' => 'qtn'], ['qty' => 1, 'decision' => 'dispose', 'note' => 'مكسور'],
        ])));
        $this->assertSame(['closed', 'mixed'], [$closed['status'], $closed['decision']]);
        $this->assertSame(['mixed', null], [$closed['lines'][0]['decision'], $closed['lines'][1]['decision']], 'a line that did not arrive has no decision');
        $this->assertSame([['restock', 4, 1, 'A-07-1-B1', 'سليم'], ['qtn', 2, 1, 'QTN-01', null], ['dispose', 1, 1, null, 'مكسور']],
            array_map(fn ($d) => [$d['decision'], $d['qty'], $d['lineNo'], $d['binCode'], $d['note']], $closed['decisions']));
        $this->assertSame(['opened', 'opened', 'opened'], array_column($closed['decisions'], 'condition'), 'the decision keeps what the inspector found');
        $this->assertSame([['ret', 7], ['restock', 4], ['qtn', 2], ['scrap', 1]], array_map(fn ($m) => [$m['type'], $m['qty']], $closed['movements']), 'one movement per part');
        $this->assertSame(3, ReturnDecision::where('return_id', $closed['id'])->whereNotNull('line_id')->whereNotNull('movement_id')->count());
        $this->assertSame([0, 4, 2, 6], [$this->onHand($a, 'RET-01'), $this->onHand($a, 'A-07-1-B1'), $this->onHand($a, 'QTN-01'), $this->totalOnHand($a)]);
        $this->assertSame(4, $this->inv()->availability($a->id)['total']);
        $this->assertSame(1, AuditLog::where('action', 'RTN.DECIDE')->where('entity_number', $r['number'])->where('new_value', 'mixed')->count());
        $this->assertReconciles();
    }

    public function test_a_tracked_product_is_restocked_only_with_its_batch(): void
    {
        $product = $this->product('RTN-BAT', tracksExpiry: true);
        $base = ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'wrong_qty'];

        // a batch number that does not exist for the product is refused at creation instead of being stored as text
        $rejected = $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['lines' => [['sku' => $product->sku, 'qty' => 2, 'batchNo' => 'NOPE-'.self::uid()]]]), 'BATCH_NOT_FOUND', [400]);
        $this->assertSame('lines.0.batchNo', $rejected['details']['field']);

        $number = $this->returnInInspection($product, 6);
        $detail = $this->expectOk($this->getAs('wm', "/api/returns/{$number}"));
        $this->assertSame([null, true, true], [$detail['lines'][0]['batchId'], $detail['lines'][0]['needsBatch'], $detail['flow']['requireBatchOnRestock']]);
        $url = "/api/returns/{$number}/decide";
        $parts = fn (array $parts) => ['lines' => [['lineNo' => 1, 'parts' => $parts]]];

        $this->expectRejected($this->postAs('wm', $url, ['decision' => 'restock']), 'BATCH_REQUIRED', [400]);
        $this->expectRejected($this->postAs('wm', $url, $parts([['qty' => 6, 'decision' => 'restock']])), 'BATCH_REQUIRED', [400]);
        $newBatch = 'RB-'.self::uid();
        $rejected = $this->expectRejected($this->postAs('wm', $url, $parts([['qty' => 6, 'decision' => 'restock', 'batchNo' => $newBatch]])), 'EXPIRY_REQUIRED', [400]);
        $this->assertSame('lines.0.parts.0.expiryDate', $rejected['details']['field']);
        $this->assertSame([6, 0], [$this->onHand($product, 'RET-01'), Batch::where('batch_no', $newBatch)->count()], 'nothing moved and no batch was created by a rejected decision');

        // an existing batch needs no date; a new one is created with the date given; quarantine needs no batch
        $existing = Batch::create(['product_id' => $product->id, 'batch_no' => 'EX-'.self::uid(), 'expiry_date' => now()->addMonths(3)]);
        $closed = $this->expectOk($this->postAs('wm', $url, $parts([
            ['qty' => 3, 'decision' => 'restock', 'binCode' => 'A-07-1-B2', 'batchNo' => $newBatch, 'expiryDate' => '2027-08-31'],
            ['qty' => 2, 'decision' => 'restock', 'binCode' => 'A-07-1-B2', 'batchNo' => $existing->batch_no],
            ['qty' => 1, 'decision' => 'qtn'],
        ])));
        $this->assertSame(['closed', 'mixed'], [$closed['status'], $closed['decision']]);
        $created = Batch::where('product_id', $product->id)->where('batch_no', $newBatch)->firstOrFail();
        $this->assertSame('2027-08-31', $created->expiry_date->toDateString());
        $rows = InventoryBalance::where('product_id', $product->id)->where('bin_id', $this->inv()->bin($this->ryd()->id, 'A-07-1-B2')->id)->pluck('on_hand', 'batch_id');
        $this->assertEquals([$created->id => 3, $existing->id => 2], $rows->all(), 'every restocked unit sits under its batch — nothing batchless on the shelf');
        $this->assertSame([[$newBatch, 3], [$existing->batch_no, 2], [null, 1]], array_map(fn ($d) => [$d['batchNo'], $d['qty']], $closed['decisions']));
        // the quantity was received batchless: it is re-identified inside RET-01 (two internal moves), then restocked under the batch
        $this->assertSame(['ret', 'move', 'move', 'restock', 'move', 'move', 'restock', 'qtn'], array_column($closed['movements'], 'type'));
        $this->assertSame(0, $this->onHand($product, 'RET-01'));
        $this->assertSame(5, $this->inv()->availability($product->id)['total']);
        $this->assertReconciles();

        // the policy can be switched off: batchless restock is then allowed as before
        $settings = $this->app->make(SettingsService::class);
        $settings->set('returns.requireBatchOnRestock', false);
        try {
            $loose = $this->returnInInspection($product, 1);
            $this->assertFalse($this->expectOk($this->getAs('wm', "/api/returns/{$loose}"))['lines'][0]['needsBatch']);
            $this->assertSame('closed', $this->expectOk($this->postAs('wm', "/api/returns/{$loose}/decide", ['decision' => 'restock']))['status']);
        } finally {
            $settings->set('returns.requireBatchOnRestock', true);
        }
        $this->assertReconciles();
    }

    public function test_a_delivery_return_takes_the_batch_the_order_was_loaded_from(): void
    {
        $product = $this->product('RTN-LOAD', tracksExpiry: true);
        $batch = Batch::create(['product_id' => $product->id, 'batch_no' => 'LD-'.self::uid(), 'expiry_date' => now()->addYear()]);
        $this->stockAt($product, 'A-07-1-B1', 12, 5.0, $batch);
        [$trip, , $fos] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 12]]]);
        $wh = $this->ryd();
        DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'load', 'productId' => $product->id, 'batchId' => $batch->id, 'qty' => 12,
            'from' => ['warehouseId' => $wh->id, 'binId' => $this->inv()->bin($wh->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fos[0]->id, 'number' => $fos[0]->number]]));

        // the delivery flow hands over {sku, qty} — the batch is the one batch the order left with
        $r = $this->deliveryReturn($fos[0], $trip, [['sku' => $product->sku, 'qty' => 5]], 'cust', 'partial');
        $this->assertSame(['approved', $batch->id, $batch->batch_no], [$r->status, $r->lines[0]->batch_id, $r->lines[0]->batch_no]);
        $url = "/api/returns/{$r->number}";
        $this->assertSame('undelivered', $this->expectOk($this->getAs('wm', $url))['flow']['credit'], 'goods that never reached the customer were never invoiced');
        $this->expectOk($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 4]]]));
        // finance: only what came back leaves goods in transit, at the cost it was loaded at
        $this->assertEqualsWithDelta(20.00, $this->gl('inventory', $r->number), 0.001);
        $this->assertEqualsWithDelta(-20.00, $this->gl('goods_in_transit', $r->number), 0.001);
        $this->assertSame('disp', OpsException::where('entity_number', $r->number)->where('kind', 'shortage')->value('owner_role'));
        $this->expectOk($this->postAs('wm', "{$url}/inspect"));
        $closed = $this->expectOk($this->postAs('wm', "{$url}/decide", ['decision' => 'restock']));  // no BATCH_REQUIRED: the line knows its batch
        $this->assertSame('closed', $closed['status']);
        $this->assertSame($batch->id, InventoryMovement::where('reference_number', $r->number)->where('type', 'restock')->value('batch_id'));
        $this->assertSame(4, $this->inv()->availability($product->id)['total']);
        $this->assertReconciles();

        // an order loaded from TWO batches: what comes back is taken from the last loaded batch first — one return line per batch
        $two = $this->product('RTN-LOAD2', tracksExpiry: true);
        $first = Batch::create(['product_id' => $two->id, 'batch_no' => 'L1-'.self::uid(), 'expiry_date' => now()->addMonths(2)]);
        $last = Batch::create(['product_id' => $two->id, 'batch_no' => 'L2-'.self::uid(), 'expiry_date' => now()->addMonths(8)]);
        $this->stockAt($two, 'A-07-1-B1', 8, 5.0, $first);
        $this->stockAt($two, 'A-07-1-B1', 4, 5.0, $last);
        [$trip2, , $fos2] = $this->dispatchedTrip($this->demoDriver(), [[[$two, 12]]]);
        foreach ([[$first, 8], [$last, 4]] as [$b, $qty]) {
            DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'load', 'productId' => $two->id, 'batchId' => $b->id, 'qty' => $qty,
                'from' => ['warehouseId' => $wh->id, 'binId' => $this->inv()->bin($wh->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fos2[0]->id, 'number' => $fos2[0]->number]]));
        }
        $back = $this->deliveryReturn($fos2[0], $trip2, [['sku' => $two->sku, 'qty' => 6]], 'del', 'del_fail');
        $this->assertSame([[1, $last->id, 4], [2, $first->id, 2]], $back->lines()->orderBy('line_no')->get()->map(fn ($l) => [$l->line_no, $l->batch_id, $l->qty])->all());
        foreach (['receive', 'inspect'] as $step) {
            $this->expectOk($this->postAs('wm', "/api/returns/{$back->number}/{$step}"));
        }
        $this->assertSame('closed', $this->expectOk($this->postAs('wm', "/api/returns/{$back->number}/decide", ['decision' => 'restock']))['status']);
        $this->assertEquals([$last->id => 4, $first->id => 2], InventoryMovement::where('reference_number', $back->number)->where('type', 'restock')->pluck('qty', 'batch_id')->all());
        // a return that names the delivery later (no trip: the goods are not on a truck) and not the batch: with two
        // delivered batches nobody guesses — the line stays batchless and the inspector says which
        $later = $this->app->make(ReturnsService::class)->create($this->actor('sales'), ['type' => 'del', 'source' => 'عميل', 'foNumber' => $fos2[0]->number,
            'warehouseCode' => 'RYD', 'reasonCode' => 'other', 'lines' => [['sku' => $two->sku, 'qty' => 3]]]);
        $this->assertSame([[null, 3]], $later->lines->map(fn ($l) => [$l->batch_id, $l->qty])->all());
        $this->assertReconciles();
    }

    public function test_cancel_needs_a_reason_and_a_delivery_return_waits_for_its_trip(): void
    {
        $product = $this->product('RTN-CAN');
        $base = ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'other', 'lines' => [['sku' => $product->sku, 'qty' => 2]]];

        // pending → cancelled
        $r = $this->expectOk($this->postAs('sales', '/api/returns', $base));
        $url = "/api/returns/{$r['number']}";
        $this->assertTrue($this->expectOk($this->getAs('sales', $url))['flow']['cancel']['allowed']);
        $this->expectRejected($this->postAs('sales', "{$url}/cancel", ['reason' => 'طلب مكرر']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$url}/cancel"), 'REASON_REQUIRED', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/cancel", ['reason' => ' ']), 'REASON_REQUIRED', [400]);
        $this->assertSame('pending', ReturnOrder::where('number', $r['number'])->value('status'));
        $cancelled = $this->expectOk($this->postAs('wm', "{$url}/cancel", ['reason' => 'طلب مكرر']));
        $this->assertSame(['cancelled', 'wm', 'طلب مكرر'], [$cancelled['status'], $cancelled['cancelledBy'], $cancelled['cancelReason']]);
        $this->assertNotNull($cancelled['cancelledAt']);
        $this->assertSame('طلب مكرر', collect($cancelled['history'])->last()['note']);
        $this->assertFalse($cancelled['flow']['cancel']['allowed']);
        foreach (['approve', 'receive', 'cancel'] as $step) {
            $this->expectRejected($this->postAs('wm', "{$url}/{$step}", ['reason' => 'مرة أخرى']), 'RTN_TRANSITION', [422]);
        }
        $this->assertSame(1, AuditLog::where('action', 'RTN.CANCEL')->where('entity_number', $r['number'])->count());

        // approved → cancelled
        $approved = $this->expectOk($this->postAs('sales', '/api/returns', $base));
        $this->expectOk($this->postAs('wm', "/api/returns/{$approved['number']}/approve"));
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "/api/returns/{$approved['number']}/cancel", ['reason' => 'العميل تراجع']))['status']);
        $this->assertSame(0, $this->totalOnHand($product), 'nothing was ever received');

        // a return created by a delivery: the goods are on the truck until the trip is closed
        [$trip, , $fos] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 5]]]);
        $onTruck = $this->deliveryReturn($fos[0], $trip, [['sku' => $product->sku, 'qty' => 5]]);
        $url = "/api/returns/{$onTruck->number}";
        $flow = $this->expectOk($this->getAs('wm', $url))['flow'];
        $this->assertSame([false, 'RTN_ON_TRUCK'], [$flow['cancel']['allowed'], $flow['cancel']['code']]);
        $rejected = $this->expectRejected($this->postAs('wm', "{$url}/cancel", ['reason' => 'لم يعد شيء']), 'RTN_ON_TRUCK', [422]);
        $this->assertSame($trip->number, $rejected['details']['trip']);
        $this->assertSame('approved', $onTruck->refresh()->status);

        $trip->update(['status' => 'closed', 'closed_at' => now()]);
        $this->assertTrue($this->expectOk($this->getAs('wm', $url))['flow']['cancel']['allowed']);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "{$url}/cancel", ['reason' => 'لم يعد شيء مع الرحلة']))['status']);
        $exception = OpsException::where('entity_number', $onTruck->number)->where('kind', 'shortage')->firstOrFail();
        $this->assertSame(['disp', $trip->number], [$exception->owner_role, $exception->document_number], 'goods that never came back are raised to the dispatcher');
        $this->assertReconciles();
    }

    public function test_damaged_scrap_and_supplier_decisions_move_stock_out_of_the_returns_area(): void
    {
        // damaged → DMG-01 (on hand, never available)
        $dmg = $this->product('RTN-DMG');
        $closed = $this->expectOk($this->postAs('wm', '/api/returns/'.$this->returnInInspection($dmg, 5, reason: 'damaged').'/decide', ['decision' => 'dmg']));
        $this->assertSame(['dmg', 'RET-01', 'DMG-01'], [$closed['movements'][1]['type'], $closed['movements'][1]['srcBin']['code'], $closed['movements'][1]['dstBin']['code']]);
        $this->assertSame([0, 5, 0], [$this->onHand($dmg, 'RET-01'), $this->onHand($dmg, 'DMG-01'), $this->inv()->availability($dmg->id)['total']]);

        // scrap → leaves the warehouse
        $scrap = $this->product('RTN-SCR');
        $closed = $this->expectOk($this->postAs('wm', '/api/returns/'.$this->returnInInspection($scrap, 6, reason: 'expired').'/decide', ['decision' => 'dispose']));
        $this->assertSame(['scrap', 'RET-01', null], [$closed['movements'][1]['type'], $closed['movements'][1]['srcBin']['code'], $closed['movements'][1]['dstBin']]);
        $this->assertSame(0, $this->totalOnHand($scrap));

        // return to supplier from a customer return: the supplier is chosen ON THE DECISION
        $sup = $this->product('RTN-SUP');
        $this->stockAt($sup, 'A-07-1-B1', 10, 8.0); // gives the product a cost, so the movement is valued
        $unknown = $this->returnInInspection($sup, 7);
        $this->assertNull($this->expectOk($this->getAs('wm', "/api/returns/{$unknown}"))['lines'][0]['suggestedSupplier'], 'no receipt, no preferred supplier: nothing to suggest');
        $rejected = $this->expectRejected($this->postAs('wm', "/api/returns/{$unknown}/decide", ['decision' => 'sup']), 'SUPPLIER_REQUIRED', [400]);
        $this->assertSame('supplierCode', $rejected['details']['field'], 'the error names the field to fill');
        $rejected = $this->expectRejected($this->postAs('wm', "/api/returns/{$unknown}/decide", ['lines' => [['lineNo' => 1, 'parts' => [['qty' => 7, 'decision' => 'sup']]]]]), 'SUPPLIER_REQUIRED', [400]);
        $this->assertSame('lines.0.parts.0.supplierCode', $rejected['details']['field']);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$unknown}/decide", ['lines' => [['lineNo' => 1, 'parts' => [['qty' => 7, 'decision' => 'sup', 'supplierCode' => 'SUP-NOPE']]]]]), 'SUPPLIER_NOT_FOUND', [404]);
        $this->assertSame(7, $this->onHand($sup, 'RET-01'), 'the rejected decision is rolled back as a whole');

        $supplier = $this->newSupplier();
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$unknown}/decide", ['lines' => [['lineNo' => 1, 'parts' => [
            ['qty' => 4, 'decision' => 'sup', 'supplierCode' => $supplier->code], ['qty' => 3, 'decision' => 'restock'],
        ]]]]));
        $this->assertSame(['closed', 'mixed'], [$closed['status'], $closed['decision']]);
        $this->assertSame([['supret', null], ['restock', 'A-07-1-B1']], array_map(fn ($m) => [$m['type'], $m['dstBin']['code'] ?? null], array_slice($closed['movements'], 1)));
        $this->assertSame($supplier->code, $closed['decisions'][0]['supplier']['code']);
        $this->assertSame(13, $this->totalOnHand($sup), '10 on the shelf + 3 restocked; 4 went back to the supplier');
        $this->assertSame(1, IntegrationEvent::where('type', 'SupplierReturnCreated')->where('payload->rtn', $unknown)->count());
        // the claim is a document: 4 × 8 (no purchase order → cost) + 15 % VAT, and the payable went down by exactly that
        $this->assertCount(1, $closed['debitNotes']);
        $this->assertSame(['issued', '32.00', '4.80', '36.80', '32.00'], [$closed['debitNotes'][0]['status'], $closed['debitNotes'][0]['subtotal'], $closed['debitNotes'][0]['vatAmount'], $closed['debitNotes'][0]['total'], $closed['debitNotes'][0]['costTotal']]);
        $this->assertEqualsWithDelta(36.80, $this->payableDebit($supplier), 0.001);

        // the default supplier: who delivered the batch, else the product's preferred supplier
        $preferred = $this->newSupplier();
        ProductSupplier::create(['product_id' => $sup->id, 'supplier_id' => $preferred->id, 'preferred' => true]);
        $byPreference = $this->returnInInspection($sup, 2);
        $this->assertSame($preferred->code, $this->expectOk($this->getAs('wm', "/api/returns/{$byPreference}"))['lines'][0]['suggestedSupplier']['code']);
        $this->assertSame($preferred->code, $this->expectOk($this->postAs('wm', "/api/returns/{$byPreference}/decide", ['decision' => 'sup']))['decisions'][0]['supplier']['code']);

        $tracked = $this->product('RTN-SUPB', tracksExpiry: true);
        $receiver = $this->newSupplier();
        [, , $batch] = $this->receipt($receiver, $tracked, 20, 9.0, 'A-07-1-B2', 'SB-'.self::uid());
        $byBatch = $this->returnInInspection($tracked, 3, ['batchNo' => $batch->batch_no]);
        $this->assertSame($receiver->code, $this->expectOk($this->getAs('wm', "/api/returns/{$byBatch}"))['lines'][0]['suggestedSupplier']['code']);
        $done = $this->expectOk($this->postAs('wm', "/api/returns/{$byBatch}/decide", ['decision' => 'sup']));
        $this->assertSame([$receiver->code, '27.00'], [$done['decisions'][0]['supplier']['code'], $done['debitNotes'][0]['subtotal']], 'claimed at the price of the supplier\'s last purchase order');

        // a supplier / damaged return with no source bin is refused: it would create stock from nothing in RET-01
        $this->expectRejected($this->postAs('wm', '/api/returns', ['type' => 'sup', 'source' => 'مورد', 'warehouseCode' => 'RYD', 'reasonCode' => 'quality', 'lines' => [['sku' => $sup->sku, 'qty' => 1]]]), 'SUPPLIER_REQUIRED', [400]);
        $this->expectRejected($this->postAs('wm', '/api/returns', ['type' => 'sup', 'source' => 'مورد', 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality', 'lines' => [['sku' => $sup->sku, 'qty' => 1]]]), 'FROM_BIN_REQUIRED', [422]);
        $this->expectRejected($this->postAs('wm', '/api/returns', ['type' => 'dmg', 'source' => 'المستودع', 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged', 'lines' => [['sku' => $sup->sku, 'qty' => 1]]]), 'FROM_BIN_REQUIRED', [422]);
        $this->expectRejected($this->postAs('wm', '/api/returns', ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'damaged', 'lines' => [['sku' => $sup->sku, 'qty' => 1, 'fromBin' => 'A-07-1-B1']]]), 'FROM_BIN_NOT_ALLOWED', [400]);
        $this->assertReconciles();
    }

    public function test_a_customer_return_that_names_its_delivery_gets_a_credit_note(): void
    {
        // the audit: the credit note was unreachable — a manual return could not name its delivery, and delivery returns never qualify
        $product = $this->product('RTN-CN');
        [$fo, $invoice] = $this->invoicedDelivery($product, 10, 6.0);   // invoice 10 × 10 = 100 + 15 VAT; cost of sales 60
        $this->assertSame(['issued', '115.00'], [$invoice->status, (string) $invoice->total]);
        $other = $this->product('RTN-CN-X');
        $base = ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'cust_reject'];
        $line = fn (int $qty, ?string $sku = null) => ['lines' => [['sku' => $sku ?? $product->sku, 'qty' => $qty]]];

        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => 'CI-NOPE'] + $line(1)), 'INVOICE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['foNumber' => 'FO-NOPE'] + $line(1)), 'FO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number] + $line(11)), 'RETURN_EXCEEDS_INVOICED', [422]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number] + $line(1, $other->sku)), 'NOT_ON_INVOICE', [422]);
        [, , $notInvoiced] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 3]]]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['foNumber' => $notInvoiced[0]->number] + $line(1)), 'FO_NOT_INVOICED', [422]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number, 'foNumber' => $notInvoiced[0]->number] + $line(1)), 'REFERENCE_MISMATCH', [422]);
        $stranger = Customer::where('id', '!=', $invoice->customer_id)->firstOrFail();
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number, 'customerCode' => $stranger->code] + $line(1)), 'CUSTOMER_MISMATCH', [422]);
        // a cancelled invoice cannot be returned on, and its delivery no longer counts as invoiced
        [$voidedFo, $voided] = $this->invoicedDelivery($other, 2, 3.0);
        $this->expectOk($this->postAs('finance', "/api/finance/invoices/{$voided->number}/cancel", ['reason' => 'فاتورة صدرت بالخطأ']));
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $voided->number] + $line(1, $other->sku)), 'INVOICE_CANCELLED', [422]);
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['foNumber' => $voidedFo->number] + $line(1, $other->sku)), 'FO_NOT_INVOICED', [422]);
        $this->assertSame(0, ReturnOrder::where('fo_id', $fo->id)->count(), 'a rejected request creates nothing');

        $r = $this->expectOk($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number] + $line(4)));
        $this->assertSame(['post_delivery', $fo->id, $invoice->customer_id, $invoice->number], [$r['sourceMode'], $r['foId'], $r['customerId'], $r['reference']]);
        $url = "/api/returns/{$r['number']}";
        $detail = $this->expectOk($this->getAs('sales', $url));
        $this->assertSame(['expected', $fo->number, null], [$detail['flow']['credit'], $detail['fo']['number'], $detail['creditNote']]);
        // the 4 units of this open return already count against the invoice: a second return cannot ask for them again
        $rejected = $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['invoiceNumber' => $invoice->number] + $line(7)), 'RETURN_EXCEEDS_INVOICED', [422]);
        $this->assertSame([10, 6, 4], [$rejected['details']['invoiced'], $rejected['details']['remaining'], $rejected['details']['pending']]);

        $this->expectOk($this->postAs('wm', "{$url}/approve"));
        $received = $this->expectOk($this->postAs('wm', "{$url}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 3]]]));  // one unit never came back
        // credit note for what was RECEIVED: 3 × 10 = 30 + 4.50 VAT, applied to the invoice
        $this->assertSame(['34.50', 'paid', $invoice->number], [$received['creditNote']['total'], $received['creditNote']['status'], $received['creditNote']['invoice']]);
        $cn = $received['creditNote']['number'];
        $this->assertStringStartsWith('CN-', $cn);
        $this->assertEqualsWithDelta(30.00, $this->gl('sales_returns', $cn), 0.001);
        $this->assertEqualsWithDelta(4.50, $this->gl('vat_output', $cn), 0.001);
        $this->assertEqualsWithDelta(-34.50, $this->gl('ar', $cn), 0.001);
        $this->assertSame(['partially_paid', '34.50'], [$invoice->refresh()->status, (string) $invoice->paid_amount]);
        // the goods are back in stock at the cost they were sold at: cost of sales reversed (3 × 6)
        $this->assertEqualsWithDelta(18.00, $this->gl('inventory', $r['number']), 0.001);
        $this->assertEqualsWithDelta(-18.00, $this->gl('cogs', $r['number']), 0.001);
        $this->assertSame(3, $this->onHand($product, 'RET-01'));
        $this->assertSame(1, CustomerInvoice::where('return_id', $r['id'])->count());

        // what was already credited cannot be returned again
        $this->expectRejected($this->postAs('sales', '/api/returns', $base + ['foNumber' => $fo->number] + $line(8)), 'RETURN_EXCEEDS_INVOICED', [422]);
        $this->expectOk($this->postAs('wm', "{$url}/inspect"));
        $this->assertSame('closed', $this->expectOk($this->postAs('wm', "{$url}/decide", ['decision' => 'restock']))['status']);

        // without a reference: received at average cost against inventory adjustment, and the return says no credit note will be issued
        $plain = $this->expectOk($this->postAs('sales', '/api/returns', $base + $line(2)));
        $this->assertSame([null, null, 'none'], [$plain['sourceMode'], $plain['foId'], $this->expectOk($this->getAs('sales', "/api/returns/{$plain['number']}"))['flow']['credit']]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$plain['number']}/approve"));
        $this->assertNull($this->expectOk($this->postAs('wm', "/api/returns/{$plain['number']}/receive"))['creditNote']);
        $this->assertEqualsWithDelta(12.00, $this->gl('inventory', $plain['number']), 0.001);
        $this->assertEqualsWithDelta(-12.00, $this->gl('inventory_adjustment', $plain['number']), 0.001);
        $this->assertSame(0, CustomerInvoice::where('return_id', $plain['id'])->count());
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
        $this->assertSame([1, $r['number'], $product->sku, []], [$list['total'], $list['items'][0]['number'], $list['items'][0]['lines'][0]['product']['sku'], $list['items'][0]['debitNotes']]);
        $this->assertSame(0, $this->expectOk($this->getAs('inv', '/api/returns?status=closed&q='.$r['number']))['total']);
        $this->expectRejected($this->getAs('inv', '/api/returns/RTN-NOPE'), 'RTN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/returns/RTN-NOPE/approve'), 'RTN_NOT_FOUND', [404]);
    }
}
