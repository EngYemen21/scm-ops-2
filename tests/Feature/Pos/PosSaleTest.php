<?php

namespace Tests\Feature\Pos;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\Payment;
use App\Finance\Services\PeriodService;
use App\Pos\Models\PosOrder;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * A counter sale from the scan to the books: prices from the price service rounded once, VAT once on the document,
 * split payment with change, stock out FEFO through the inventory engine, a tax invoice with its receipts and the cost
 * of sales — every amount checked to the halala by hand; then the rules that refuse a sale.
 */
class PosSaleTest extends ApiTestCase
{
    use PosFixtures;

    public function test_a_sale_with_split_payment_reconciles_to_the_halala_in_stock_and_in_the_books(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);

        // the screen: a scan finds the product by its barcode, with the customer's price and what the shelf holds
        $scan = $this->expectOk($this->getAs($w['cashier'], "/api/pos/catalog/scan?register={$w['register']}&code=629{$w['tag']}1"));
        $this->assertSame([$w['p1'], '10.44', 70], [$scan['id'], $scan['price'], $scan['available']]);
        $search = $this->expectOk($this->getAs($w['cashier'], "/api/pos/catalog?register={$w['register']}&q=QP{$w['tag']}"));
        $this->assertSame(3, $search['total']);
        $this->expectRejected($this->getAs($w['cashier'], "/api/pos/catalog/scan?register={$w['register']}&code=NOPE{$w['tag']}"), 'POS_SCAN_UNKNOWN', [404]);

        $lines = [['productId' => $w['p1'], 'qty' => 3], ['productId' => $w['p2'], 'qty' => 7, 'discountPct' => 5]];
        // by hand: 3 × 10.44 = 31.32 · 7 × 3.33 = 23.31 − 5 % = 22.1445 → 22.14 · net 53.46 · VAT 15 % = 8.019 → 8.02 · total 61.48
        $quote = $this->expectOk($this->postAs($w['cashier'], '/api/pos/quote', ['session' => $s['id'], 'lines' => $lines]));
        $this->assertSame(['31.32', '22.14'], array_column($quote['lines'], 'lineTotal'));
        $this->assertSame(['53.46', '8.02', '61.48', '1.17'], [$quote['subtotal'], $quote['vatAmount'], $quote['total'], $quote['discountTotal']]);
        $this->assertSame([], $quote['stockShort']);

