<?php

namespace Tests\Feature\Finance;

use App\Finance\Models\DebitNote;
use App\Finance\Models\Journal;
use App\Finance\Services\DebitNoteService;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ReturnOrder;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;
use Tests\Feature\Flows\FlowFixtures;
use Tests\Feature\Flows\ReturnsFixtures;

/**
 * Supplier debit notes: the financial document of a purchase return. Issued by the returns flow, read and cancelled by
 * finance; cancelling reverses the journal and a corrected note can be issued again from the return.
 */
class DebitNoteTest extends ApiTestCase
{
    use FlowFixtures, ReturnsFixtures;

    public function test_finance_reads_the_note_and_it_is_on_the_supplier_statement(): void
    {
        [$supplier, , $rtn, $dn] = $this->shippedPurchaseReturn();   // 10 × 20 = 200 + 15 % = 230; cost 200

        $this->expectRejected($this->getAs('proc', '/api/finance/debit-notes'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('proc', "/api/finance/debit-notes/{$dn}"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('finance', '/api/finance/debit-notes/DN-NOPE'), 'DN_NOT_FOUND', [404]);

        $list = $this->expectOk($this->getAs('finance', "/api/finance/debit-notes?supplier={$supplier->code}"));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame([1, $dn, 'issued', '200.00', '30.00', '230.00', '200.00', $supplier->code, $rtn],
            [$list['total'], $list['items'][0]['number'], $list['items'][0]['status'], $list['items'][0]['subtotal'], $list['items'][0]['vatAmount'], $list['items'][0]['total'], $list['items'][0]['costTotal'],
                $list['items'][0]['supplier']['code'], $list['items'][0]['return']['number']]);
        $this->assertSame(1, $this->expectOk($this->getAs('finance', "/api/finance/debit-notes?q={$dn}&status=issued&return={$rtn}"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('finance', "/api/finance/debit-notes?supplier={$supplier->code}&status=cancelled"))['total']);

        $note = $this->expectOk($this->getAs('finance', "/api/finance/debit-notes/{$dn}"));
        $this->assertSame([$dn, 'closed', 15], [$note['number'], $note['return']['status'], (int) $note['vatPct']]);
        $this->assertNotNull($note['po']['number']);
        $this->assertCount(1, $note['journals']);
        $this->assertSame([$note['journal']['number'], 'posted'], [$note['journals'][0]['number'], $note['journals'][0]['status']]);
        // the stock movement already debited the payable at cost (200); the note's journal is only the VAT — price = cost here
        $this->assertEqualsWithDelta(30.00, $this->gl('ap', $dn), 0.001);
        $this->assertEqualsWithDelta(-30.00, $this->gl('vat_input', $dn), 0.001);
        $this->assertEqualsWithDelta(0.00, $this->gl('ppv', $dn), 0.001);
        $this->assertEqualsWithDelta(230.00, $this->payableDebit($supplier), 0.001);

        // supplier statement: both the goods leaving (return) and the note, and the supplier now owes us the note total
        $statement = $this->expectOk($this->getAs('finance', "/api/finance/reports/statement/supplier/{$supplier->code}?from=2026-01-01&to=2027-12-31"));
        $this->assertEqualsCanonicalizing([$rtn, $dn], array_column($statement['rows'], 'sourceNumber'));
        $this->assertEqualsWithDelta(-230.00, $statement['closing'], 0.001);

        // issuing again is a no-op: one note per return and supplier
        $again = DB::transaction(fn () => $this->app->make(DebitNoteService::class)->issue($this->actor('finance'), ReturnOrder::where('number', $rtn)->firstOrFail()));
        $this->assertSame([$dn], array_map(fn (DebitNote $n) => $n->number, $again));
        $this->assertSame(1, DebitNote::where('supplier_id', $supplier->id)->count());
        $this->assertSame(1, Journal::where('source_type', 'DebitNote')->where('source_number', $dn)->count());
    }

    public function test_cancel_reverses_the_journal_and_a_corrected_note_is_issued_from_the_return(): void
    {
        [$supplier, , $rtn, $dn] = $this->shippedPurchaseReturn();
        $url = "/api/finance/debit-notes/{$dn}/cancel";

        // while the note stands, the return cannot get a second one
        $this->expectRejected($this->postAs('wm', "/api/returns/{$rtn}/debit-note"), 'DN_EXISTS', [409]);

        $this->expectRejected($this->postAs('proc', $url, ['reason' => 'سعر خاطئ في الإشعار']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', $url, ['reason' => 'سعر خاطئ في الإشعار']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('finance', $url), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', $url, ['reason' => 'خطأ']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('finance', '/api/finance/debit-notes/DN-NOPE/cancel', ['reason' => 'سعر خاطئ في الإشعار']), 'DN_NOT_FOUND', [404]);
        $this->assertSame('issued', DebitNote::where('number', $dn)->value('status'));

        $res = $this->postAs('finance', $url, ['reason' => 'سعر خاطئ في الإشعار']);
        $cancelled = $this->expectOk($res);
        $this->assertSame(201, $res->getStatusCode());
        $this->assertSame('cancelled', $cancelled['status']);
        $this->assertNotNull($cancelled['cancelledAt']);
        $this->assertStringContainsString('سعر خاطئ في الإشعار', $cancelled['notes']);
        $this->assertEqualsCanonicalizing(['reversed', 'posted'], array_column($cancelled['journals'], 'status'));
        $this->assertEqualsWithDelta(0.00, $this->gl('ap', $dn), 0.001, 'the note\'s journal is reversed');
        $this->assertEqualsWithDelta(0.00, $this->gl('vat_input', $dn), 0.001);
        $this->assertEqualsWithDelta(200.00, $this->payableDebit($supplier), 0.001, 'the goods stay returned: the payable keeps the cost the movement posted');
        $this->assertSame(1, AuditLog::where('action', 'DN.CANCEL')->where('entity_number', $dn)->count());
        $this->expectRejected($this->postAs('finance', $url, ['reason' => 'سعر خاطئ في الإشعار']), 'DN_STATE', [422]);

        // the return shows the cancelled note and stays closed
        $detail = $this->expectOk($this->getAs('wm', "/api/returns/{$rtn}"));
        $this->assertSame(['closed', [['number' => $dn, 'status' => 'cancelled']]], [$detail['status'], array_map(fn ($n) => ['number' => $n['number'], 'status' => $n['status']], $detail['debitNotes'])]);
        $this->assertSame([], $this->expectOk($this->getAs('wm', "/api/returns?q={$rtn}"))['items'][0]['debitNotes'], 'the list shows active notes only');

        // a corrected note: the supplier credits 18 per unit, below the cost of 20 → 180 + 27 VAT = 207
        $this->expectRejected($this->postAs('proc', "/api/returns/{$rtn}/debit-note"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$rtn}/debit-note", ['lines' => [['lineNo' => 9, 'unitPrice' => 1]]]), 'LINE_NOT_FOUND', [400]);
        $this->expectRejected($this->postAs('wm', "/api/returns/{$rtn}/debit-note", ['lines' => [['lineNo' => 1, 'unitPrice' => -3]]]), 'INVALID_INPUT', [400]);
        $reissued = $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/debit-note", ['lines' => [['lineNo' => 1, 'unitPrice' => 18]]]));
        $this->assertSame(['closed', '18.0000'], [$reissued['status'], $reissued['lines'][0]['unitPrice']]);
        $this->assertSame([['cancelled', '230.00'], ['issued', '207.00']], array_map(fn ($n) => [$n['status'], $n['total']], $reissued['debitNotes']));
        $new = $reissued['debitNotes'][1]['number'];
        $this->assertNotSame($dn, $new);
        $this->assertSame(DebitNote::where('number', $new)->value('id'), ReturnOrder::where('number', $rtn)->value('debit_note_id'));
        $this->assertEqualsWithDelta(7.00, $this->gl('ap', $new), 0.001);          // (180 − 200) + 27
        $this->assertEqualsWithDelta(20.00, $this->gl('ppv', $new), 0.001);        // credited below cost: the variance is a cost
        $this->assertEqualsWithDelta(-27.00, $this->gl('vat_input', $new), 0.001);
        $this->assertEqualsWithDelta(207.00, $this->payableDebit($supplier), 0.001, 'payable ↓ by exactly the standing note');
        $this->expectRejected($this->postAs('wm', "/api/returns/{$rtn}/debit-note"), 'DN_EXISTS', [409]);
        $this->assertReconciles();
    }

    public function test_nothing_to_claim_before_the_goods_have_left(): void
    {
        $supplier = $this->newSupplier();
        $product = $this->product('DN-N');
        $this->receipt($supplier, $product, 10, 5.0, 'A-07-1-B2');
        $r = $this->expectOk($this->postAs('proc', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality',
            'lines' => [['sku' => $product->sku, 'qty' => 4, 'fromBin' => 'A-07-1-B2']]]));
        $this->expectRejected($this->postAs('wm', "/api/returns/{$r['number']}/debit-note"), 'DN_NOTHING_TO_CLAIM', [422]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $this->expectRejected($this->postAs('wm', "/api/returns/{$r['number']}/debit-note"), 'DN_NOTHING_TO_CLAIM', [422]);
        $this->assertSame(0, DebitNote::where('supplier_id', $supplier->id)->count());
        $this->assertEqualsWithDelta(0.00, $this->payableDebit($supplier), 0.001, 'approving a return posts nothing');
        // a customer return decided otherwise has nothing to claim either
        $plain = $this->returnInInspection($product, 1);
        $this->expectOk($this->postAs('wm', "/api/returns/{$plain}/decide", ['decision' => 'restock']));
        $this->expectRejected($this->postAs('wm', "/api/returns/{$plain}/debit-note"), 'DN_NOTHING_TO_CLAIM', [422]);
    }

    /**
     * A purchase return of 10 units received at 20 (VAT 15 %), approved and shipped.
     *
     * @return array{0: Supplier, 1: Product, 2: string, 3: string} supplier, product, return number, debit note number
     */
    private function shippedPurchaseReturn(): array
    {
        $supplier = $this->newSupplier();
        $product = $this->product('DN-P');
        [$po] = $this->receipt($supplier, $product, 60, 20.0, 'A-07-1-B1');
        $r = $this->expectOk($this->postAs('proc', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality',
            'poNumber' => $po->number, 'lines' => [['sku' => $product->sku, 'qty' => 10, 'fromBin' => 'A-07-1-B1']]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/ship"));

        return [$supplier, $product, $r['number'], $closed['debitNotes'][0]['number']];
    }
}
