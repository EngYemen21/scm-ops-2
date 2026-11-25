<?php

namespace Tests\Feature\Pos;

use App\Pos\Models\PosOrderLine;
use App\Pos\Support\Checkpoint;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * "All or none", proven: a failure is injected at every step of a sale and of a refund (after the order, after the
 * stock, after the invoice, after the cost of sales, after the receipts, just before commit). Each time nothing
 * remains — no order, line, payment, stock movement, balance change, invoice, receipt, journal, audit row or number
 * taken — and the same sale then goes through.
 */
class PosAtomicityTest extends ApiTestCase
{
    use PosFixtures;

    public function test_a_sale_fails_whole_at_every_step_and_a_refund_too(): void
    {
        $w = $this->world();
        $s = $this->openSession($w, $w['cashier']);
        $lines = [['productId' => $w['p1'], 'qty' => 3], ['productId' => $w['p2'], 'qty' => 7, 'discountPct' => 5]];
        $payments = [['method' => $w['mCard'], 'amount' => '20.00', 'reference' => 'TRM'], ['method' => $w['mCash'], 'amount' => '50.00']];

        foreach (Checkpoint::SALE as $step) {
            $before = $this->footprint($w);
            Checkpoint::failAt($step);
            $res = $this->sell($w['cashier'], $s['id'], $lines, $payments);
            Checkpoint::failAt(null);
            $this->assertSame(500, $res->getStatusCode(), "failure at {$step}: ".$res->getContent());
            $this->assertSame($before, $this->footprint($w), "a failure at «{$step}» leaves nothing behind");
        }
        $sale = $this->expectOk($this->sell($w['cashier'], $s['id'], $lines, $payments));
        $this->assertSame('61.48', $sale['total']);

        $refund = fn () => $this->postAs($w['cashier'], "/api/pos/orders/{$sale['id']}/refund", ['session' => $s['id'], 'clientKey' => 'r-'.Str::random(20), 'reason' => 'اختبار الفشل',
            'lines' => [['lineId' => $sale['lines'][0]['id'], 'qty' => 2]], 'payments' => [['method' => $w['mCash'], 'amount' => '24.01']]]);
        foreach (Checkpoint::REFUND as $step) {
            $before = $this->footprint($w);
            Checkpoint::failAt($step);
            $res = $refund();
            Checkpoint::failAt(null);
            $this->assertSame(500, $res->getStatusCode(), "failure at {$step}: ".$res->getContent());
            $this->assertSame($before, $this->footprint($w), "a refund failing at «{$step}» leaves nothing behind");
            $this->assertSame(0, (int) PosOrderLine::find($sale['lines'][0]['id'])->refunded_qty);
        }
        $r = $this->expectOk($refund());
        $this->assertSame('24.01', $r['total']);
    }
}
