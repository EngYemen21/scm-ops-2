<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Notification;
use App\Models\OpsException;
use App\Models\WarehouseTransfer;
use Tests\ApiTestCase;

/** Port of transfers.spec.ts over HTTP: RYD → JED transfers, in-transit stock, partial receipts, guards and RBAC. */
class TransfersTest extends ApiTestCase
{
    use InventoryFixtures;

    /** @var array<string,mixed>|null sequential story: 100 units in bin R1 (RYD), empty bin J1 (JED) */
    private static ?array $fx = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$fx === null) {
            $tag = self::uid();
            $src = $this->makeZone('RYD', "T{$tag}", ["T{$tag}-R1"]);
            $dst = $this->makeZone('JED', "T{$tag}", ["T{$tag}-J1"]);
            $p = $this->makeProduct($tag);
            $this->stock($p['product'], $p['batch'], $src['wh'], $src['bins']["T{$tag}-R1"], 100, "OPEN-{$tag}");
            self::$fx = ['tag' => $tag, 'sku' => $p['product']->sku, 'pid' => $p['product']->id, 'batchNo' => $p['batch']->batch_no, 'batchId' => $p['batch']->id,
                'r1' => $src['bins']["T{$tag}-R1"], 'j1' => $dst['bins']["T{$tag}-J1"], 'jedId' => $dst['wh']->id];
        }
    }

    private function line(int $qty, array $over = []): array
    {
        return $over + ['sku' => self::$fx['sku'], 'qty' => $qty, 'batchNo' => self::$fx['batchNo'], 'fromBin' => self::$fx['r1']->code, 'toBin' => self::$fx['j1']->code];
    }

    private function payload(array $lines, array $over = []): array
    {
        return $over + ['fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(), 'reasonCode' => 'shortage', 'submit' => true, 'lines' => $lines];
    }

    private function create(array $lines, array $over = []): array
    {
        $res = $this->postAs('wm', '/api/inventory/transfers', $this->payload($lines, $over));
        $res->assertStatus(201);

        return $res->json();
    }

    /** approve → start-picking → picking-done with body-less action buttons. */
    private function toReady(string $id): array
    {
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$id}/approve"));
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$id}/start-picking"));

        return $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$id}/picking-done"));
    }

    private function onHand(string $bin): ?int
    {
        return $this->balance(self::$fx['pid'], self::$fx[$bin]->id, self::$fx['batchId'])?->on_hand;
    }

    private function mismatches(string $warehouse): array
    {
        $rec = $this->expectOk($this->getAs('admin', "/api/inventory/reconciliation?warehouse={$warehouse}"));

        return array_values(array_filter($rec['mismatches'], fn ($m) => $m['sku'] === self::$fx['sku']));
    }

    public function test_full_flow_ship_leaves_source_receive_lands_in_destination_close_done(): void
    {
        $t = $this->create([$this->line(40)]);
        $this->assertSame('requested', $t['status']);
        $this->assertStringStartsWith('TRF-', $t['number']);
        $this->assertSame(self::$fx['j1']->code, $t['lines'][0]['toBin']['code']);
        $this->assertSame(['id', 'code', 'zone'], array_keys($t['lines'][0]['fromBin']));
        $this->assertSame(self::$fx['sku'], $t['lines'][0]['product']['sku']);
        $this->assertSame(self::$fx['batchNo'], $t['lines'][0]['batch']['batchNo']);
        $this->assertSame(['RYD', 'JED', 'تغطية نقص'], [$t['fromWarehouse']['code'], $t['toWarehouse']['code'], $t['reasonAr']]);
        $this->assertTrue(Notification::where('entity_number', $t['number'])->where('role_key', 'wm')->exists());
        $this->assertSame(100, $this->onHand('r1'), 'nothing moves before shipping');

        $ready = $this->toReady($t['id']);
        $this->assertSame('ready', $ready['status']);
        $this->assertNotNull($ready['approvedAt']);

        $shipped = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['number']}/ship", ['note' => 'شاحنة اختبار']));
        $this->assertSame('transit', $shipped['status']);
        $this->assertNotNull($shipped['shippedAt']);
        $this->assertSame(60, $this->onHand('r1'));
        $this->assertNull($this->onHand('j1'));
        $transit = InventoryMovement::where('reference_id', $t['id'])->where('type', 'transit')->firstOrFail();
        $this->assertSame([40, self::$fx['r1']->id, null], [$transit->qty, $transit->src_bin_id, $transit->dst_bin_id]);
        $this->assertTrue(AuditLog::where('action', 'TRANSFER.SHIP')->where('entity_id', $t['id'])->where('transaction_id', $transit->transaction_id)->exists());

        $sum = $this->expectOk($this->getAs('admin', '/api/inventory/balances/summary'));
        $this->assertGreaterThanOrEqual(40, collect($sum['warehouses'])->firstWhere('code', 'RYD')['inTransitOut']);
        $this->assertGreaterThanOrEqual(40, collect($sum['warehouses'])->firstWhere('code', 'JED')['inTransitIn']);
        $this->assertGreaterThanOrEqual(40, $sum['totals']['inTransit']);
        $listed = $this->expectOk($this->getAs('wm', '/api/inventory/transfers?status=transit&q='.self::$fx['sku']));
        $this->assertSame([40, 0, 40], [$listed['items'][0]['totalQty'], $listed['items'][0]['receivedQty'], $listed['items'][0]['inTransitQty']]);

        // cannot cancel or reject after shipping
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/cancel"), 'TRANSFER_SHIPPED', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/reject"), 'TRANSFER_SHIPPED', [422]);

        $received = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 40]]]));
        $this->assertSame(['received', false, null], [$received['status'], $received['partial'], $received['exception']]);
        $j = $this->balance(self::$fx['pid'], self::$fx['j1']->id, self::$fx['batchId']);
        $this->assertSame([40, self::$fx['batchId'], self::$fx['jedId']], [$j->on_hand, $j->batch_id, $j->warehouse_id]);
        $trf = InventoryMovement::where('reference_id', $t['id'])->where('type', 'trf')->firstOrFail();
        $this->assertSame([self::$fx['j1']->id, self::$fx['batchNo']], [$trf->dst_bin_id, $trf->batch_no]);
        $this->assertSame([], $this->mismatches('RYD'));
        $this->assertSame([], $this->mismatches('JED'));

        $this->assertSame('done', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/close"))['status']);
        $full = $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$t['number']}"));
        $this->assertSame(['draft', 'requested', 'approved', 'picking', 'ready', 'transit', 'received', 'done'], array_column($full['history'], 'toStatus'));
        $this->assertCount(2, $full['movements']);
        $this->assertSame(['transit', 'trf'], array_column($full['movements'], 'type'));
        $this->assertSame([40, 40, []], [$full['totalQty'], $full['receivedQty'], $full['allowed']]);
        $this->assertNotNull($full['closedAt']);
        $this->assertSame(2, $this->expectOk($this->getAs('wm', "/api/inventory/trace/{$t['number']}"))['count']);
        $this->assertReconciled();
    }

    public function test_partial_receive_raises_a_transfer_exception(): void
    {
        $t = $this->create([$this->line(30)], ['reasonCode' => 'rebalance']);
        $this->toReady($t['id']);
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/ship"));
        $before = OpsException::where('entity_number', $t['number'])->count();

        $r = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/receive", ['lines' => [['lineNo' => '1', 'receivedQty' => '25']]]));
        $this->assertSame(['partial', true, 'transfer'], [$r['status'], $r['partial'], $r['exception']['kind']]);
        $this->assertSame('wm', $r['exception']['ownerRole']);
        $this->assertSame($before + 1, OpsException::where('entity_number', $t['number'])->where('kind', 'transfer')->where('status', 'open')->count());
        $this->assertSame(25, $r['lines'][0]['receivedQty']);
        $this->assertSame([65, 30], [$this->onHand('j1'), $this->onHand('r1')]);

        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 5]]]), 'TRANSFER_TRANSITION', [422]);
        $detail = $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$t['id']}"));
        $this->assertSame([$r['exception']['number']], array_column($detail['exceptions'], 'number'));
        $this->assertSame(['done'], $detail['allowed']);
        $this->assertSame('done', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/close"))['status']);
        $this->assertSame([], $this->mismatches('JED'));
        $this->assertReconciled();
    }

    public function test_guards_quantities_bins_transitions_and_drafts(): void
    {
        $sku = self::$fx['sku'];
        $count = WarehouseTransfer::count();
        $tooMuch = $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(31)])), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['line' => 1, 'available' => 30, 'requested' => 31], $tooMuch['details']);
        // J1 lives in JED, not in the source warehouse
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1, ['fromBin' => self::$fx['j1']->code])])), 'BIN_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1, ['toBin' => 'STG-IN'])])), 'LOC_RULE', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1, ['sku' => 'NOPE-'.self::$fx['tag']])])), 'PRODUCT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1, ['batchNo' => 'NOPE'])])), 'BATCH_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1)], ['toWarehouseCode' => 'XXX'])), 'WAREHOUSE_NOT_FOUND', [404]);
        // validation
        $same = $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1)], ['toWarehouseCode' => 'RYD'])), 'INVALID_INPUT', [400]);
        $this->assertSame('toWarehouseCode', $same['details'][0]['path']);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(0)])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1)], ['date' => '17/09/2026'])), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1)], ['reasonCode' => 'whim'])), 'INVALID_INPUT', [400]);
        $this->assertSame($count, WarehouseTransfer::count(), 'a rejected request creates nothing');

        $d = $this->create([$this->line(10)], ['reasonCode' => 'other', 'submit' => false]);
        $this->assertSame('draft', $d['status']);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/approve"), 'TRANSFER_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/ship"), 'TRANSFER_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/receive", ['lines' => []]), 'TRANSFER_TRANSITION', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/teleport"), 'BAD_ACTION', [400]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers/TRF-NOPE/submit'), 'TRANSFER_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('wm', '/api/inventory/transfers/TRF-NOPE'), 'TRANSFER_NOT_FOUND', [404]);

        $this->assertSame('requested', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/submit"))['status']);
        $this->toReady($d['id']);
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/ship"));
        $this->assertSame(20, $this->onHand('r1'));
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => 11]]]), 'RECEIVE_EXCEEDS', [400]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/receive", ['lines' => [['lineNo' => 9, 'receivedQty' => 1]]]), 'BAD_LINE', [400]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/receive", ['lines' => [['lineNo' => 1, 'receivedQty' => -1]]]), 'INVALID_INPUT', [400]);
        $this->assertSame(65, $this->onHand('j1'), 'nothing landed');

        $full = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/receive", ['lines' => []])); // default = full quantity
        $this->assertSame('received', $full['status']);
        $this->assertSame(75, $this->onHand('j1'));
        $list = $this->expectOk($this->getAs('wm', "/api/inventory/transfers?pageSize=50&q={$sku}"));
        $this->assertSame(3, $list['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('wm', "/api/inventory/transfers?q={$sku}&status=received&from=ryd&to=jed&warehouse=JED"))['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/inventory/transfers?q={$sku}&from=JED"))['total']);
        $this->assertReconciled();
    }

    public function test_reject_cancel_and_stock_that_disappears_before_shipping(): void
    {
        $rejected = $this->create([$this->line(5)]);
        $r = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$rejected['id']}/reject", ['note' => 'لا حاجة']));
        $this->assertSame('rejected', $r['status']);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$rejected['id']}/approve"), 'TRANSFER_TRANSITION', [422]);
        $history = $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$rejected['id']}"))['history'];
        $this->assertSame('لا حاجة', end($history)['note']);

        $cancelled = $this->create([$this->line(5)]);
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$cancelled['id']}/approve"));
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$cancelled['id']}/cancel"))['status']);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$cancelled['id']}/start-picking"), 'TRANSFER_TRANSITION', [422]);

        // the source row gets quarantined after approval: shipping is refused and nothing moves
        $t = $this->create([$this->line(20)]);
        $this->toReady($t['id']);
        $flag = ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['r1']->code, 'batchNo' => self::$fx['batchNo'], 'reason' => 'فحص جودة'];
        $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', $flag + ['quarantine' => true]));
        $movements = InventoryMovement::count();
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/ship"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(1)])), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame('ready', WarehouseTransfer::findOrFail($t['id'])->status);
        $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', $flag + ['quarantine' => false]));

        // receive with no body at all = everything received in full
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/ship"));
        $done = $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/receive"));
        $this->assertSame(['received', 20], [$done['status'], $done['lines'][0]['receivedQty']]);
        $this->assertSame([0, 95], [$this->onHand('r1'), $this->onHand('j1')]);
        $this->assertReconciled();
    }

    public function test_permissions_are_enforced_and_denials_change_nothing(): void
    {
        $count = WarehouseTransfer::count();
        // inv may adjust/count but holds neither inventory.transfer nor inventory.transfer.approve
        $this->expectRejected($this->postAs('inv', '/api/inventory/transfers', $this->payload([$this->line(1, ['fromBin' => self::$fx['j1']->code])], ['fromWarehouseCode' => 'JED', 'toWarehouseCode' => 'RYD'])), 'FORBIDDEN', [403]);
        $this->assertSame($count, WarehouseTransfer::count());

        $t = $this->postAs('wm', '/api/inventory/transfers', $this->payload([$this->line(5, ['fromBin' => self::$fx['j1']->code, 'toBin' => self::$fx['r1']->code])], ['fromWarehouseCode' => 'JED', 'toWarehouseCode' => 'RYD', 'reasonCode' => 'surplus']))->json();
        $this->assertSame('requested', $t['status']);
        foreach (['approve', 'reject', 'cancel', 'receive'] as $action) {
            $this->expectRejected($this->postAs('inv', "/api/inventory/transfers/{$t['id']}/{$action}"), 'FORBIDDEN', [403]);
        }
        $this->assertSame('requested', WarehouseTransfer::findOrFail($t['id'])->status);
        // reading stays open to every signed-in user
        $this->assertSame($t['number'], $this->expectOk($this->getAs('sales', "/api/inventory/transfers/{$t['id']}"))['number']);
        $this->assertSame(['approved', 'rejected', 'cancelled'], $this->expectOk($this->getAs('sales', "/api/inventory/transfers/{$t['number']}"))['allowed']);
        $this->assertSame('cancelled', $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t['id']}/cancel"))['status']);
    }
}
