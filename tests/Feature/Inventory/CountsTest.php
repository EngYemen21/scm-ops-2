<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryCount;
use App\Models\InventoryMovement;
use Tests\ApiTestCase;

/** Cycle counts over HTTP: schedule → start (freeze) → enter → complete (variance) → approve (adjust) → close. */
class CountsTest extends ApiTestCase
{
    use InventoryFixtures;

    /** @var array<string,mixed>|null sequential story: 40 units in bin B1 and 10 in B2 of a private RYD zone, empty zone in JED */
    private static ?array $fx = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$fx === null) {
            $tag = self::uid();
            $zone = $this->makeZone('RYD', "T{$tag}", ["T{$tag}-B1", "T{$tag}-B2"]);
            $jed = $this->makeZone('JED', "T{$tag}", ["T{$tag}-J1"]);
            $p = $this->makeProduct($tag);
            $this->stock($p['product'], $p['batch'], $zone['wh'], $zone['bins']["T{$tag}-B1"], 40, "OPEN-{$tag}");
            $this->stock($p['product'], $p['batch'], $zone['wh'], $zone['bins']["T{$tag}-B2"], 10, "OPEN-{$tag}");
            self::$fx = ['tag' => $tag, 'sku' => $p['product']->sku, 'pid' => $p['product']->id, 'batchNo' => $p['batch']->batch_no, 'batchId' => $p['batch']->id,
                'zone' => $zone['zone']->code, 'b1' => $zone['bins']["T{$tag}-B1"], 'b2' => $zone['bins']["T{$tag}-B2"], 'j1' => $jed['bins']["T{$tag}-J1"]];
        }
    }

    private function schedule(array $over = [], string $user = 'wm'): array
    {
        $res = $this->postAs($user, '/api/inventory/counts', $over + ['warehouseCode' => 'RYD', 'zoneCode' => self::$fx['zone'], 'type' => 'cycle', 'scope' => 'zone', 'date' => now()->toDateString()]);
        $res->assertStatus(201);

        return $res->json();
    }

    private function row(string $bin)
    {
        return $this->balance(self::$fx['pid'], self::$fx[$bin]->id, self::$fx['batchId']);
    }

    private function totalAvailable(): int
    {
        return $this->expectOk($this->getAs('wm', '/api/inventory/products/'.self::$fx['sku'].'/stock'))['totalAvailable'];
    }

    public function test_full_cycle_count_freezes_rows_posts_adjustments_and_reconciles(): void
    {
        $c = $this->schedule(['counterUsername' => 'worker']); // blind + freeze default to true
        $this->assertSame(['open', true, true, 'zone', 'cycle'], [$c['status'], $c['blind'], $c['freeze'], $c['scope'], $c['type']]);
        $this->assertStringStartsWith('CNT-', $c['number']);
        $this->assertCount(2, $c['lines']);
        $this->assertSame([self::$fx['b1']->code, self::$fx['b2']->code], array_map(fn ($l) => $l['bin']['code'], $c['lines']), 'lines are sorted by bin');
        $this->assertSame(['total' => 2, 'counted' => 0, 'variances' => 0], $c['progress']);
        $this->assertSame(['counting'], $c['allowed']);
        $this->assertSame(self::$fx['zone'], $c['zone']['code']);
        $this->assertNotNull($c['counter']);

        // blind: a counter without inventory.adjust never sees systemQty before completion
        $counterView = $this->expectOk($this->getAs('worker', "/api/inventory/counts/{$c['id']}"));
        foreach ($counterView['lines'] as $l) {
            $this->assertNull($l['systemQty']);
            $this->assertTrue($l['blind']);
            $this->assertArrayNotHasKey('variance', $l);
        }
        $this->assertArrayNotHasKey('variances', $counterView['progress']);
        $this->assertSame([40, 10], array_column($this->expectOk($this->getAs('admin', "/api/inventory/counts/{$c['number']}"))['lines'], 'systemQty'));
        $listed = collect($this->expectOk($this->getAs('worker', '/api/inventory/counts?status=open,counting&warehouse=RYD&type=cycle&q='.$c['number']))['items']);
        $this->assertSame([null, null], array_column($listed->firstWhere('id', $c['id'])['lines'], 'systemQty'));

        // stock moves between scheduling and start: start re-snapshots systemQty, then freezes
        $this->expectOk($this->postAs('wm', '/api/inventory/adjust', ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['b1']->code, 'batchNo' => self::$fx['batchNo'], 'qtyDelta' => 2, 'reason' => 'قبل الجرد']));
        $this->assertSame(52, $this->totalAvailable());

        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", ['lines' => [['lineId' => $c['lines'][0]['id'], 'countedQty' => 1]]]), 'COUNT_NOT_COUNTING', [422]);
        $started = $this->expectOk($this->postAs('worker', "/api/inventory/counts/{$c['id']}/start"));
        $this->assertSame('counting', $started['status']);
        $this->assertTrue($this->row('b1')->blocked);
        $this->assertTrue($this->row('b2')->blocked);
        $this->assertSame([42, 10], array_column($this->expectOk($this->getAs('wm', "/api/inventory/counts/{$c['id']}"))['lines'], 'systemQty'));

        // frozen rows cannot be allocated, moved, adjusted or transferred
        $this->assertSame(0, $this->totalAvailable());
        $this->assertCount(0, $this->engine()->allocRows(self::$fx['pid'], self::$fx['b1']->warehouse_id));
        $move = ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'fromBin' => self::$fx['b1']->code, 'toBin' => self::$fx['b2']->code, 'batchNo' => self::$fx['batchNo'], 'qty' => 1, 'reason' => 'slot'];
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $move), 'ROW_BLOCKED', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['b1']->code, 'batchNo' => self::$fx['batchNo'], 'qtyDelta' => -1, 'reason' => 'x']), 'ROW_BLOCKED', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', ['fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(),
            'lines' => [['sku' => self::$fx['sku'], 'qty' => 1, 'batchNo' => self::$fx['batchNo'], 'fromBin' => self::$fx['b1']->code, 'toBin' => self::$fx['j1']->code]]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $blocked = collect($this->expectOk($this->getAs('wm', '/api/inventory/balances?sku='.self::$fx['sku']))['items'])->firstWhere('bin', self::$fx['b1']->code);
        $this->assertTrue($blocked['blocked']);
        $this->assertContains('blocked', $blocked['flags']);
        $this->assertSame(0, $blocked['available']);

        // entering: only lines of this count, only the assigned counter (or someone holding inventory.adjust)
        [$l1, $l2] = $started['lines'];
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", ['lines' => [['lineId' => 'NOPE', 'countedQty' => 1]]]), 'BAD_LINE', [400]);
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", ['lines' => []]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", ['lines' => [['lineId' => $l1['id'], 'countedQty' => -1]]]), 'INVALID_INPUT', [400]);
        $partial = $this->expectOk($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", ['lines' => [['lineId' => $l1['id'], 'countedQty' => 38]]]));
        $this->assertSame(1, $partial['progress']['counted']);
        $incomplete = $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/complete"), 'COUNT_INCOMPLETE', [422]);
        $this->assertSame(['missing' => [$l2['id']]], $incomplete['details']);
        $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/enter", ['lines' => [['lineId' => $l2['id'], 'countedQty' => '10']]]));

        $completed = $this->expectOk($this->postAs('worker', "/api/inventory/counts/{$c['id']}/complete"));
        $this->assertSame('variance', $completed['status']);
        $this->assertSame(1, $completed['progress']['variances']);
        $this->assertSame([-4, 0], array_column($completed['lines'], 'variance'), 'system quantities are revealed once counting is over');
        $this->assertTrue($this->row('b1')->blocked, 'rows stay frozen until the variance is approved');

        // approving needs inventory.adjust
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/approve"), 'FORBIDDEN', [403]);
        $this->assertSame('variance', InventoryCount::findOrFail($c['id'])->status);
        $this->assertSame(42, $this->row('b1')->on_hand);

        $approved = $this->expectOk($this->postAs('inv', "/api/inventory/counts/{$c['id']}/approve"));
        $this->assertSame('adjusted', $approved['status']);
        $this->assertCount(1, $approved['adjustments']);
        $this->assertSame([self::$fx['sku'], self::$fx['b1']->code, 42, 38], [$approved['adjustments'][0]['sku'], $approved['adjustments'][0]['bin'], $approved['adjustments'][0]['from'], $approved['adjustments'][0]['to']]);
        $this->assertNotNull($approved['adjustedAt']);
        $this->assertSame([38, false], [$this->row('b1')->on_hand, $this->row('b1')->blocked]);
        $this->assertFalse($this->row('b2')->blocked);
        $adj = InventoryMovement::where('reference_type', 'InventoryCount')->where('reference_id', $c['id'])->get();
        $this->assertCount(1, $adj);
        $this->assertSame(['adj', 4, self::$fx['b1']->id, $approved['adjustments'][0]['movement']], [$adj[0]->type, $adj[0]->qty, $adj[0]->src_bin_id, $adj[0]->number]);
        $this->assertTrue(AuditLog::where('action', 'COUNT.ADJUST')->where('transaction_id', $adj[0]->transaction_id)->exists());
        $this->assertSame(48, $this->totalAvailable(), 'unfrozen stock is sellable again');
        $rec = $this->expectOk($this->getAs('inv', '/api/inventory/reconciliation?warehouse=RYD'));
        $this->assertSame([], array_values(array_filter($rec['mismatches'], fn ($m) => $m['sku'] === self::$fx['sku'])));

        $this->assertSame('closed', $this->expectOk($this->postAs('worker', "/api/inventory/counts/{$c['id']}/close", ['note' => 'أُقفل']))['status']);
        $this->expectRejected($this->postAs('inv', "/api/inventory/counts/{$c['id']}/approve"), 'COUNT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/start"), 'COUNT_TRANSITION', [422]);
        $detail = $this->expectOk($this->getAs('sales', "/api/inventory/counts/{$c['number']}"));
        $this->assertSame(['open', 'counting', 'variance', 'adjusted', 'closed'], array_column($detail['history'], 'toStatus'));
        $this->assertSame(['adj'], array_column($detail['movements'], 'type'));
        $this->assertSame([], $detail['allowed']);
        $this->assertReconciled();
    }

    public function test_open_count_without_freeze_and_a_found_surplus(): void
    {
        $c = $this->schedule(['blind' => false, 'freeze' => false, 'counterUsername' => 'wm']);
        $this->assertSame([false, false], [$c['blind'], $c['freeze']]);
        $this->assertSame([38, 10], array_column($this->expectOk($this->getAs('worker', "/api/inventory/counts/{$c['id']}"))['lines'], 'systemQty'), 'an open count shows system quantities');
        $started = $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/start"));
        $this->assertFalse($this->row('b1')->blocked);
        $this->assertSame(48, $this->totalAvailable());

        // assigned to wm: another counter without inventory.adjust is refused
        [$l1, $l2] = $started['lines'];
        $lines = ['lines' => [['lineId' => $l1['id'], 'countedQty' => 38], ['lineId' => $l2['id'], 'countedQty' => 13]]];
        $this->expectRejected($this->postAs('worker', "/api/inventory/counts/{$c['id']}/enter", $lines), 'COUNT_NOT_COUNTER', [422]);
        $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/enter", $lines));
        $this->expectRejected($this->postAs('wm', "/api/inventory/counts/{$c['id']}/close"), 'COUNT_TRANSITION', [422]);
        $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/complete"));
        $approved = $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/approve", ['note' => 'فائض موجود']));
        $this->assertSame([10, 13], [$approved['adjustments'][0]['from'], $approved['adjustments'][0]['to']]);
        $this->assertSame(13, $this->row('b2')->on_hand);
        $mv = InventoryMovement::where('reference_id', $c['id'])->firstOrFail();
        $this->assertSame([3, self::$fx['b2']->id, null], [$mv->qty, $mv->dst_bin_id, $mv->src_bin_id]);
        $this->expectOk($this->postAs('wm', "/api/inventory/counts/{$c['id']}/close"));

        // "neg" scope = bins that showed variances in earlier adjusted/closed counts of the warehouse
        $neg = $this->schedule(['scope' => 'neg', 'zoneCode' => null, 'freeze' => false]);
        $bins = array_map(fn ($l) => $l['bin']['code'], $neg['lines']);
        $this->assertContains(self::$fx['b1']->code, $bins);
        $this->assertContains(self::$fx['b2']->code, $bins);
        $this->assertNull($neg['zone']);
        $this->assertReconciled();
    }

    public function test_schedule_validation_rules_and_permissions(): void
    {
        $count = InventoryCount::count();
        $base = ['warehouseCode' => 'RYD', 'date' => now()->toDateString()];
        $noZone = $this->expectRejected($this->postAs('wm', '/api/inventory/counts', $base), 'INVALID_INPUT', [400]); // scope defaults to zone
        $this->assertSame('zoneCode', $noZone['details'][0]['path']);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', ['warehouseCode' => 'RYD', 'zoneCode' => self::$fx['zone']]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', $base + ['zoneCode' => self::$fx['zone'], 'type' => 'weekly']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', ['warehouseCode' => 'XXX'] + $base + ['zoneCode' => self::$fx['zone']]), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', $base + ['zoneCode' => 'NOPE']), 'ZONE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', $base + ['zoneCode' => self::$fx['zone'], 'counterUsername' => 'ghost']), 'USER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts', ['warehouseCode' => 'JED'] + $base + ['zoneCode' => self::$fx['zone']]), 'COUNT_EMPTY', [422]);
        $this->expectRejected($this->postAs('sales', '/api/inventory/counts', $base + ['zoneCode' => self::$fx['zone']]), 'FORBIDDEN', [403]);
        $this->assertSame($count, InventoryCount::count(), 'rejected requests schedule nothing');
        $this->expectRejected($this->getAs('wm', '/api/inventory/counts/CNT-NOPE'), 'COUNT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/counts/CNT-NOPE/start'), 'COUNT_NOT_FOUND', [404]);

        // type=abc overrides the scope; a frozen count that is never started blocks nothing
        $abc = $this->schedule(['type' => 'abc', 'scope' => 'all', 'zoneCode' => null]);
        $this->assertSame('abc', $abc['scope']);
        $this->assertGreaterThan(0, count($abc['lines']));
        $this->assertFalse($this->row('b1')->blocked);
        $list = $this->expectOk($this->getAs('wm', '/api/inventory/counts?type=abc&pageSize=5&q='.$abc['number']));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame($abc['number'], $list['items'][0]['number']);
    }
}