        $before = DB::table('audit_logs')->where('action', 'POS.SALE')->count();
        $res = $this->sell($w['cashier'], $s['id'], $lines, [['method' => $w['mCard'], 'amount' => '20.00', 'reference' => 'TRM-7781'], ['method' => $w['mCash'], 'amount' => '50.00']], ['expectedTotal' => '61.48']);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $o = $res->json();
        $this->assertMatchesRegularExpression('/^POS-\d{4}-\d{5}$/', $o['number']);
        $this->assertSame(['sale', '53.46', '8.02', '61.48', '70.00', '8.52', '0.00', '15.00', 0], [$o['kind'], $o['subtotal'], $o['vatAmount'], $o['total'], $o['tendered'], $o['changeDue'], $o['onAccount'], $o['vatPct'], $o['pendingQty']]);
        $this->assertSame([['20.00', '20.00'], ['50.00', '41.48']], array_map(fn ($p) => [$p['amount'], $p['applied']], $o['payments']), 'the change comes out of the cash');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'POS.SALE')->count() - $before);

        // stock: 3 + 7 left the warehouse, 70 → 67 and 30 → 23; the ledger still equals the balances
        $this->assertSame(67, $this->onHand($w['p1'], $w['shelf']) + $this->onHand($w['p1'], $w['shelf2']));
        $this->assertSame(23, $this->onHand($w['p2'], $w['shelf']));
        $this->assertTrue(DB::transaction(fn () => app(InventoryService::class)->reconcile($w['wh']))['ok']);
        $this->assertSame(2, DB::table('inventory_movements')->where('reference_type', 'PosOrder')->where('reference_id', $o['id'])->where('type', 'load')->count());

        // the tax invoice: the order's own figures, paid in full by two receipts
        $inv = CustomerInvoice::with('lines')->findOrFail($o['invoiceId']);
        $this->assertSame([$o['invoiceNumber'], 'invoice', 'paid', '53.46', '8.02', '61.48', '61.48', '22.50'], [$inv->number, $inv->kind, $inv->status, $inv->subtotal, $inv->vat_amount, $inv->total, $inv->paid_amount, $inv->cogs_total]);
        $this->assertSame(['31.32', '22.14'], $inv->lines->sortBy('line_no')->pluck('line_total')->values()->all());
        $receipts = Payment::whereIn('id', array_column($o['payments'], 'finPaymentId'))->orderBy('amount')->get();
        $this->assertSame([['card', '20.00', 'TRM-7781'], ['cash', '41.48', $o['number']]], $receipts->map(fn ($p) => [$p->method, $p->amount, $p->reference])->all());

        // the books, role by role (debit − credit over every journal of the order): all balanced, transit cleared
        $j = $this->journalsOf($this->expectOk($this->getAs('admin', "/api/pos/orders/{$o['id']}")));
        $this->assertSame('0.00', $this->net($j, 'ar'), 'invoice and receipts: the walk-in customer owes nothing');
        $this->assertSame('-53.46', $this->net($j, 'revenue'));
        $this->assertSame('-8.02', $this->net($j, 'vat_output'));
        $this->assertSame('41.48', $this->net($j, 'cash'));
        $this->assertSame('20.00', $this->net($j, 'bank'));
        $this->assertSame('22.50', $this->net($j, 'cogs'), '3 × 4.00 + 7 × 1.50 at the moving average');
        $this->assertSame('-22.50', $this->net($j, 'inventory'));
        $this->assertSame('0.00', $this->net($j, 'goods_in_transit'), 'what the load moved to transit, the cost of sales took out');

        // cost is shown only to who may see cost
        $asCashier = $this->expectOk($this->getAs($w['cashier'], "/api/pos/orders/{$o['id']}"));
        $this->assertArrayNotHasKey('cogsTotal', $asCashier);
        $this->assertArrayNotHasKey('cogs', $asCashier['lines'][0]);
        $this->assertSame('22.50', $this->expectOk($this->getAs('admin', "/api/pos/orders/{$o['id']}"))['cogsTotal']);

        // receipts print: 80 mm slip and A4 tax invoice, both languages, VAT breakdown, the QR code
        $slip = $this->getAs($w['cashier'], "/api/pos/orders/{$o['id']}/receipt");
        $slip->assertOk();
        $this->assertStringContainsString('text/html', $slip->headers->get('Content-Type'));
        foreach (['فاتورة ضريبية مبسطة', 'Simplified tax invoice', '61.48', '8.02', '53.46', 'TRM-7781', $o['invoiceNumber'], '<svg', '8.52'] as $text) {
            $this->assertStringContainsString($text, $slip->getContent());
        }
        $a4 = $this->getAs($w['cashier'], "/api/pos/orders/{$o['id']}/receipt?format=a4")->getContent();
        $this->assertStringContainsString('size:A4', $a4);
        $this->assertStringContainsString('فاتورة ضريبية · Tax invoice', $a4);

        // the list finds it by number, invoice number and SKU
        foreach ([$o['number'], $o['invoiceNumber'], "QP{$w['tag']}-2"] as $q) {
            $this->assertContains($o['id'], array_column($this->expectOk($this->getAs($w['cashier'], '/api/pos/orders?q='.urlencode($q)))['items'], 'id'));
        }
    }

    public function test_the_same_client_key_returns_the_same_sale_and_another_cart_under_it_is_refused(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);
        $body = ['session' => $s['id'], 'clientKey' => "retry-{$w['tag']}", 'lines' => [['productId' => $w['p1'], 'qty' => 1]], 'payments' => [['method' => $w['mCash'], 'amount' => '12.01']]];
        $first = $this->postAs($w['cashier'], '/api/pos/orders', $body);
        $this->assertSame(201, $first->getStatusCode(), $first->getContent());
        $again = $this->postAs($w['cashier'], '/api/pos/orders', $body);
        $this->assertSame(200, $again->getStatusCode());
        $this->assertSame([$first->json('id'), true], [$again->json('id'), $again->json('replayed')]);
        $this->assertSame(1, PosOrder::where('client_key', "retry-{$w['tag']}")->count());
        $this->assertSame(69, $this->onHand($w['p1'], $w['shelf']) + $this->onHand($w['p1'], $w['shelf2']), 'stock left once');

        $body['lines'][0]['qty'] = 2;
        $this->expectRejected($this->postAs($w['cashier'], '/api/pos/orders', $body), 'POS_KEY_REUSED', [409]);
    }

    public function test_the_rules_that_refuse_a_sale(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);
        $one = [['productId' => $w['p1'], 'qty' => 1]];   // 10.44 + 1.57 = 12.01
        $cash = [['method' => $w['mCash'], 'amount' => '12.01']];
        $footprint = $this->footprint($w);

        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 1, 'discountPct' => 10.5]], $cash), 'POS_DISCOUNT_LIMIT', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p3'], 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '8.05']]), 'POS_STOCK_SHORT', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 71]], [['method' => $w['mCash'], 'amount' => '9999']]), 'POS_STOCK_SHORT', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, [['method' => $w['mCash'], 'amount' => '12.00']]), 'POS_UNDERPAID', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, [['method' => $w['mCard'], 'amount' => '13.00', 'reference' => 'X1']]), 'POS_OVERPAID', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, [['method' => $w['mCard'], 'amount' => '12.01']]), 'POS_REFERENCE_REQUIRED', [400]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, [['method' => $w['mAcct'], 'amount' => '12.01']]), 'POS_ACCOUNT_NEEDS_CUSTOMER', [422]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, $cash, ['expectedTotal' => '12.00']), 'POS_TOTAL_CHANGED', [409]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => '1.5']], $cash), null, [400]);
        // someone else's session; a role without pos.sell (finance reads the section only)
        $this->expectRejected($this->sell($w['cashier2'], $s['id'], $one, $cash), 'POS_NOT_YOUR_SESSION', [403]);
        $this->expectRejected($this->sell('finance', $s['id'], $one, $cash), null, [403]);
        $this->expectOk($this->getAs('finance', '/api/pos/orders'));
        $this->assertSame($footprint, $this->footprint($w), 'a refused sale leaves nothing');

        // a closed fiscal month refuses the whole sale — not only its journal
        $period = app(PeriodService::class)->forDate(Carbon::now());
        DB::table('fin_periods')->where('id', $period->id)->update(['status' => 'closed']);
        try {
            $this->expectRejected($this->sell($w['cashier'], $s['id'], $one, $cash), 'PERIOD_CLOSED', [422]);
        } finally {
            DB::table('fin_periods')->where('id', $period->id)->update(['status' => 'open']);
        }
        $this->assertSame($footprint, $this->footprint($w), 'nothing of the sale remains when its accounting is refused');

        // an inactive price, product, method
        DB::table('products')->where('id', $w['p2'])->update(['active' => false]);
        $this->expectRejected($this->sell($w['cashier'], $s['id'], [['productId' => $w['p2'], 'qty' => 1]], $cash), 'POS_PRODUCT_INACTIVE', [422]);
        DB::table('products')->where('id', $w['p2'])->update(['active' => true]);
    }

    public function test_a_sale_on_account_leaves_the_invoice_open_for_that_part_and_the_customer_owes_it(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);
        // 2 × 10.44 = 20.88 + 3.13 VAT = 24.01: 10.00 cash, 14.01 on the customer's account
        $o = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 2]], [['method' => $w['mCash'], 'amount' => '10'], ['method' => $w['mAcct'], 'amount' => '14.01']], ['customer' => $w['named']]));
        $this->assertSame(['24.01', '14.01', '0.00'], [$o['total'], $o['onAccount'], $o['changeDue']]);
        $inv = CustomerInvoice::findOrFail($o['invoiceId']);
        $this->assertSame(['partially_paid', '10.00'], [$inv->status, $inv->paid_amount]);
        $this->assertSame('14.01', $this->net($this->journalsOf($this->expectOk($this->getAs('admin', "/api/pos/orders/{$o['id']}"))), 'ar'), 'the part on account is the customer\'s debt');
        $this->assertSame('14.01', (string) DB::table('customers')->where('id', $w['named'])->value('balance'));
    }

    public function test_a_register_that_sells_ahead_of_stock_records_what_is_owed_and_issues_it_when_stock_arrives(): void
    {
        $w = $this->world(['allowNegativeStock' => true]);
        $s = $this->openSession($w, $w['cashier']);
        // product 3 has no stock: the sale is recorded, its 4 units are owed
        $o = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p3'], 'qty' => 4], ['productId' => $w['p2'], 'qty' => 2]], [['method' => $w['mCash'], 'amount' => '40']]));
        $this->assertSame(4, $o['pendingQty']);
        $this->assertSame([[0, 4], [2, 0]], array_map(fn ($l) => [$l['issuedQty'], $l['pendingQty']], $o['lines']));
        $this->assertContains($o['id'], array_column($this->expectOk($this->getAs('admin', '/api/pos/orders?pending=1'))['items'], 'id'));
        $this->expectRejected($this->postAs('admin', "/api/pos/orders/{$o['id']}/issue-pending"), 'POS_STOCK_SHORT', [422]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/orders/{$o['id']}/issue-pending"), null, [403]);

        $this->stockIn($w['p3'], $w['wh'], $w['shelf'], 3, 2.0);
        $after = $this->expectOk($this->postAs('admin', "/api/pos/orders/{$o['id']}/issue-pending"));
        $this->assertSame(1, $after['pendingQty'], 'what the shelf held (3) was issued, 1 still owed');
        $this->assertSame(0, $this->onHand($w['p3'], $w['shelf']));
        $this->assertSame('9.00', $after['cogsTotal'], '2 × 1.50 at the sale + 3 × 2.00 now');
        $j = $this->journalsOf($after);
        $this->assertSame('0.00', $this->net($j, 'goods_in_transit'));
        $this->assertSame('9.00', $this->net($j, 'cogs'));
        $this->assertTrue(DB::transaction(fn () => app(InventoryService::class)->reconcile($w['wh']))['ok']);
    }

    public function test_held_sales_survive_on_the_server_and_are_recalled_or_discarded(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);
        $cart = ['session' => $s['id'], 'label' => 'العميل ذهب لإحضار المحفظة', 'clientKey' => "hold-{$w['tag']}", 'lines' => [['productId' => $w['p1'], 'qty' => 2, 'discountPct' => 5]], 'total' => '22.81'];
        $h = $this->expectOk($this->postAs($w['cashier'], '/api/pos/held', $cart));
        $this->assertSame($h['id'], $this->expectOk($this->postAs($w['cashier'], '/api/pos/held', $cart))['id'], 'the same cart held twice is one held sale');
        $this->assertSame([$h['id']], array_column($this->expectOk($this->getAs($w['cashier'], "/api/pos/sessions/{$s['id']}/held")), 'id'));
        $this->expectRejected($this->postAs($w['cashier2'], "/api/pos/held/{$h['id']}/recall"), 'POS_NOT_YOUR_SESSION', [403]);
        $recalled = $this->expectOk($this->postAs($w['cashier'], "/api/pos/held/{$h['id']}/recall"));
        $this->assertSame(['recalled', 2], [$recalled['status'], $recalled['payload']['lines'][0]['qty']]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/held/{$h['id']}/recall"), 'POS_HELD_STATE', [422]);
        $o = $this->expectOk($this->sell($w['cashier'], $s['id'], $recalled['payload']['lines'], [['method' => $w['mCash'], 'amount' => '30']], ['heldId' => $h['id']]));
        $this->assertSame($o['id'], DB::table('pos_held_sales')->where('id', $h['id'])->value('order_id'));

        $h2 = $this->expectOk($this->postAs($w['cashier'], '/api/pos/held', ['session' => $s['id'], 'lines' => [['productId' => $w['p2'], 'qty' => 1]]]));
        // a session with a held sale does not close silently
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/sessions/{$s['id']}/close", ['counts' => []]), 'POS_HELD_OPEN', [422]);
        $this->assertSame('discarded', $this->expectOk($this->postAs($w['cashier'], "/api/pos/held/{$h2['id']}/discard", ['reason' => 'ألغى العميل']))['status']);
    }
}
