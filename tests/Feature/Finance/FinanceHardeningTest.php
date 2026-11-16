<?php

namespace Tests\Feature\Finance;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\Journal;
use App\Finance\Models\JournalLine;
use App\Finance\Services\CustomerInvoiceService;
use App\Finance\Services\LedgerService;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\PoLine;
use App\Models\Product;
use App\Models\ProofOfDelivery;
use App\Models\PurchaseOrder;
use App\Models\ReturnLine;
use App\Models\ReturnOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;
use Tests\Feature\Flows\FlowFixtures;
use Tests\Feature\Flows\ReturnsFixtures;
use Tests\Feature\Inventory\InventoryFixtures;

/**
 * Finance side of the 2026-11 operational hardening: what the new operational events must leave in the books.
 *  - a customer credit note credits what ARRIVED, per product, never more than was invoiced, and settles a fully
 *    returned invoice to the halala;
 *  - payables are aged net of supplier debit notes, and the payables sub-ledger equals the control account through a
 *    purchase return, a cancelled and re-issued note, and a return to supplier decided on a customer return;
 *  - stock on hand × average cost equals the inventory account after a transfer received at its ship cost, a transit
 *    loss, a rounding remainder and a voided goods receipt; the in-transit account ends at zero.
 */
class FinanceHardeningTest extends ApiTestCase
{
    use FlowFixtures, InventoryFixtures, ReturnsFixtures {
        FlowFixtures::stock insteadof InventoryFixtures;
    }

    /** One row of the sub-ledger ↔ general-ledger reconciliation (ar | ap | inventory). */
    private function check(string $key): array
    {
        return collect($this->expectOk($this->getAs('finance', '/api/finance/reconciliation'))['checks'])->firstWhere('key', $key);
    }

    private function assertTies(string $key, string $after): array
    {
        $c = $this->check($key);
        $this->assertTrue($c['ok'], "{$key} after {$after}: sub-ledger {$c['subLedger']} ≠ general ledger {$c['gl']}");

        return $c;
    }

    /** The inventory account (debit − credit) over the journal lines that name one product. */
    private function inventoryGl(Product $p): float
    {
        return round((float) JournalLine::query()->join('fin_journals', 'fin_journals.id', '=', 'fin_journal_lines.journal_id')->join('fin_accounts', 'fin_accounts.id', '=', 'fin_journal_lines.account_id')
            ->where('fin_journals.status', '!=', 'draft')->where('fin_accounts.role', 'inventory')->where('fin_journal_lines.product_id', $p->id)
            ->selectRaw('coalesce(sum(fin_journal_lines.debit), 0) - coalesce(sum(fin_journal_lines.credit), 0) as b')->value('b'), 2);
    }

    /** The product's side of the inventory sub-ledger: stock on hand × moving average. */
    private function stockValue(Product $p): float
    {
        return round((int) InventoryBalance::where('product_id', $p->id)->sum('on_hand') * (float) $p->fresh()->avg_cost, 2);
    }

    private function assertStockEqualsLedger(Product $p, string $after): void
    {
        $this->assertEqualsWithDelta($this->stockValue($p), $this->inventoryGl($p), 0.01, "stock on hand × average ≠ the inventory account after {$after}");
    }

