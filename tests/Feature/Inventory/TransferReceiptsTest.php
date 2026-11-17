<?php

namespace Tests\Feature\Inventory;

use App\Finance\Models\Journal;
use App\Models\Batch;
use App\Models\Bin;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\Product;
use App\Models\TransferLine;
use App\Models\WarehouseTransfer;
use Illuminate\Testing\TestResponse;
use Tests\ApiTestCase;

/**
 * Operational hardening W6 — the destination side of a transfer: the bin is chosen at receipt, goods arrive in as many
 * receipts as it takes and are valued at the cost they left the source at, and what never arrives is a variance that is
 * written off or received back into the source before the transfer can close.
 */
class TransferReceiptsTest extends ApiTestCase
{
    use InventoryFixtures;

    /** @var array<string,mixed>|null sequential story: 100 units (cost 10) in R1 (RYD); R2 (RYD) and J1 / J2 (JED) empty; J3 (JED) blocked */
    private static ?array $fx = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$fx === null) {
            $tag = self::uid();
            $src = $this->makeZone('RYD', "C{$tag}", ["C{$tag}-R1", "C{$tag}-R2"]);
            $dst = $this->makeZone('JED', "C{$tag}", ["C{$tag}-J1", "C{$tag}-J2", "C{$tag}-J3"]);
            $dst['bins']["C{$tag}-J3"]->update(['status' => 'blocked']);
            $p = $this->makeProduct($tag);
            $this->stock($p['product'], $p['batch'], $src['wh'], $src['bins']["C{$tag}-R1"], 100, "OPEN-{$tag}");
            self::$fx = ['tag' => $tag, 'product' => $p['product'], 'batch' => $p['batch'], 'ryd' => $src['wh'], 'jed' => $dst['wh'],
                'r1' => $src['bins']["C{$tag}-R1"], 'r2' => $src['bins']["C{$tag}-R2"], 'j1' => $dst['bins']["C{$tag}-J1"], 'j2' => $dst['bins']["C{$tag}-J2"], 'j3' => $dst['bins']["C{$tag}-J3"]];
        }
    }

    /** A transfer RYD → JED of one product, shipped (in transit). */
    private function shipped(int $qty, ?string $toBin = null, ?Product $product = null, ?Batch $batch = null, ?Bin $from = null): array
    {
        $product ??= self::$fx['product'];
        $batch ??= self::$fx['batch'];
        $res = $this->postAs('wm', '/api/inventory/transfers', [
            'fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(), 'reasonCode' => 'rebalance',
            'lines' => [['sku' => $product->sku, 'qty' => $qty, 'batchNo' => $batch->batch_no, 'fromBin' => ($from ?? self::$fx['r1'])->code] + ($toBin ? ['toBin' => $toBin] : [])],
        ]);
        $res->assertStatus(201);
        $t = $res->json();
        foreach (['approve', 'start-picking', 'picking-done', 'ship'] as $step) {
            $t = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/{$step}"));
        }
        $this->assertSame('transit', $t['status']);

        return $t;
    }

    private function receive(string $id, array $lines = []): TestResponse
    {
        return $this->postAs('wm', "/api/inventory/transfers/{$id}/receive", $lines ? ['lines' => $lines] : []);
    }

    private function resolve(string $id, array $body): TestResponse
    {
        return $this->postAs('wm', "/api/inventory/transfers/{$id}/resolve-variance", $body);
    }

    private function detail(string $id, string $user = 'wm'): array
    {
        return $this->expectOk($this->getAs($user, "/api/inventory/transfers/{$id}"));
    }

    private function onHand(string $bin, ?Product $product = null, ?Batch $batch = null): int
    {
        return (int) $this->balance(($product ?? self::$fx['product'])->id, self::$fx[$bin]->id, ($batch ?? self::$fx['batch'])->id)?->on_hand;
    }

    private function openExceptions(string $number): int
    {
        return OpsException::where('entity_number', $number)->where('kind', 'transfer')->whereIn('status', ['open', 'ack'])->count();
    }

    public function test_destination_bin_is_optional_at_creation_and_chosen_at_receipt_over_several_receipts(): void
    {
        $t = $this->shipped(20); // created without any destination bin
        $this->assertNull($t['lines'][0]['toBin']);
        $this->assertNull($t['lines'][0]['toBinId']);

        // the receipt form is offered a bin: nothing of this product is in JED yet → the first free bin of its class
        $suggested = $this->detail($t['id'])['lines'][0]['suggestedToBin'];
        $this->assertNotNull($suggested);
        $this->assertStringContainsString('free', $suggested['reasonEn']);
        $this->assertTrue($this->engine()->locCheck(self::$fx['product']->id, $this->engine()->bin(self::$fx['jed']->id, $suggested['code'])->id)['ok']);

        // no bin at creation and none now → refused; the bin named at receipt is checked at receipt
        $none = $this->expectRejected($this->receive($t['id']), 'TO_BIN_REQUIRED', [422]);
        $this->assertSame(['line' => 1], $none['details']);
        $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 20]]), 'TO_BIN_REQUIRED', [422]);
        $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 20, 'toBin' => 'STG-IN']]), 'LOC_RULE', [422]);
        $blocked = $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 20, 'toBin' => self::$fx['j3']->code]]), 'LOC_RULE', [422]);
        $this->assertStringContainsString(self::$fx['j3']->code, $blocked['message']);
        $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 20, 'toBin' => self::$fx['r1']->code]]), 'BIN_NOT_FOUND', [404]); // a bin of the source warehouse
        $this->assertSame(['transit', 0], [WarehouseTransfer::findOrFail($t['id'])->status, InventoryMovement::where('reference_id', $t['id'])->where('type', 'trf')->count()], 'nothing landed');

        // first delivery: 8 of 20 into J1
        $first = $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 8, 'toBin' => self::$fx['j1']->code]]));
        $this->assertSame(['partial', true, 8, 12], [$first['status'], $first['partial'], $first['receivedNow'], $first['remainingQty']]);
        $this->assertSame([self::$fx['j1']->code, 8, 12], [$first['lines'][0]['toBin']['code'], $first['lines'][0]['receivedQty'], $first['lines'][0]['remainingQty']]);
        $this->assertSame([8, 1], [$this->onHand('j1'), $this->openExceptions($t['number'])]);
        $again = $this->detail($t['id'])['lines'][0]['suggestedToBin'];
        $this->assertSame(self::$fx['j1']->code, $again['code'], 'now the product has a home in JED: the bin that already holds it');

        // second delivery: 5 more, into another bin (codes are case-insensitive) — still short, still ONE exception
        $second = $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 5, 'toBin' => strtolower(self::$fx['j2']->code)]]));
        $this->assertSame(['partial', 13, 7, $first['exception']['number']], [$second['status'], $second['lines'][0]['receivedQty'], $second['remainingQty'], $second['exception']['number']]);
        $this->assertSame([8, 5, 1], [$this->onHand('j1'), $this->onHand('j2'), $this->openExceptions($t['number'])]);
        $this->assertSame(self::$fx['j2']->code, $second['lines'][0]['toBin']['code']);

        // nothing new / more than what is left
        $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 0]]), 'RECEIVE_NOTHING', [422]);
        $over = $this->expectRejected($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 8]]), 'RECEIVE_EXCEEDS', [400]);
        $this->assertSame(['line' => 1, 'remaining' => 7, 'shipped' => 20], $over['details']);
        $this->assertSame(70.0, $this->inTransitNet($t['id']));

        // the rest arrives: no body = everything still on the road, into the bin last used
        $last = $this->expectOk($this->receive($t['id']));
        $this->assertSame(['received', false, null, 7, 0], [$last['status'], $last['partial'], $last['exception'], $last['receivedNow'], $last['remainingQty']]);
        $this->assertSame([8, 12, 0], [$this->onHand('j1'), $this->onHand('j2'), $this->openExceptions($t['number'])]);
        $this->assertSame('resolved', OpsException::where('number', $first['exception']['number'])->firstOrFail()->status, 'the shortfall arrived: its exception is closed');
        $this->assertSame(0.0, $this->inTransitNet($t['id']));

        $full = $this->detail($t['id']);
        $this->assertSame(['transit', 'partial', 'partial', 'received'], array_slice(array_column($full['history'], 'toStatus'), -4));
        $this->assertSame(['transit', 'trf', 'trf', 'trf'], array_column($full['movements'], 'type'));
        $this->assertSame([20, 20, 0, 0, ['done']], [$full['totalQty'], $full['receivedQty'], $full['varianceQty'], $full['inTransitQty'], $full['allowed']]);
        $this->assertSame('done', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/close"))['status']);
        $this->assertReconciled();
    }

    public function test_receipts_are_valued_at_the_ship_cost_whatever_the_average_became(): void
    {
        $tag = 'V'.self::uid();
        $p = $this->makeProduct($tag);
        $this->stock($p['product'], $p['batch'], self::$fx['ryd'], self::$fx['r2'], 50, "OPEN-{$tag}"); // cost 10
        $t = $this->shipped(10, self::$fx['j1']->code, $p['product'], $p['batch'], self::$fx['r2']);
        $this->assertSame(100.0, $this->inTransitNet($t['id']));

        // a dearer receipt re-averages the product while the goods are on the road
        $this->stockAtCost($p['product'], $p['batch'], self::$fx['ryd'], self::$fx['r2'], 40, 30.0, "OPEN2-{$tag}");
        $average = (float) $p['product']->fresh()->avg_cost;
        $this->assertEqualsWithDelta(20.0, $average, 0.0001); // (40 × 10 + 40 × 30) / 80

        $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 4]]));
        $this->assertSame(60.0, $this->inTransitNet($t['id']));
        $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 6]]));
        $arrivals = InventoryMovement::where('reference_id', $t['id'])->where('type', 'trf')->orderBy('number')->get();
        $this->assertSame([[4, 10.0, 40.0], [6, 10.0, 60.0]], $arrivals->map(fn ($m) => [$m->qty, (float) $m->unit_cost, (float) $m->total_cost])->all(), 'valued at the cost they left at, not at the new average');
        $this->assertSame(0.0, $this->inTransitNet($t['id']), 'so the in-transit account clears exactly');
        $this->assertFalse(Journal::where('event_key', "trf-clear:{$t['id']}")->exists(), 'nothing was left to clear');
        // the 10 units are back in stock at the 10 they left at while the average had become 20: they are blended in, so
        // stock on hand × average keeps equalling the inventory account (the finance reconciliation invariant)
        $onHand = (int) InventoryBalance::where('product_id', $p['product']->id)->sum('on_hand');
        $this->assertEqualsWithDelta((($onHand - 10) * $average + 100.0) / $onHand, (float) $p['product']->fresh()->avg_cost, 0.001, 'the arrival is blended into the average at its own cost');
        $this->assertNotEquals($average, (float) $p['product']->fresh()->avg_cost);
        $this->assertSame(10, $this->onHand('j1', $p['product'], $p['batch']));
        $this->assertReconciled();
    }

    public function test_rounding_left_by_several_receipts_is_cleared_when_the_transfer_completes(): void
    {
        $tag = 'Z'.self::uid();
        $p = $this->makeProduct($tag);
        $this->stockAtCost($p['product'], $p['batch'], self::$fx['ryd'], self::$fx['r2'], 3, 0.3333, "OPEN-{$tag}");
        $t = $this->shipped(3, self::$fx['j1']->code, $p['product'], $p['batch'], self::$fx['r2']);
        $this->assertSame(1.0, $this->inTransitNet($t['id'])); // 3 × 0.3333 = 0.9999 → 1.00

        foreach ([1, 1] as $one) {
            $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => $one]]));
        }
        $this->assertSame(0.34, $this->inTransitNet($t['id'])); // two receipts of 0.33
        $this->assertSame('received', $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 1]]))['status']);
        $clearing = Journal::with('lines.account')->where('event_key', "trf-clear:{$t['id']}")->firstOrFail();
        $this->assertSame([0.01, $t['number']], [(float) $clearing->total_debit, $clearing->source_number]);
        $this->assertSame(['inventory' => 0.01, 'inventory_in_transit' => -0.01], $clearing->lines->mapWithKeys(fn ($l) => [$l->account->role => round((float) $l->debit - (float) $l->credit, 2)])->all());
        $this->assertSame(0.0, $this->inTransitNet($t['id']));
        $this->assertReconciled();
    }

    public function test_variance_returned_puts_the_remainder_back_in_the_source_warehouse(): void
    {
        $t = $this->shipped(10, self::$fx['j1']->code);
        $r1 = $this->onHand('r1');
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'عادت مع السائق']), 'TRANSFER_NO_VARIANCE', [422]); // still in transit: receive first
        $short = $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 6]]));
        $this->assertSame(['partial', 4], [$short['status'], $short['remainingQty']]);

        // input
        $this->expectRejected($this->resolve($t['id'], []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'gift', 'note' => 'هدية']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'عادت', 'lines' => [['lineNo' => 9, 'toBin' => self::$fx['r2']->code]]]), 'BAD_LINE', [400]);
        // the bin the goods come back to is a storage bin of the SOURCE warehouse
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'عادت', 'lines' => [['lineNo' => 1, 'toBin' => 'STG-IN']]]), 'LOC_RULE', [422]);
        $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'عادت', 'lines' => [['lineNo' => 1, 'toBin' => self::$fx['j1']->code]]]), 'BIN_NOT_FOUND', [404]);
        $this->assertSame(['partial', $r1], [WarehouseTransfer::findOrFail($t['id'])->status, $this->onHand('r1')]);

        $done = $this->expectOk($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'عادت مع السائق']));
        $this->assertSame(['received', 'returned', 4, null], [$done['status'], $done['resolution'], $done['varianceQty'], $done['journal']]);
        $this->assertSame([6, 4, 0], [$done['lines'][0]['receivedQty'], $done['lines'][0]['varianceQty'], $done['lines'][0]['remainingQty']]);
        $this->assertSame($r1 + 4, $this->onHand('r1'), 'back in the bin it left');
        $back = InventoryMovement::where('reference_id', $t['id'])->where('type', 'trf')->orderByDesc('number')->firstOrFail();
        $this->assertSame([4, self::$fx['r1']->id, self::$fx['ryd']->id, 10.0], [$back->qty, $back->dst_bin_id, $back->dst_warehouse_id, (float) $back->unit_cost]);
        $this->assertSame([0.0, 0], [$this->inTransitNet($t['id']), $this->openExceptions($t['number'])]);
        $detail = $this->detail($t['id']);
        $this->assertSame(['returned', 'عادت مع السائق', null, 4, 0], [$detail['variance']['resolution'], $detail['variance']['note'], $detail['variance']['journal'], $detail['variance']['qty'], $detail['variance']['openQty']]);
        $this->assertStringContainsString('أُعيد إلى المصدر', end($detail['history'])['note']);
        $this->assertSame('done', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/close"))['status']);
        $this->assertReconciled();
    }

    public function test_nothing_arrived_and_the_goods_come_back_to_another_bin(): void
    {
        $t = $this->shipped(5, self::$fx['j1']->code);
        $j1 = $this->onHand('j1');
        // the truck came back full: a receipt of zero records it and opens the variance
        $zero = $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 0]]));
        $this->assertSame(['partial', 0, 5, 'transfer'], [$zero['status'], $zero['receivedNow'], $zero['remainingQty'], $zero['exception']['kind']]);

        // the bin it left is not usable any more → the refusal says so, and another bin is named
        self::$fx['r1']->update(['status' => 'blocked']);
        try {
            $this->expectRejected($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'رجعت الشاحنة']), 'LOC_RULE', [422]);
            $done = $this->expectOk($this->resolve($t['id'], ['resolution' => 'returned', 'note' => 'رجعت الشاحنة', 'lines' => [['lineNo' => 1, 'toBin' => self::$fx['r2']->code]]]));
        } finally {
            self::$fx['r1']->update(['status' => 'active']);
        }
        $this->assertSame(['received', 5, 0], [$done['status'], $done['varianceQty'], $done['lines'][0]['receivedQty']]);
        $this->assertSame([5, $j1], [$this->onHand('r2'), $this->onHand('j1')]);
        $this->assertSame([0.0, 0], [$this->inTransitNet($t['id']), $this->openExceptions($t['number'])]);
        $this->assertReconciled();
    }

    public function test_goods_that_carry_no_cost_are_written_off_without_a_journal(): void
    {
        $tag = 'U'.self::uid();
        $product = Product::create(['sku' => "TST-{$tag}", 'name_ar' => "منتج بلا تكلفة {$tag}", 'name_en' => "Unvalued product {$tag}", 'storage_class' => 'ambient', 'weight_kg' => 1, 'tracks_expiry' => true]);
        $batch = Batch::create(['product_id' => $product->id, 'batch_no' => "TB-{$tag}", 'expiry_date' => now()->startOfDay()->addDays(200)]);
        $this->stock($product, $batch, self::$fx['ryd'], self::$fx['r2'], 6, "OPEN-{$tag}");
        $t = $this->shipped(6, self::$fx['j1']->code, $product, $batch, self::$fx['r2']);
        $this->assertSame(0.0, (float) TransferLine::where('transfer_id', $t['id'])->firstOrFail()->unit_cost);
        $this->expectOk($this->receive($t['id'], [['lineNo' => 1, 'receivedQty' => 2]]));

        $done = $this->expectOk($this->resolve($t['id'], ['resolution' => 'loss', 'note' => 'فُقدت في الطريق']));
        $this->assertSame(['received', 'loss', 4, null], [$done['status'], $done['resolution'], $done['varianceQty'], $done['journal']]);
        $this->assertNull(WarehouseTransfer::findOrFail($t['id'])->variance_journal_id);
        $this->assertSame([0, 0.0], [Journal::where('source_type', 'WarehouseTransfer')->where('source_id', $t['id'])->count(), $this->inTransitNet($t['id'])]);
        $this->assertSame('done', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/close"))['status']);
        $this->assertReconciled();
    }
}
