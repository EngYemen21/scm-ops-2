<?php

namespace Tests\Feature\Flows;

use App\Models\AuditLog;
use App\Models\GoodsReceipt;
use App\Models\IntegrationEvent;
use App\Models\InventoryMovement;
use App\Models\OpsException;
use App\Models\PurchaseOrder;
use App\Models\StagingEntry;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/**
 * Inbound: arrive → inspect → GRN (accepted → STG-IN, damaged → DMG-01, exceptions, PO partial) → putaway
 * (wrong scans rejected with a persistent exception) → available stock.
 */
class InboundFlowTest extends ApiTestCase
{
    use FlowFixtures;

    public function test_rbac_grn_and_arrival_without_permission_change_nothing(): void
    {
        [$shipment] = $this->expectedShipment([[$this->product('INB-RBAC'), 50]]);
        $movements = InventoryMovement::count();
        $security = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();

        $this->expectRejected($this->postAs('driver', "/api/inbound/shipments/{$shipment->number}/grn", ['lines' => []]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "/api/inbound/shipments/{$shipment->number}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 50]]]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "/api/inbound/shipments/{$shipment->number}/arrive"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$shipment->number}/cancel"), 'FORBIDDEN', [403]);

        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame('expected', $shipment->refresh()->status);
        $this->assertSame(0, GoodsReceipt::where('shipment_id', $shipment->id)->count());
        $this->assertSame($security + 4, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
    }

    public function test_full_receiving_story_500_ordered_480_accepted_10_damaged_10_short(): void
    {
        $main = $this->product('INB-MAIN', tracksExpiry: true);
        $side = $this->product('INB-SIDE');
        [$shipment, $po] = $this->expectedShipment([[$main, 500], [$side, 100]]);
        $url = "/api/inbound/shipments/{$shipment->number}";
        $batchNo = 'B-'.self::uid();
        $good = ['lines' => [
            ['lineNo' => 1, 'acceptedQty' => 480, 'damagedQty' => 10, 'rejectedQty' => 0, 'batchNo' => $batchNo, 'expiryDate' => '2027-06-30'],
            ['lineNo' => 2, 'acceptedQty' => '90', 'rejectedQty' => 10, 'qcNote' => 'عبوات مفتوحة'],
        ]];

        // ── reads: list / scan / detail
        $list = $this->expectOk($this->getAs('wm', '/api/inbound/shipments?status=open&warehouse=RYD&po='.$po->number));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($list));
        $this->assertSame(1, $list['total']);
        $this->assertSame(['ordered' => 600, 'accepted' => 0, 'damaged' => 0, 'rejected' => 0, 'open' => 600], $list['items'][0]['totals']);
        $this->assertSame(500, $list['items'][0]['lines'][0]['openQty']);
        $this->assertSame($main->sku, $list['items'][0]['lines'][0]['product']['sku']);
        $this->assertSame($shipment->number, $this->expectOk($this->getAs('worker', '/api/inbound/shipments/scan/'.strtolower($po->number)))['number'], 'scan by PO number');
        $this->expectRejected($this->getAs('worker', '/api/inbound/shipments/scan/PO-NOPE'), 'PO_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('worker', '/api/inbound/shipments/SHP-NOPE'), 'SHIPMENT_NOT_FOUND', [404]);

        // ── arrival + inspection (action buttons post without a body)
        $this->expectRejected($this->postAs('worker', "{$url}/grn", $good), 'GRN_BEFORE_INSPECTION', [422]);
        $arrived = $this->expectOk($this->postAs('worker', "{$url}/arrive", ['carrier' => 'V-08']));
        $this->assertSame(['arrived', 'V-08'], [$arrived['status'], $arrived['carrier']]);
        $this->assertNotNull($arrived['arrivedAt']);
        $this->expectRejected($this->postAs('worker', "{$url}/arrive"), 'SHIPMENT_TRANSITION', [422]);
        $this->expectRejected($this->postAs('proc', "{$url}/cancel"), 'SHIPMENT_TRANSITION', [422]);
        $this->assertSame('inspecting', $this->expectOk($this->postAs('worker', "{$url}/inspect"))['status']);

        // ── rejected receipts leave nothing behind
        $this->expectRejected($this->postAs('wm', "{$url}/grn", ['lines' => []]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => -1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$url}/grn", ['lines' => [$good['lines'][0]]]), 'LINE_MISSING', [400]);
        $over = $good;
        $over['lines'][0]['acceptedQty'] = 495;
        $body = $this->expectRejected($this->postAs('worker', "{$url}/grn", $over), 'OVER_RECEIPT', [422]);
        $this->assertSame(['lineNo' => 1, 'remaining' => 500, 'entered' => 505], $body['details']);
        $noExpiry = $good;
        unset($noExpiry['lines'][0]['expiryDate']);
        $this->expectRejected($this->postAs('wm', "{$url}/grn", $noExpiry), 'EXPIRY_REQUIRED', [400]);
        $noBatch = $good;
        unset($noBatch['lines'][0]['batchNo']);
        $this->expectRejected($this->postAs('wm', "{$url}/grn", $noBatch), 'BATCH_REQUIRED', [400]);
        $this->assertSame(0, GoodsReceipt::where('shipment_id', $shipment->id)->count());
        $this->assertSame(0, $this->totalOnHand($main));

        // ── GRN
        $key = ['Idempotency-Key' => 'grn-'.self::uid()];
        $grn = $this->expectOk($this->postAs('worker', "{$url}/grn", $good, $key));
        $this->assertStringStartsWith('GRN-', $grn['number']);
        $this->assertCount(2, $grn['lines']);
        $this->assertCount(2, $grn['putaways']);
        $this->assertSame([490, 480, 10, 0, 10, 'damaged'], array_map(fn ($k) => $grn['lines'][0][$k], ['receivedQty', 'acceptedQty', 'damagedQty', 'rejectedQty', 'remainingQty', 'qcResult']));
        $this->assertSame([100, 90, 0, 10, 0, 'rejected'], array_map(fn ($k) => $grn['lines'][1][$k], ['receivedQty', 'acceptedQty', 'damagedQty', 'rejectedQty', 'remainingQty', 'qcResult']));
        $this->assertSame(['accepted', 'damaged'], array_column($grn['lines'][0]['qcResults'], 'result'));
        $this->assertSame($batchNo, $grn['lines'][0]['batchNo']);
        $this->assertSame($po->number, $grn['po']['number']);
        $this->assertSame('putaway', $grn['shipment']['status']);
        $this->assertEqualsCanonicalizing([['grn', 480, 'STG-IN'], ['dmg', 10, 'DMG-01'], ['grn', 90, 'STG-IN']], array_map(fn ($m) => [$m['type'], $m['qty'], $m['dstBin']['code']], $grn['movements']));

        $this->assertSame(480, $this->onHand($main, 'STG-IN'), 'accepted stock waits in inbound staging');
        $this->assertSame(10, $this->onHand($main, 'DMG-01'), 'damaged stock goes to the damaged area');
        $this->assertSame(90, $this->onHand($side, 'STG-IN'));
        $this->assertSame(90, $this->totalOnHand($side), 'rejected quantity never enters stock');
        $this->assertSame(0, $this->inv()->availability($main->id)['total'], 'nothing is available before putaway');

        $kinds = OpsException::where('entity_number', $grn['number'])->pluck('kind')->sort()->values()->all();
        $this->assertSame(['damage', 'rejected', 'shortage'], $kinds);
        $this->assertSame($po->number, OpsException::where('entity_number', $grn['number'])->first()->document_number);
        $po->refresh();
        $this->assertSame(['partial', 10], [$po->status, $po->open_qty]);
        $this->assertSame(1, IntegrationEvent::where('type', 'InventoryReceived')->where('payload->grn', $grn['number'])->count());
        $this->assertSame(1, AuditLog::where('action', 'GRN.POST')->where('entity_number', $grn['number'])->count());
        $this->assertSame(2, StagingEntry::where('reference_id', $grn['id'])->where('status', 'waiting')->count());

        // a retry with the same idempotency key replays the first answer; a genuinely new post is a duplicate
        $replay = $this->postAs('worker', "{$url}/grn", $good, $key);
        $this->assertSame($grn['number'], $this->expectOk($replay)['number']);
        $this->assertSame('true', $replay->headers->get('X-Idempotent-Replay'));
        $this->expectRejected($this->postAs('wm', "{$url}/grn", $good), 'GRN_DUPLICATE', [409]);
        $this->assertSame(1, GoodsReceipt::where('shipment_id', $shipment->id)->count());
        $this->assertReconciles();

        // ── GRN reads
        $grns = $this->expectOk($this->getAs('proc', '/api/inbound/grns?warehouse=RYD&po='.$po->number));
        $this->assertSame([1, $grn['number']], [$grns['total'], $grns['items'][0]['number']]);
        $this->assertSame(480, $grns['items'][0]['lines'][0]['acceptedQty']);
        $this->assertSame($grn['number'], $this->expectOk($this->getAs('proc', '/api/inbound/grns/'.$grn['id']))['number']);
        $this->expectRejected($this->getAs('proc', '/api/inbound/grns/GRN-NOPE'), 'GRN_NOT_FOUND', [404]);

        // ── putaway: wrong scans are rejected, the wrong-location attempts stay on record
        $tasks = $this->expectOk($this->getAs('worker', '/api/inbound/putaway?warehouse=RYD&grn='.$grn['number']))['items'];
        $this->assertCount(2, $tasks);
        $task = collect($tasks)->firstWhere('product.sku', $main->sku);
        $other = collect($tasks)->firstWhere('product.sku', $side->sku);
        $this->assertSame(480, $task['qty']);
        $this->assertSame($grn['number'], $task['grn']['number']);
        $this->assertNotNull($task['suggestedBin'], 'a free ambient bin is suggested');
        $this->assertSame('ambient', $task['suggestedBin']['zone']['type']);
        $confirm = "/api/inbound/putaway/{$task['number']}/confirm";
        $bin = $task['suggestedBin']['code'];

        $this->expectRejected($this->postAs('sales', $confirm, ['scannedBin' => $bin]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin, 'scannedProduct' => 'P00000']), 'WRONG_PRODUCT', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => 'FZ-01-1-B1', 'scannedProduct' => $main->sku]), 'WRONG_LOCATION', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => 'QTN-01', 'scannedProduct' => $main->sku]), 'WRONG_LOCATION', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => 'Z-99-9-B9', 'scannedProduct' => $main->sku]), 'BIN_NOT_FOUND', [404]);
        $wrongLoc = OpsException::where('kind', 'wrongloc')->where('document_number', $task['number'])->get();
        $this->assertCount(3, $wrongLoc, 'every rejected location attempt leaves a persistent exception');
        $this->assertSame([$grn['number'], 'wm', 'open'], [$wrongLoc[0]->entity_number, $wrongLoc[0]->owner_role, $wrongLoc[0]->status]);
        $this->assertSame(480, $this->onHand($main, 'STG-IN'), 'a rejected putaway moves nothing');
        $this->assertSame('open', $this->expectOk($this->getAs('worker', '/api/inbound/putaway?grn='.$grn['number']))['items'][0]['status']);

        // ── correct putaway
        $done = $this->expectOk($this->postAs('worker', $confirm, ['scannedBin' => strtolower($bin), 'scannedProduct' => $main->sku]));
        $this->assertSame([$task['number'], $bin, false], [$done['task'], $done['bin'], $done['shipmentDone']]);
        $this->assertStringStartsWith('TX-', $done['movement']);
        $this->assertSame([0, 480], [$this->onHand($main, 'STG-IN'), $this->onHand($main, $bin)]);
        $this->assertSame(480, $this->inv()->availability($main->id)['total'], 'damaged stock is never available');
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => $bin]), 'PUTAWAY_DONE', [409]);

        // the second task is confirmed with no scan at all: the suggested bin is used
        $last = $this->expectOk($this->postAs('worker', "/api/inbound/putaway/{$other['id']}/confirm"));
        $this->assertTrue($last['shipmentDone']);
        $detail = $this->expectOk($this->getAs('wm', $url));
        $this->assertSame('done', $detail['status']);
        $this->assertNotNull($detail['completedAt']);
        $this->assertSame(['arrived', 'inspecting', 'putaway', 'done'], array_column($detail['history'], 'toStatus'));
        $this->assertSame(['ordered' => 600, 'accepted' => 570, 'damaged' => 10, 'rejected' => 10, 'open' => 10], $detail['totals']);
        $this->assertSame([$bin, 'done'], [$detail['putaways'][0]['actualBin']['code'], $detail['putaways'][0]['status']]);
        $this->assertSame(0, StagingEntry::where('reference_id', $grn['id'])->where('status', 'waiting')->count());
        $this->assertSame(0, $this->expectOk($this->getAs('worker', '/api/inbound/putaway?grn='.$grn['number']))['total']);
        $this->assertSame(2, $this->expectOk($this->getAs('worker', '/api/inbound/putaway?status=done&grn='.$grn['number']))['total']);
        $this->assertReconciles();
    }

    public function test_excess_receipt_is_rejected_unless_the_setting_allows_it(): void
    {
        $product = $this->product('INB-EXC');
        [$shipment, $po] = $this->expectedShipment([[$product, 100]]);
        $url = "/api/inbound/shipments/{$shipment->number}";
        $this->expectOk($this->postAs('worker', "{$url}/arrive"));
        $this->expectOk($this->postAs('worker', "{$url}/inspect"));
        $excess = ['lines' => [['lineNo' => 1, 'acceptedQty' => 101]]];

        $this->expectRejected($this->postAs('wm', "{$url}/grn", $excess), 'OVER_RECEIPT', [422]);
        $this->assertSame('inspecting', $shipment->refresh()->status);
        $this->assertSame(0, $this->totalOnHand($product));

        $settings = $this->app->make(SettingsService::class);
        $settings->set('receiving.allowExcess', true);
        try {
            $grn = $this->expectOk($this->postAs('wm', "{$url}/grn", $excess));
        } finally {
            $settings->set('receiving.allowExcess', false);
        }
        $this->assertSame([101, 0], [$grn['lines'][0]['acceptedQty'], $grn['lines'][0]['remainingQty']]);
        $this->assertSame(101, $this->onHand($product, 'STG-IN'));
        $this->assertSame(['received', 0], [PurchaseOrder::find($po->id)->status, PurchaseOrder::find($po->id)->open_qty]);
        $this->assertSame(0, OpsException::where('entity_number', $grn['number'])->count());
        $this->assertReconciles();
    }

    public function test_a_fully_rejected_receipt_closes_the_shipment_without_putaway_and_cancel_works_only_before_arrival(): void
    {
        $product = $this->product('INB-REJ');
        [$shipment] = $this->expectedShipment([[$product, 20]]);
        $url = "/api/inbound/shipments/{$shipment->id}";
        $this->expectOk($this->postAs('worker', "{$url}/arrive"));
        $this->expectOk($this->postAs('worker', "{$url}/inspect"));
        $grn = $this->expectOk($this->postAs('wm', "{$url}/grn", ['lines' => [['lineNo' => 1, 'acceptedQty' => 0, 'rejectedQty' => 20]], 'notes' => 'شحنة مرفوضة بالكامل']));
        $this->assertSame([], $grn['putaways']);
        $this->assertSame([], $grn['movements']);
        $this->assertSame('done', $grn['shipment']['status']);
        $this->assertSame(0, $this->totalOnHand($product));

        [$cancellable] = $this->expectedShipment([[$product, 5]]);
        $cancelled = $this->expectOk($this->postAs('proc', "/api/inbound/shipments/{$cancellable->number}/cancel"));
        $this->assertSame('cancelled', $cancelled['status']);
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$cancellable->number}/arrive"), 'SHIPMENT_TRANSITION', [422]);

        [$locked] = $this->expectedShipment([[$product, 5]]);
        $locked->update(['locked' => true]);
        $this->expectRejected($this->postAs('worker', "/api/inbound/shipments/{$locked->number}/arrive"), 'SHIPMENT_LOCKED', [422]);
        $this->assertReconciles();
    }
}