    /**
     * A purchase return of 10 units received at 20 (VAT 15 %), approved and shipped: debit note 200 + 30 = 230, cost 200.
     *
     * @return array{0: Supplier, 1: Product, 2: string, 3: string} supplier, product, return number, debit note number
     */
    private function shippedPurchaseReturn(?Supplier $supplier = null): array
    {
        $supplier ??= $this->newSupplier();
        $product = $this->product('FH-PR');
        [$po] = $this->receipt($supplier, $product, 60, 20.0, 'A-07-1-B1');
        $r = $this->expectOk($this->postAs('proc', '/api/returns', ['type' => 'sup', 'source' => $supplier->name_ar, 'supplierCode' => $supplier->code, 'warehouseCode' => 'RYD', 'reasonCode' => 'quality',
            'poNumber' => $po->number, 'lines' => [['sku' => $product->sku, 'qty' => 10, 'fromBin' => 'A-07-1-B1']]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/ship"));

        return [$supplier, $product, $r['number'], $closed['debitNotes'][0]['number']];
    }

    /** An approved, unpaid supplier invoice with its journal (Dr purchase cost / Cr payable), due `$dueInDays` from today (negative = overdue). */
    private function payable(Supplier $supplier, PurchaseOrder $po, float $amount, int $dueInDays): SupplierInvoice
    {
        return DB::transaction(function () use ($supplier, $po, $amount, $dueInDays) {
            $inv = SupplierInvoice::create(['number' => self::code('INV-T'), 'po_id' => $po->id, 'supplier_id' => $supplier->id, 'supplier_invoice_no' => self::code('F'), 'invoice_date' => now()->subDays(60),
                'due_date' => now()->startOfDay()->addDays($dueInDays), 'subtotal' => $amount, 'vat_pct' => 0, 'vat_amount' => 0, 'total' => $amount, 'paid_amount' => 0, 'status' => 'approved']);
            $ref = ['referenceType' => 'SupplierInvoice', 'referenceId' => $inv->id];
            $journal = $this->app->make(LedgerService::class)->post(null, [
                'eventKey' => "test-si:{$inv->id}", 'source' => ['type' => 'SupplierInvoice', 'id' => $inv->id, 'number' => $inv->number], 'memo' => "فاتورة مورد اختبار {$inv->number}",
                'lines' => [['role' => 'freight_in', 'debit' => $amount] + $ref, ['role' => 'ap', 'credit' => $amount, 'partnerType' => 'supplier', 'partnerId' => $supplier->id] + $ref],
            ]);
            $inv->update(['journal_id' => $journal->id]);

            return $inv->refresh();
        });
    }

    /**
     * A delivered and invoiced order at a given price and line discount (the fixture's own delivery is always 10 per unit).
     *
     * @return array{0: FulfillmentOrder, 1: CustomerInvoice}
     */
    private function deliveredAt(Product $product, int $qty, float $price, float $discPct): array
    {
        $wh = $this->ryd();
        $this->stockAt($product, 'A-07-1-B1', $qty, 2.0);
        [$trip, $stops, $fos] = $this->dispatchedTrip($this->demoDriver(), [[[$product, $qty]]]);
        SalesOrderLine::where('so_id', $fos[0]->so_id)->update(['price' => $price, 'disc_pct' => $discPct]);
        $fo = $fos[0]->load(['lines.soLine', 'lines.product']);
        $invoice = DB::transaction(function () use ($wh, $product, $qty, $trip, $stops, $fo) {
            $this->inv()->post(null, null, ['type' => 'load', 'productId' => $product->id, 'qty' => $qty,
                'from' => ['warehouseId' => $wh->id, 'binId' => $this->inv()->bin($wh->id, 'A-07-1-B1')->id], 'reference' => ['type' => 'FulfillmentOrder', 'id' => $fo->id, 'number' => $fo->number]]);
            $pod = ProofOfDelivery::create(['number' => self::code('POD-T'), 'trip_id' => $trip->id, 'stop_id' => $stops[0]->id, 'fo_id' => $fo->id, 'customer_id' => $fo->customer_id,
                'result' => 'delivered', 'delivered_qty' => $qty, 'returned_qty' => 0]);

            return $this->app->make(CustomerInvoiceService::class)->issueFromDelivery($this->actor('driver'), $fo, $pod, $fo->lines->mapWithKeys(fn ($l) => [$l->id => $l->qty])->all());
        });

        return [$fo->refresh(), $invoice];
    }

    /**
     * A received customer return of a delivery, stored exactly as the returns flow leaves it, handed to the finance
     * method as it is — no `lines` relation prepared, no view.
     *
     * @param  array<int, array{0: int, 1: ?int}>  $lines  [requested qty, received qty | null = never received partially]
     * @return array{0: ReturnOrder, 1: ?CustomerInvoice}
     */
    private function creditFor(FulfillmentOrder $fo, Product $product, array $lines): array
    {
        return DB::transaction(function () use ($fo, $product, $lines) {
            $rtn = ReturnOrder::create(['number' => self::code('RTN-T'), 'type' => 'cust', 'customer_id' => $fo->customer_id, 'fo_id' => $fo->id, 'warehouse_id' => $fo->warehouse_id, 'status' => 'received', 'attachments' => []]);
            foreach ($lines as $i => [$qty, $received]) {
                ReturnLine::create(['return_id' => $rtn->id, 'line_no' => $i + 1, 'product_id' => $product->id, 'qty' => $qty, 'received_qty' => $received]);
            }

            return [$rtn, $this->app->make(CustomerInvoiceService::class)->creditFromReturn(null, $rtn)];
        });
    }

    // ───────────────────────── 1. customer credit note ─────────────────────────

    public function test_a_credit_note_credits_what_arrived_per_product_and_never_more_than_was_invoiced(): void
    {
        $product = $this->product('FH-CN');
        [$fo, $invoice] = $this->invoicedDelivery($product, 10);   // 10 × 10 = 100 + 15 % = 115
        $this->assertSame(['100.00', '15.00', '115.00'], [$invoice->subtotal, $invoice->vat_amount, $invoice->total]);

        // through the returns flow: two lines of one product, each received short → ONE credit line for what arrived (2 + 1)
        $r = $this->expectOk($this->postAs('sales', '/api/returns', ['type' => 'cust', 'source' => 'عميل', 'warehouseCode' => 'RYD', 'reasonCode' => 'wrong_product', 'foNumber' => $fo->number,
            'lines' => [['sku' => $product->sku, 'qty' => 3], ['sku' => $product->sku, 'qty' => 3]]]));
        $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/approve"));
        $received = $this->expectOk($this->postAs('wm', "/api/returns/{$r['number']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 2], ['lineNo' => 2, 'receivedQty' => 1]]]));
        $first = CustomerInvoice::with('lines')->where('return_id', $received['id'])->firstOrFail();
        $this->assertSame(['credit_note', $invoice->id, '30.00', '4.50', '34.50'], [$first->kind, $first->credit_of_id, $first->subtotal, $first->vat_amount, $first->total]);
        $this->assertSame([[3, '30.00']], $first->lines->map(fn ($l) => [$l->qty, $l->line_total])->all(), 'received 2 + 1 of the 3 + 3 requested');
        $this->assertSame($first->number, $received['creditNote']['number']);
        $this->assertEqualsWithDelta(30.00, $this->gl('sales_returns', $first->number), 0.001);
        $this->assertEqualsWithDelta(4.50, $this->gl('vat_output', $first->number), 0.001);
        $this->assertEqualsWithDelta(-34.50, $this->gl('ar', $first->number), 0.001);
        $this->assertSame(['partially_paid', '34.50'], [$invoice->refresh()->status, $invoice->paid_amount]);

        // the method itself, given the stored return: `received_qty` per line (null = received in full), added up per product
        [$rtn, $second] = $this->creditFor($fo, $product, [[5, 2], [4, null]]);
        $this->assertSame([[6, '60.00']], $second->lines->map(fn ($l) => [$l->qty, $l->line_total])->all(), '2 received + 4 (a line from before partial receipts)');
        $this->assertSame(['60.00', '9.00', '69.00'], [$second->subtotal, $second->vat_amount, $second->total]);
        // idempotent per return
        $again = DB::transaction(fn () => $this->app->make(CustomerInvoiceService::class)->creditFromReturn(null, $rtn));
        $this->assertSame($second->id, $again->id);
        $this->assertSame(2, CustomerInvoice::where('credit_of_id', $invoice->id)->count());

        // 9 of the 10 invoiced units are credited: 5 more come back, only the last one is creditable — and it settles the invoice
        [, $third] = $this->creditFor($fo, $product, [[5, 5]]);
        $this->assertSame([[1, '10.00']], $third->lines->map(fn ($l) => [$l->qty, $l->line_total])->all());
        $this->assertSame(['10.00', '1.50', '11.50'], [$third->subtotal, $third->vat_amount, $third->total]);
        $this->assertSame(['paid', '115.00'], [$invoice->refresh()->status, $invoice->paid_amount]);
        // nothing is left to credit
        [, $none] = $this->creditFor($fo, $product, [[2, 2]]);
        $this->assertNull($none);
        $this->assertSame(10, (int) CustomerInvoice::where('credit_of_id', $invoice->id)->get()->sum(fn ($cn) => $cn->lines()->sum('qty')));
        $this->assertEqualsWithDelta(0.00, $this->gl('ar', $invoice->number) + $this->gl('ar', $first->number) + $this->gl('ar', $second->number) + $this->gl('ar', $third->number), 0.001, 'the customer owes nothing for a fully returned delivery');
        $this->assertTies('ar', 'credit notes');
        $this->assertReconciles();
    }

    public function test_the_note_that_returns_the_last_unit_settles_the_invoice_to_the_halala(): void
    {
        // 3 × 3.33 less 10 % = 8.991 → invoiced 8.99; one unit alone is 2.997 → 3.00, so three single returns would credit 9.00
        $product = $this->product('FH-RD');
        [$fo, $invoice] = $this->deliveredAt($product, 3, 3.33, 10);
        $this->assertSame('8.99', $invoice->subtotal);
        $notes = [];
        foreach ([1, 2, 3] as $n) {
            [, $notes[$n]] = $this->creditFor($fo, $product, [[1, 1]]);
        }
        $this->assertSame(['3.00', '3.00', '2.99'], [$notes[1]->subtotal, $notes[2]->subtotal, $notes[3]->subtotal]);
        $this->assertSame('2.99', $notes[3]->lines()->value('line_total'), 'the lines of a note add up to its net');
        $sum = fn (string $column) => round(array_sum(array_map(fn (CustomerInvoice $cn) => (float) $cn->{$column}, $notes)), 2);
        $this->assertSame([(float) $invoice->subtotal, (float) $invoice->vat_amount, (float) $invoice->total], [$sum('subtotal'), $sum('vat_amount'), $sum('total')]);
        $this->assertSame(['paid', $invoice->total], [$invoice->refresh()->status, $invoice->paid_amount], 'settled exactly: nothing over, nothing left open');
        $this->assertEqualsWithDelta(0.00, $this->gl('ar', $invoice->number) + array_sum(array_map(fn (CustomerInvoice $cn) => $this->gl('ar', $cn->number), $notes)), 0.001);
        $this->assertTies('ar', 'a fully returned invoice');
    }

    // ───────────────────────── 2. payables aging ─────────────────────────

    public function test_payables_are_aged_net_of_debit_notes_oldest_invoice_first(): void
    {
        $supplier = $this->newSupplier();
        $po = PurchaseOrder::create(['number' => self::code('PO-A'), 'supplier_id' => $supplier->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'received', 'total' => 0, 'open_qty' => 0]);
        $this->payable($supplier, $po, 100, -45);   // 45 days overdue
        $this->payable($supplier, $po, 150, -10);   // 10 days overdue
        $this->payable($supplier, $po, 200, 20);    // not due yet
        $aging = fn () => $this->expectOk($this->getAs('finance', '/api/finance/reports/aging/supplier'));
        $row = fn (Supplier $s) => collect($aging()['rows'])->firstWhere('code', $s->code);
        $figures = fn (array $r) => [$r['current'], $r['d30'], $r['d60'], $r['d90'], $r['d90p'], $r['total'], $r['invoices'], $r['debitNotes'], $r['returnsUnclaimed']];
        $this->assertEquals([200, 150, 100, 0, 0, 450, 3, 0, 0], $figures($row($supplier)), 'no return yet: every invoice in its own bucket');

        // a debit note of 230: it clears the oldest invoice (100) and takes 130 off the next one
        [, , $rtn, $dn] = $this->shippedPurchaseReturn($supplier);
        $this->assertEquals([200, 20, 0, 0, 0, 220, 3, 230, 0], $figures($row($supplier)));
        $this->assertTies('ap', 'open invoices and a debit note');

        // cancelled: the goods stay returned, so the payable is still lower by their cost (200) until a note is issued again
        $this->expectOk($this->postAs('finance', "/api/finance/debit-notes/{$dn}/cancel", ['reason' => 'سعر خاطئ في الإشعار']));
        $this->assertEquals([200, 50, 0, 0, 0, 250, 3, 0, 200], $figures($row($supplier)));
        // re-issued at 18 per unit: 180 + 27 = 207
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/debit-note", ['lines' => [['lineNo' => 1, 'unitPrice' => 18]]]));
        $this->assertEquals([200, 43, 0, 0, 0, 243, 3, 207, 0], $figures($row($supplier)));

        // a supplier with a note and nothing to set it against owes us: a negative amount that is not overdue
        [$other] = $this->shippedPurchaseReturn();
        $this->assertEquals([-230, 0, 0, 0, 0, -230, 0, 230, 0], $figures($row($other)));

        $report = $aging();
        $this->assertSame(['current', 'd30', 'd60', 'd90', 'd90p', 'total', 'debitNotes', 'returnsUnclaimed'], array_keys($report['totals']));
        $this->assertEqualsWithDelta(array_sum(array_column($report['rows'], 'total')), $report['totals']['total'], 0.001);
        $this->assertGreaterThanOrEqual(437, $report['totals']['debitNotes']);
        // the receivables report is what it was
        $this->assertSame(['current', 'd30', 'd60', 'd90', 'd90p', 'total'], array_keys($this->expectOk($this->getAs('finance', '/api/finance/reports/aging/customer'))['totals']));
        $this->assertTies('ap', 'a cancelled and re-issued note next to open invoices');
    }

    // ───────────────────────── 3. payables sub-ledger = control account ─────────────────────────

    public function test_the_payables_sub_ledger_follows_supplier_returns_through_every_step(): void
    {
        $start = $this->assertTies('ap', 'the start')['parts'];
        $moved = function () use ($start): array {   // how far the two return figures of the sub-ledger moved since the start
            $now = $this->check('ap')['parts'];

            return ['debitNotes' => round($now['debitNotes'] - $start['debitNotes'], 2), 'returnsUnclaimed' => round($now['returnsUnclaimed'] - $start['returnsUnclaimed'], 2)];
        };

        // 1. a purchase return shipped with its debit note: payable ↓ 230 (200 by the stock movement, 30 by the note's journal)
        [$supplier, $product, $rtn, $dn] = $this->shippedPurchaseReturn();
        $this->assertEqualsWithDelta(230.00, $this->payableDebit($supplier), 0.001);
        $this->assertTies('ap', 'a purchase return shipped with its debit note');
        $this->assertEquals(['debitNotes' => 230, 'returnsUnclaimed' => 0], $moved());

        // 2. the note cancelled: its journal is reversed, the movement's 200 stay — then re-issued at another price
        $this->expectOk($this->postAs('finance', "/api/finance/debit-notes/{$dn}/cancel", ['reason' => 'سعر خاطئ في الإشعار']));
        $this->assertEqualsWithDelta(200.00, $this->payableDebit($supplier), 0.001);
        $this->assertTies('ap', 'the debit note was cancelled');
        $this->assertEquals(['debitNotes' => 0, 'returnsUnclaimed' => 200], $moved());
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/debit-note", ['lines' => [['lineNo' => 1, 'unitPrice' => 18]]]));
        $this->assertEqualsWithDelta(207.00, $this->payableDebit($supplier), 0.001);
        $this->assertTies('ap', 'the debit note was issued again');
        $this->assertEquals(['debitNotes' => 207, 'returnsUnclaimed' => 0], $moved());

        // 3. a customer return decided "to supplier": 2 units at the supplier's order price 20 → 40 + 6 VAT
        $plain = $this->returnInInspection($product, 2);
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$plain}/decide", ['decision' => 'sup', 'supplierCode' => $supplier->code]));
        $this->assertSame([['issued', '46.00']], array_map(fn ($n) => [$n['status'], $n['total']], $closed['debitNotes']));
        $this->assertEqualsWithDelta(253.00, $this->payableDebit($supplier), 0.001);
        $this->assertTies('ap', 'a return to supplier decided on a customer return');
        $this->assertEquals(['debitNotes' => 253, 'returnsUnclaimed' => 0], $moved());

        // the board tells finance how much of "open payables" is already claimed back
        $parts = $this->check('ap')['parts'];
        $this->assertEqualsWithDelta($parts['debitNotes'] + $parts['returnsUnclaimed'], $this->expectOk($this->getAs('finance', '/api/finance/board'))['payables']['returnCredits'], 0.001);
        // and the command the release check runs agrees: trial balance balanced, AR / AP / inventory sub-ledgers = general ledger
        $this->assertSame(0, Artisan::call('scm:finance-check'), Artisan::output());
        $this->assertReconciles();
    }

    // ───────────────────────── 4. inventory and in-transit after transfers and a voided receipt ─────────────────────────

    public function test_a_transfer_keeps_inventory_tied_through_a_price_change_a_transit_loss_and_a_rounding_remainder(): void
    {
        $tag = 'H'.self::uid();
        $src = $this->makeZone('RYD', "H{$tag}", ["{$tag}-R1"]);
        $dst = $this->makeZone('JED', "H{$tag}", ["{$tag}-J1"]);
        [$r1, $j1] = [$src['bins']["{$tag}-R1"], $dst['bins']["{$tag}-J1"]];
        $ship = function (array $p, int $qty) use ($r1): array {
            $res = $this->postAs('wm', '/api/inventory/transfers', ['fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(), 'reasonCode' => 'rebalance',
                'lines' => [['sku' => $p['product']->sku, 'qty' => $qty, 'batchNo' => $p['batch']->batch_no, 'fromBin' => $r1->code]]]);
            $res->assertStatus(201);
            $t = $res->json();
            foreach (['approve', 'start-picking', 'picking-done', 'ship'] as $step) {
                $t = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/{$step}"));
            }

            return $t;
        };
        $receive = fn (array $t, int $qty) => $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => $qty, 'toBin' => $j1->code]]]));

