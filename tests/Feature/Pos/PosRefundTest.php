<?php

namespace Tests\Feature\Pos;

use App\Finance\Models\CustomerInvoice;
use App\Pos\Models\PosOrderLine;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\ApiTestCase;

/**
 * Refunds against a sale: never more than was sold, the amounts in proportion with the last refund taking what is
 * left to the halala (so a fully refunded sale nets to zero in money and VAT), the goods back to the batch and bin they
 * left at the cost they left at, a credit note per refund, and the money paid back from the drawer or to the account.
 */
class PosRefundTest extends ApiTestCase
{
    use PosFixtures;

    private function refund(string $who, array $sale, string $session, array $lines, array $payments, string $reason = 'العميل أعاد الصنف'): TestResponse
    {
        return $this->postAs($who, "/api/pos/orders/{$sale['id']}/refund", ['session' => $session, 'clientKey' => 'r-'.Str::random(20), 'reason' => $reason, 'lines' => $lines, 'payments' => $payments]);
    }

    public function test_refunds_never_exceed_the_sale_and_a_fully_refunded_sale_nets_to_the_halala(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier'], '100.00');
        $sale = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 3], ['productId' => $w['p2'], 'qty' => 7, 'discountPct' => 5]],
            [['method' => $w['mCard'], 'amount' => '61.48', 'reference' => 'TRM-1']]));
        [$l1, $l2] = [$sale['lines'][0]['id'], $sale['lines'][1]['id']];

        // what is refused
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l2, 'qty' => 8]], [['method' => $w['mCash'], 'amount' => '29']]), 'POS_REFUND_EXCEEDS', [422]);
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l2, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '3.62']]), 'POS_REFUND_PAYMENTS', [422]);
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l2, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '3.63']], 'لا'), 'REASON_REQUIRED', [400]);
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => Str::ulid(), 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '3.63']]), 'POS_REFUND_LINE', [422]);
        $this->expectRejected($this->refund('finance', $sale, $s['id'], [['lineId' => $l2, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '3.63']]), null, [403]);

        // three refunds of one unit of line 2: 22.14 × 1/7 = 3.162… → 3.16, VAT 0.474 → 0.47, 3.63 in cash each
        $refunds = [];
        for ($i = 0; $i < 3; $i++) {
            $r = $this->expectOk($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l2, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '3.63']]));
            $this->assertSame(['refund', '3.16', '0.47', '3.63'], [$r['kind'], $r['subtotal'], $r['vatAmount'], $r['total']]);
            $refunds[] = $r;
        }
        $this->assertMatchesRegularExpression('/^POSR-\d{4}-\d{5}$/', $refunds[0]['number']);
        // the last refund takes what is left: net 53.46 − 9.48 = 43.98, VAT 8.02 − 1.41 = 6.61 (in proportion it would be 6.60)
        $last = $this->expectOk($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l1, 'qty' => 3], ['lineId' => $l2, 'qty' => 4]], [['method' => $w['mCard'], 'amount' => '50.59', 'reference' => 'TRM-RF']]));
        $this->assertSame(['43.98', '6.61', '50.59'], [$last['subtotal'], $last['vatAmount'], $last['total']]);
        $this->assertSame(['31.32', '12.66'], array_column($last['lines'], 'lineTotal'));
        $after = $this->expectOk($this->getAs('admin', "/api/pos/orders/{$sale['id']}"));
        $this->assertSame(['61.48', false], [$after['refundedTotal'], $after['refundable']]);
        $this->assertSame([3, 7], array_column($after['lines'], 'refundedQty'));
        $this->assertSame(4, count($after['refunds']));
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $l1, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '12.01']]), 'POS_REFUND_EXCEEDS', [422]);
        $this->expectRejected($this->postAs($w['cashier'], "/api/pos/orders/{$last['id']}/refund", ['session' => $s['id'], 'clientKey' => 'r-'.Str::random(20), 'reason' => 'مرتجع مرتجع',
            'lines' => [['lineId' => $last['lines'][0]['id'], 'qty' => 1]], 'payments' => [['method' => $w['mCash'], 'amount' => '1']]]), 'POS_REFUND_OF_REFUND', [422]);

        // the credit notes credit exactly the invoice
        $cns = CustomerInvoice::where('credit_of_id', $sale['invoiceId'])->get();
        $this->assertSame(4, $cns->count());
        $this->assertSame(['53.46', '8.02', '61.48'], [number_format($cns->sum(fn ($c) => (float) $c->subtotal), 2, '.', ''), number_format($cns->sum(fn ($c) => (float) $c->vat_amount), 2, '.', ''), number_format($cns->sum(fn ($c) => (float) $c->total), 2, '.', '')]);
        $this->assertSame(['credit_note', 'paid'], [$cns[0]->kind, $cns[0]->status]);

        // goods back to the shelves they left, at their cost; the ledger equals the balances
        $this->assertSame(70, $this->onHand($w['p1'], $w['shelf']) + $this->onHand($w['p1'], $w['shelf2']));
        $this->assertSame(30, $this->onHand($w['p2'], $w['shelf']));
        $this->assertTrue(DB::transaction(fn () => app(InventoryService::class)->reconcile($w['wh']))['ok']);

        // the first refund's books: returns and VAT debited, cash out, cost of sales reversed into inventory
        $j = $this->journalsOf($this->expectOk($this->getAs('admin', "/api/pos/orders/{$refunds[0]['id']}")));
        $this->assertSame(['3.16', '0.47', '-3.63', '0.00', '1.50', '-1.50'], [$this->net($j, 'sales_returns'), $this->net($j, 'vat_output'), $this->net($j, 'cash'), $this->net($j, 'ar'), $this->net($j, 'inventory'), $this->net($j, 'cogs')]);
        // the session: 100.00 float − 3 × 3.63 paid back
        $this->assertSame('89.11', $this->expectOk($this->getAs($w['cashier'], "/api/pos/sessions/{$s['id']}"))['expectedCashNow']);
        $receipt = $this->getAs($w['cashier'], "/api/pos/orders/{$last['id']}/receipt")->getContent();
        $this->assertStringContainsString('إشعار دائن · Credit note', $receipt);
        $this->assertStringContainsString($sale['invoiceNumber'], $receipt);
    }

    public function test_the_register_decides_who_refunds_and_where_the_goods_go_and_the_drawer_limits_cash(): void
    {
        $w = $this->world(['refundNeedsManager' => true, 'refundRestock' => 'returns']);
        $s = $this->openSession($w, $w['cashier'], '0');
        $sale = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 2]], [['method' => $w['mCard'], 'amount' => '24.01', 'reference' => 'T']]));
        $line = $sale['lines'][0]['id'];
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $line, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '12.01']]), 'POS_REFUND_NEEDS_MANAGER', [403]);
        // the manager refunds in a session of his own; his drawer is empty: no cash refund
        $boss = $this->manager("qpm{$w['tag']}");
        $this->expectOk($this->postAs($boss, "/api/pos/sessions/{$s['id']}/close", ['counts' => []]));
        $m = $this->openSession($w, $boss, '0');
        $this->expectRejected($this->refund($boss, $sale, $m['id'], [['lineId' => $line, 'qty' => 1]], [['method' => $w['mCash'], 'amount' => '12.01']]), 'POS_CASH_SHORT', [422]);
        $r = $this->expectOk($this->refund($boss, $sale, $m['id'], [['lineId' => $line, 'qty' => 1]], [['method' => $w['mCard'], 'amount' => '12.01', 'reference' => 'RF']]));
        $this->assertSame('12.01', $r['total']);
        $this->assertSame(1, $this->onHand($w['p1'], $w['ret']), 'into the returns area for inspection');
        $this->assertTrue(DB::transaction(fn () => app(InventoryService::class)->reconcile($w['wh']))['ok']);
    }

    public function test_a_refund_to_account_settles_the_open_invoice_and_owed_stock_is_cancelled_not_returned(): void
    {
        $w = $this->world(['allowNegativeStock' => true]);
        $s = $this->openSession($w, $w['cashier'], '0');
        // 2 × 10.44 → 24.01: 10 by card, 14.01 on account
        $sale = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p1'], 'qty' => 2]], [['method' => $w['mCard'], 'amount' => '10', 'reference' => 'T'], ['method' => $w['mAcct'], 'amount' => '14.01']], ['customer' => $w['named']]));
        $this->expectRejected($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $sale['lines'][0]['id'], 'qty' => 2]], [['method' => $w['mAcct'], 'amount' => '24.01']]), 'POS_REFUND_ACCOUNT', [422]);
        $this->expectOk($this->refund($w['cashier'], $sale, $s['id'], [['lineId' => $sale['lines'][0]['id'], 'qty' => 1]], [['method' => $w['mAcct'], 'amount' => '12.01']]));
        $inv = CustomerInvoice::findOrFail($sale['invoiceId']);
        $this->assertSame(['22.01', 'partially_paid'], [$inv->paid_amount, $inv->status]);
        $this->assertSame('2.00', (string) DB::table('customers')->where('id', $w['named'])->value('balance'));

        // a sale of product 3 (no stock) owes 2 units; refunding one cancels what is owed — nothing comes back to a shelf
        $owed = $this->expectOk($this->sell($w['cashier'], $s['id'], [['productId' => $w['p3'], 'qty' => 2]], [['method' => $w['mCard'], 'amount' => '16.10', 'reference' => 'T3']]));
        $this->assertSame(2, $owed['pendingQty']);
        $this->expectOk($this->refund($w['cashier'], $owed, $s['id'], [['lineId' => $owed['lines'][0]['id'], 'qty' => 1]], [['method' => $w['mCard'], 'amount' => '8.05', 'reference' => 'R3']]));
        $this->assertSame([1, 1], [(int) PosOrderLine::find($owed['lines'][0]['id'])->pending_qty, $this->expectOk($this->getAs('admin', "/api/pos/orders/{$owed['id']}"))['pendingQty']]);
        $this->assertSame(0, $this->onHand($w['p3'], $w['shelf']));
    }
}
