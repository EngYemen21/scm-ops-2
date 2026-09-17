<?php

namespace Tests\Feature\Inventory;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use Tests\ApiTestCase;

/** Port of inventory-query.spec.ts over HTTP: balances, adjustments, moves, quarantine flags and the read views. */
class InventoryOpsTest extends ApiTestCase
{
    use InventoryFixtures;

    /** @var array<string,mixed>|null sequential story: 50 units of one batch in bin B1 of a private RYD zone */
    private static ?array $fx = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$fx === null) {
            $tag = self::uid();
            $zone = $this->makeZone('RYD', "T{$tag}", ["T{$tag}-B1", "T{$tag}-B2", "T{$tag}-B3"]);
            $p = $this->makeProduct($tag);
            $this->stock($p['product'], $p['batch'], $zone['wh'], $zone['bins']["T{$tag}-B1"], 50, "OPEN-{$tag}");
            self::$fx = ['tag' => $tag, 'sku' => $p['product']->sku, 'pid' => $p['product']->id, 'batchNo' => $p['batch']->batch_no, 'batchId' => $p['batch']->id,
                'zone' => $zone['zone']->code, 'b1' => $zone['bins']["T{$tag}-B1"], 'b2' => $zone['bins']["T{$tag}-B2"], 'b3' => $zone['bins']["T{$tag}-B3"]];
        }
    }

    private function adjustBody(int $delta, string $reason = 'test', array $over = []): array
    {
        return $over + ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['b1']->code, 'batchNo' => self::$fx['batchNo'], 'qtyDelta' => $delta, 'reason' => $reason];
    }

    private function moveBody(int $qty, array $over = []): array
    {
        return $over + ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'fromBin' => self::$fx['b1']->code, 'toBin' => self::$fx['b2']->code, 'batchNo' => self::$fx['batchNo'], 'qty' => $qty, 'reason' => 'slot'];
    }

    private function onHand(string $bin): ?int
    {
        return $this->balance(self::$fx['pid'], self::$fx[$bin]->id, self::$fx['batchId'])?->on_hand;
    }

    public function test_rejects_moving_a_frozen_product_into_an_ambient_bin(): void
    {
        $tag = 'F'.self::uid();
        $fz = $this->makeZone('RYD', "T{$tag}", ["T{$tag}-F1"], 'frozen');
        $p = $this->makeProduct($tag, 'frozen');
        $this->stock($p['product'], $p['batch'], $fz['wh'], $fz['bins']["T{$tag}-F1"], 5, "OPEN-{$tag}");

        $res = $this->postAs('wm', '/api/inventory/move', ['sku' => $p['product']->sku, 'warehouseCode' => 'RYD', 'fromBin' => "T{$tag}-F1", 'toBin' => self::$fx['b3']->code, 'batchNo' => $p['batch']->batch_no, 'qty' => 1, 'reason' => 'slot']);
        $body = $this->expectRejected($res, 'LOC_RULE', [422]);
        $this->assertSame('BUSINESS_RULE', $body['category']);
        $this->assertSame(5, $this->balance($p['product']->id, $fz['bins']["T{$tag}-F1"]->id, $p['batch']->id)->on_hand);
        $this->assertNull($this->balance($p['product']->id, self::$fx['b3']->id, $p['batch']->id));
        $this->assertReconciled();
    }

    public function test_adjustments_never_go_negative_and_write_ledger_audit_and_activity(): void
    {
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(-51)), 'INSUFFICIENT_STOCK', [422]);
        $this->assertSame(50, $this->onHand('b1'));

        $res = $this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(-5, 'test shrink'));
        $res->assertStatus(201);
        $mv = $res->json();
        $this->assertSame(['adj', 50, 45, 5], [$mv['type'], $mv['beforeQty'], $mv['afterQty'], $mv['qty']]);
        $this->assertSame(self::$fx['b1']->id, $mv['srcBinId']);
        $this->assertNull($mv['dstBinId']);
        $this->assertSame('Adjustment', $mv['referenceType']);
        $this->assertSame('wm', $mv['username']);
        $this->assertStringStartsWith('TX-', $mv['number']);
        $audit = AuditLog::where('action', 'INVENTORY.ADJUST')->where('entity_number', $mv['number'])->firstOrFail();
        $this->assertSame($mv['transactionId'], $audit->transaction_id);
        $this->assertSame('50', $audit->old_value);
        $this->assertTrue(ActivityLog::where('entity_number', $mv['number'])->exists());

        // numeric strings are coerced like z.coerce.number()
        $back = $this->expectOk($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(5, 'test found', ['qtyDelta' => '5'])));
        $this->assertSame(50, $back['afterQty']);
        $this->assertSame(self::$fx['b1']->id, $back['dstBinId']);
        $this->assertReconciled();
    }

    public function test_adjust_validation_and_lookups(): void
    {
        $body = $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(0)), 'INVALID_INPUT', [400]);
        $this->assertSame('qtyDelta', $body['details'][0]['path']);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', ['sku' => self::$fx['sku']]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(1, 'x', ['sku' => 'NOPE-'.self::uid()])), 'PRODUCT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(1, 'x', ['warehouseCode' => 'XXX'])), 'WAREHOUSE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(1, 'x', ['binCode' => 'NO-BIN'])), 'BIN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/adjust', $this->adjustBody(1, 'x', ['batchNo' => 'NO-BATCH'])), 'BATCH_NOT_FOUND', [404]);
        $this->assertSame(50, $this->onHand('b1'));
    }

    public function test_moves_bin_to_bin_within_available_with_one_ledger_row(): void
    {
        $res = $this->postAs('wm', '/api/inventory/move', $this->moveBody(10));
        $res->assertStatus(201);
        $mv = $res->json();
        $this->assertSame(['move', self::$fx['b1']->id, self::$fx['b2']->id], [$mv['type'], $mv['srcBinId'], $mv['dstBinId']]);
        $this->assertSame([40, 10], [$this->onHand('b1'), $this->onHand('b2')]);

        $rejected = $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(41)), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['available' => 40, 'requested' => 41], $rejected['details']);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(1, ['toBin' => strtolower(self::$fx['b1']->code)])), 'SAME_BIN', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(1, ['fromBin' => self::$fx['b3']->code])), 'NO_STOCK', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(1, ['toBin' => 'STG-IN'])), 'LOC_RULE', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(1, ['reason' => 'teleport'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(0)), 'INVALID_INPUT', [400]);
        $this->assertSame([40, 10], [$this->onHand('b1'), $this->onHand('b2')]);

        $led = $this->expectOk($this->getAs('wm', '/api/inventory/ledger?pageSize=10&type=move&sku='.self::$fx['sku']));
        $this->assertSame(1, $led['total']);
        $this->assertSame($mv['number'], $led['items'][0]['number']);
        $this->assertSame(self::$fx['b1']->code, $led['items'][0]['src']['bin']);
        $this->assertSame(self::$fx['b2']->code, $led['items'][0]['dst']['bin']);
        $this->assertSame('RYD', $led['items'][0]['dst']['warehouse']);
        $this->assertSame(10, $led['items'][0]['signedQty']);
        $this->assertSame(self::$fx['sku'], $led['items'][0]['product']['sku']);
        $this->assertSame(['id', 'code', 'zone', 'warehouse'], array_keys($led['items'][0]['srcBin']));
        $this->assertReconciled();
    }

    public function test_quarantine_is_a_flag_change_without_a_movement(): void
    {
        $sku = self::$fx['sku'];
        $body = ['sku' => $sku, 'warehouseCode' => 'RYD', 'binCode' => self::$fx['b2']->code, 'batchNo' => self::$fx['batchNo'], 'quarantine' => true, 'reason' => 'test hold'];
        $before = InventoryMovement::where('product_id', self::$fx['pid'])->count();

        $r = $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', $body));
        $this->assertTrue($r['quarantine']);
        $this->assertSame([$sku, 'RYD', self::$fx['b2']->code, self::$fx['batchNo'], 10], [$r['sku'], $r['warehouse'], $r['bin'], $r['batch'], $r['onHand']]);
        $this->assertSame($before, InventoryMovement::where('product_id', self::$fx['pid'])->count(), 'a flag change writes no ledger row');
        $this->assertTrue(AuditLog::where('action', 'INVENTORY.QUARANTINE')->where('entity_id', $r['rowId'])->exists());

        $rows = $this->expectOk($this->getAs('wm', "/api/inventory/balances?pageSize=10&sku={$sku}"));
        $q = collect($rows['items'])->firstWhere('bin', self::$fx['b2']->code);
        $this->assertSame(0, $q['available']);
        $this->assertContains('quarantine', $q['flags']);
        $this->assertSame('quarantine', $q['status']);
        $only = $this->expectOk($this->getAs('wm', "/api/inventory/balances?sku={$sku}&status=quarantine"));
        $this->assertSame([self::$fx['b2']->code], array_column($only['items'], 'bin'));
        $available = $this->expectOk($this->getAs('wm', "/api/inventory/balances?sku={$sku}&status=available"));
        $this->assertSame([self::$fx['b1']->code], array_column($available['items'], 'bin'));
        $this->assertSame(40, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable']);

        $this->expectRejected($this->postAs('wm', '/api/inventory/quarantine', ['reason' => 'again'] + $body), 'QTN_NOOP', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(1, ['fromBin' => self::$fx['b2']->code, 'toBin' => self::$fx['b3']->code])), 'SOURCE_QUARANTINED', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/quarantine', ['binCode' => self::$fx['b3']->code] + $body), 'BALANCE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/quarantine', ['quarantine' => 'maybe'] + $body), 'INVALID_INPUT', [400]);

        $released = $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', ['quarantine' => false, 'reason' => 'released'] + $body));
        $this->assertFalse($released['quarantine']);
        $this->assertSame(50, $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"))['totalAvailable']);
        $rec = $this->expectOk($this->getAs('wm', '/api/inventory/reconciliation?warehouse=RYD'));
        $this->assertSame([], array_values(array_filter($rec['mismatches'], fn ($m) => $m['sku'] === $sku)));
        $this->assertReconciled();
    }

    public function test_quarantine_and_damage_moves_target_their_zones(): void
    {
        $sku = self::$fx['sku'];
        $this->expectRejected($this->postAs('wm', '/api/inventory/move', $this->moveBody(2, ['reason' => 'qtn', 'toBin' => self::$fx['b3']->code])), 'LOC_RULE', [422]);

        $mv = $this->expectOk($this->postAs('wm', '/api/inventory/move', $this->moveBody(2, ['reason' => 'qtn', 'toBin' => 'QTN-01'])));
        $qtn = $this->engine()->bin(self::$fx['b1']->warehouse_id, 'QTN-01');
        $this->assertSame($qtn->id, $mv['dstBinId']);
        $this->assertTrue($this->balance(self::$fx['pid'], $qtn->id, self::$fx['batchId'])->quarantine, 'a qtn move flags the destination row');

        $dmg = $this->expectOk($this->postAs('wm', '/api/inventory/move', $this->moveBody(3, ['reason' => 'damage', 'toBin' => 'DMG-01'])));
        $this->assertSame('move', $dmg['type']);
        $this->assertSame(35, $this->onHand('b1'));

        $stock = $this->expectOk($this->getAs('wm', "/api/inventory/products/{$sku}/stock"));
        $this->assertSame(45, $stock['totalAvailable'], 'quarantine and damaged zones are not sellable');
        $ryd = collect($stock['perWarehouse'])->firstWhere('code', 'RYD');
        $this->assertSame([50, 45, 2], [$ryd['onHand'], $ryd['available'], $ryd['quarantine']]);
        $this->assertSame($sku, $stock['product']['sku']);
        $this->assertCount(4, $stock['rows']);
        $damaged = collect($stock['rows'])->firstWhere('bin', 'DMG-01');
        $this->assertContains('damaged', $damaged['flags']);
        $this->assertSame(0, $damaged['available']);
        $this->assertReconciled();
    }

    public function test_read_views_summary_batches_trace_staging_and_ledger_entry(): void
    {
        $sku = self::$fx['sku'];
        $tag = self::$fx['tag'];

        $sum = $this->expectOk($this->getAs('admin', '/api/inventory/balances/summary'));
        $ryd = collect($sum['warehouses'])->firstWhere('code', 'RYD');
        $this->assertGreaterThanOrEqual(50, $ryd['onHand']);
        $this->assertTrue($sum['costVisible']);
        $this->assertIsNumeric($ryd['value']);
        $this->assertGreaterThan(0, $ryd['value']);
        $this->assertArrayHasKey('value', $sum['totals']);
        foreach (['warehouseId', 'reserved', 'available', 'quarantine', 'staging', 'damaged', 'returns', 'expired', 'rows', 'skus', 'inTransitOut', 'inTransitIn', 'inTransit'] as $key) {
            $this->assertArrayHasKey($key, $ryd);
        }
        $noCost = $this->expectOk($this->getAs('wm', '/api/inventory/balances/summary?warehouse=ryd'));
        $this->assertFalse($noCost['costVisible']);
        $this->assertCount(1, $noCost['warehouses']);
        $this->assertArrayNotHasKey('value', $noCost['warehouses'][0], 'cost is hidden without inventory.view_cost');
        $this->assertArrayNotHasKey('value', $noCost['totals']);
        $this->assertSame($ryd['onHand'], $noCost['warehouses'][0]['onHand']);

        $b = $this->expectOk($this->getAs('wm', "/api/inventory/batches?pageSize=10&sku={$sku}"));
        $this->assertSame(1, $b['total']);
        $this->assertSame([self::$fx['batchNo'], 50, 'ok', 2], [$b['items'][0]['batchNo'], $b['items'][0]['onHand'], $b['items'][0]['status'], $b['items'][0]['quarantine']]);
        $this->assertSame(50, $b['items'][0]['perWarehouse']['RYD']['onHand']);
        $this->assertContains(self::$fx['b1']->code, $b['items'][0]['perWarehouse']['RYD']['bins']);
        $this->assertSame(200, $b['items'][0]['daysLeft']);
        $this->assertSame(1, $this->expectOk($this->getAs('wm', "/api/inventory/batches?sku={$sku}&status=quarantine&warehouse=RYD"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/inventory/batches?sku={$sku}&status=expired"))['total']);
        $this->expectRejected($this->getAs('wm', '/api/inventory/batches?status=rotten'), 'INVALID_INPUT', [400]);

        $t = $this->expectOk($this->getAs('wm', "/api/inventory/trace/OPEN-{$tag}"));
        $this->assertSame([1, 50, 0, 'OpeningBalance'], [$t['count'], $t['inQty'], $t['outQty'], $t['referenceType']]);
        $this->assertSame(['opening' => 50], $t['byType']);
        $this->assertSame($sku, $t['products'][0]['sku']);
        $entry = $this->expectOk($this->getAs('wm', '/api/inventory/ledger/'.$t['movements'][0]['number']));
        $this->assertSame(self::$fx['b1']->code, $entry['dst']['bin']);
        $this->assertNull($entry['src']);
        $this->assertSame([], $entry['sameTransaction']);
        $this->expectRejected($this->getAs('wm', '/api/inventory/ledger/TX-NOPE'), 'MOVEMENT_NOT_FOUND', [404]);
        $empty = $this->expectOk($this->getAs('wm', '/api/inventory/trace/NOPE-'.$tag));
        $this->assertSame([0, null, []], [$empty['count'], $empty['referenceType'], $empty['movements']]);

        $stg = $this->expectOk($this->getAs('wm', '/api/inventory/staging?warehouse=RYD'));
        $this->assertIsArray($stg['items']);
        $this->assertIsArray($stg['entries']);

        $led = $this->expectOk($this->getAs('wm', '/api/inventory/ledger?pageSize=5&warehouse=RYD&bin='.self::$fx['b2']->code));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($led));
        $this->assertGreaterThan(0, count($led['items']));
        $today = now()->toDateString();
        $this->assertGreaterThan(0, $this->expectOk($this->getAs('wm', "/api/inventory/ledger?sku={$sku}&from={$today}&to={$today}&user=wm"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/inventory/ledger?sku={$sku}&to=2000-01-01"))['total']);

        $byBin = $this->expectOk($this->getAs('wm', '/api/inventory/balances?sort=onHand&order=desc&zone='.self::$fx['zone'].'&warehouse=RYD&storageClass=ambient'));
        $this->assertSame([35, 10], array_column($byBin['items'], 'onHand'));
        $this->assertSame(1, $this->expectOk($this->getAs('wm', '/api/inventory/balances?q='.self::$fx['b2']->code))['total']);
        $this->expectRejected($this->getAs('wm', '/api/inventory/balances?status=bogus'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('wm', '/api/inventory/products/NOPE-'.$tag.'/stock'), 'PRODUCT_NOT_FOUND', [404]);
    }

    public function test_reconciliation_needs_audit_view_or_inventory_adjust(): void
    {
        $rec = $this->expectOk($this->getAs('inv', '/api/inventory/reconciliation'));
        $this->assertTrue($rec['ok']);
        $this->assertSame('ALL', $rec['warehouse']);
        $this->assertGreaterThan(0, $rec['checked']);
        $this->assertSame('JED', $this->expectOk($this->getAs('gm', '/api/inventory/reconciliation?warehouse=jed'))['warehouse']);
        $this->expectRejected($this->getAs('sales', '/api/inventory/reconciliation'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('admin', '/api/inventory/reconciliation?warehouse=XXX'), 'WAREHOUSE_NOT_FOUND', [404]);
    }

    public function test_forbidden_operations_change_nothing(): void
    {
        $movements = InventoryMovement::count();
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        foreach (['sales', 'driver'] as $user) {
            $this->expectRejected($this->postAs($user, '/api/inventory/adjust', $this->adjustBody(5, 'x')), 'FORBIDDEN', [403]);
        }
        $this->expectRejected($this->postAs('inv', '/api/inventory/move', $this->moveBody(1)), 'FORBIDDEN', [403]); // inv may adjust but not move
        $this->expectRejected($this->postAs('worker', '/api/inventory/quarantine', ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['b1']->code, 'batchNo' => self::$fx['batchNo'], 'quarantine' => true, 'reason' => 'x']), 'FORBIDDEN', [403]);
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(35, $this->onHand('b1'));
        $this->assertFalse($this->balance(self::$fx['pid'], self::$fx['b1']->id, self::$fx['batchId'])->quarantine);
        $this->assertSame($denied + 4, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
        $this->assertSame(401, $this->getJson('/api/inventory/balances')->getStatusCode(), 'reads still need a session');
    }
}