        // 10 units leave at 10; while they are on the road a dearer receipt moves the average of what stayed to 20
        $p = $this->makeProduct($tag);
        $this->stockAtCost($p['product'], $p['batch'], $src['wh'], $r1, 50, 10.0, "OPEN-{$tag}");
        $t = $ship($p, 10);
        $this->assertSame(100.0, $this->inTransitNet($t['id']));
        $this->stockAtCost($p['product'], $p['batch'], $src['wh'], $r1, 40, 30.0, "OPEN2-{$tag}");
        $this->assertEqualsWithDelta(20.0, (float) $p['product']->fresh()->avg_cost, 0.0001);
        $this->assertStockEqualsLedger($p['product'], 'a receipt while goods are on the road');

        // 4 arrive at the cost they left at (40): the in-transit account goes down by exactly that, and the arrival is
        // blended into the average — (80 × 20 + 4 × 10) / 84 — so stock on hand × average is still the inventory account
        $receive($t, 4);
        $this->assertSame(60.0, $this->inTransitNet($t['id']));
        $this->assertEqualsWithDelta(19.5238, (float) $p['product']->fresh()->avg_cost, 0.0001);
        $this->assertStockEqualsLedger($p['product'], 'a transfer arrival at its ship cost');
        $this->assertTies('inventory', 'a transfer arrival at its ship cost');

        // the other 6 never arrive: written off at the ship cost — in-transit is back to zero and inventory is untouched
        $value = $this->stockValue($p['product']);
        $resolved = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/resolve-variance", ['resolution' => 'loss', 'note' => 'تلف في الطريق']));
        $this->assertSame('received', $resolved['status']);
        $this->assertSame(0.0, $this->inTransitNet($t['id']));
        $this->assertEqualsWithDelta(60.00, $this->gl('inventory_loss', $t['number']), 0.001);
        $this->assertFalse(Journal::where('event_key', "trf-clear:{$t['id']}")->exists(), 'nothing was left to clear');
        $this->assertSame($value, $this->stockValue($p['product']));
        $this->assertStockEqualsLedger($p['product'], 'a transit loss');
        $this->assertTies('inventory', 'a transit loss');

        // 3 units at 0.3333 leave as 1.00 and arrive as 3 × 0.33: the clearing journal moves the last halala to inventory
        $z = $this->makeProduct("Z{$tag}");
        $this->stockAtCost($z['product'], $z['batch'], $src['wh'], $r1, 3, 0.3333, "OPENZ-{$tag}");
        $t2 = $ship($z, 3);
        foreach ([1, 2, 3] as $n) {
            $receive($t2, 1);
        }
        $this->assertTrue(Journal::where('event_key', "trf-clear:{$t2['id']}")->exists());
        $this->assertSame(0.0, $this->inTransitNet($t2['id']));
        $this->assertEqualsWithDelta(0.00, $this->gl('inventory', $t2['number']), 0.001, 'a completed transfer changes the inventory account by exactly nothing');
        $this->assertEqualsWithDelta(0.3333, (float) $z['product']->fresh()->avg_cost, 0.00001, 'arriving at the cost it is carried at leaves the average alone');
        $this->assertTies('inventory', 'a transfer cleared of its rounding remainder');
        $this->assertReconciles();
    }

    public function test_a_voided_receipt_leaves_inventory_tied_even_when_the_stock_can_no_longer_carry_its_cost(): void
    {
        $wh = $this->ryd();
        $shelf = $this->inv()->bin($wh->id, 'A-07-1-B1');
        // 10 units at 10 on the shelf, a receipt of 10 at 20 (average 15) still in STG-IN; `$issued` shelf units leave at 15
        // before the receipt is voided. [issued, purchase price variance of the void, average afterwards]
        foreach ([[5, 0.0, 5.0], [9, -50.0, 15.0], [10, -50.0, 15.0]] as [$issued, $variance, $average]) {
            $p = $this->product('FH-GV');
            $this->stockAt($p, 'A-07-1-B1', 10, 10.0);
            [$shipment, $po] = $this->expectedShipment([[$p, 10]]);
            PoLine::where('po_id', $po->id)->update(['price' => 20]);
            $url = "/api/inbound/shipments/{$shipment->number}";
            $this->expectOk($this->postAs('wm', "{$url}/arrive", ['carrier' => 'FH-TEST']));
            $this->expectOk($this->postAs('wm', "{$url}/inspect"));
            $grn = $this->expectOk($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 10]]]));
            $this->assertEqualsWithDelta(15.0, (float) $p->fresh()->avg_cost, 0.0001);
            DB::transaction(fn () => $this->inv()->post(null, null, ['type' => 'scrap', 'productId' => $p->id, 'qty' => $issued,
                'from' => ['warehouseId' => $wh->id, 'binId' => $shelf->id], 'reference' => ['type' => 'Test', 'number' => 'T-ISSUE']]));
            $this->assertStockEqualsLedger($p, 'an issue at the blended average');

            $this->expectOk($this->postAs('wm', "/api/inbound/grns/{$grn['number']}/void", ['reason' => 'استُلمت على شحنة خاطئة']));
            $case = "voiding a receipt after {$issued} other units left at the blended average";
            $this->assertSame(10 - $issued, $this->totalOnHand($p));
            $this->assertEqualsWithDelta(0.00, $this->gl('grni', $grn['number']), 0.001, "the accrual is closed in full — {$case}");
            $this->assertEqualsWithDelta($variance, $this->gl('ppv', $grn['number']), 0.001, $case);
            $this->assertEqualsWithDelta($average, (float) $p->fresh()->avg_cost, 0.0001, $case);
            $this->assertStockEqualsLedger($p, $case);
            $this->assertTies('inventory', $case);
        }
        $this->assertReconciles();
    }
}
