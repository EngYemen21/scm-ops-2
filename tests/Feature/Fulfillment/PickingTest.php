<?php

namespace Tests\Feature\Fulfillment;

use App\Models\AuditLog;
use App\Models\FulfillmentOrder;
use App\Models\InventoryBalance;
use App\Models\OpsException;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Who holds a pick list, the batch scan, and batch substitution. */
class PickingTest extends ApiTestCase
{
    use FulfillmentFixtures;

    public function test_claim_release_assign_and_who_may_scan(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-1-B2', 30);
        $so = $this->order([['sku' => $p->sku, 'qty' => 6, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $pl = $fo['pickLists'][0];
        $task = $pl['tasks'][0];
        $list = "/api/fulfillment/pick-lists/{$pl['number']}";
        $mine = fn (string $user, string $what) => array_column($this->expectOk($this->getAs($user, "/api/fulfillment/{$what}?mine=1&pageSize=200"))['items'], 'number');

        $this->assertNotContains($pl['number'], $mine('worker', 'pick-lists'));
        $this->expectRejected($this->postAs('sales', "{$list}/claim"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/pick-lists/PL-NOPE/claim'), 'PICK_LIST_NOT_FOUND', [404]);
        $claimed = $this->expectOk($this->postAs('worker', "{$list}/claim"));
        $this->assertSame([$pl['number'], 'worker'], [$claimed['pickList'], $claimed['assignedTo']]);
        $this->assertNotNull($claimed['assignedAt']);
        $this->expectOk($this->postAs('worker', "{$list}/claim")); // asking again changes nothing
        $this->assertSame(1, AuditLog::where('action', 'PICK.CLAIM')->where('entity_number', $pl['number'])->count());
        $this->assertContains($pl['number'], $mine('worker', 'pick-lists'));
        $this->assertNotContains($pl['number'], $mine('wm', 'pick-lists'));
        $this->assertContains($fo['number'], $mine('worker', 'orders'));
        $this->assertNotContains($fo['number'], $mine('wm', 'orders'));
        $this->assertSame('worker', $this->fo($fo['number'])['pickLists'][0]['assignedTo'], 'the order shows who holds its list');

        // another picker cannot take it; a supervisor may scan on it without taking it
        $this->expectRejected($this->postAs('wm', "{$list}/claim"), 'PICK_ASSIGNED', [409]);
        $this->expectOk($this->pickTask($task, 1, 'wm'));
        $this->assertSame('worker', PickList::find($pl['id'])->assigned_to);

        // assignment is the supervisor's
        $this->expectRejected($this->postAs('worker', "{$list}/assign", ['username' => 'wm']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "{$list}/assign"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', "{$list}/assign", ['username' => 'nobody-here']), 'USER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', "{$list}/assign", ['username' => 'sales']), 'USER_CANNOT_PICK', [422]);
        $assigned = $this->expectOk($this->postAs('wm', "{$list}/assign", ['username' => 'wm']));
        $this->assertSame('wm', $assigned['assignedTo']);
        $this->assertTrue(AuditLog::where('action', 'PICK.ASSIGN')->where('entity_number', $pl['number'])->where('new_value', 'wm')->exists());

        // the list is someone else's now: the worker can neither scan, report nor release it — and nothing changed
        $this->expectRejected($this->pickTask($task, 1), 'PICK_ASSIGNED', [409]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/short", ['reason' => 'notfound']), 'PICK_ASSIGNED', [409]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/substitute", ['reason' => 'damaged']), 'PICK_ASSIGNED', [409]);
        $this->expectRejected($this->postAs('worker', "{$list}/release"), 'PICK_ASSIGNED', [409]);
        $this->assertSame([1, 'partial'], array_values(PickTask::find($task['id'])->only(['picked_qty', 'status'])));
        $this->assertSame('wm', PickList::find($pl['id'])->assigned_to);

        // released: free for anyone, and the next confirmed scan takes it
        $this->assertNull($this->expectOk($this->postAs('wm', "{$list}/release"))['assignedTo']);
        $this->assertTrue(AuditLog::where('action', 'PICK.RELEASE')->where('entity_number', $pl['number'])->exists());
        $this->expectOk($this->pickTask($task, 2));
        $this->assertSame('worker', PickList::find($pl['id'])->assigned_to);
        // a supervisor frees a list he does not hold
        $this->assertNull($this->expectOk($this->postAs('wm', "{$list}/release"))['assignedTo']);

        $this->expectOk($this->pickTask($task));
        $this->assertSame('done', PickList::find($pl['id'])->status);
        $this->expectRejected($this->postAs('worker', "{$list}/claim"), 'PICK_LIST_CLOSED', [422]);
        $this->expectRejected($this->postAs('wm', "{$list}/assign", ['username' => 'worker']), 'PICK_LIST_CLOSED', [422]);
        $this->assertReconciles();
    }

    public function test_the_batch_is_scanned_when_the_bin_holds_more_than_one(): void
    {
        $p = $this->makeProduct();
        $early = $this->stock($p, 'A-07-2-B4', 10, 30);
        $late = $this->stock($p, 'A-07-2-B4', 10, 90);
        $so = $this->order([['sku' => $p->sku, 'qty' => 4, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $task = $fo['pickLists'][0]['tasks'][0];
        $this->assertSame($early->batch_no, $task['batch']['batchNo'], 'FEFO allocated the earlier batch');
        $this->assertTrue($task['batchScan'], 'two batches of the product in this bin: the card asks for the batch');

        $this->expectRejected($this->pickTask($task), 'BATCH_SCAN_REQUIRED', [422]);
        $this->expectRejected($this->pickTask($task, null, 'worker', ['scannedBatch' => $late->batch_no]), 'WRONG_BATCH', [422]);
        $this->assertSame(20, $this->onHand($p, 'A-07-2-B4'), 'nothing moved');
        $this->expectOk($this->pickTask($task, 3, 'worker', ['scannedBatch' => $early->batch_no]));
        $rows = InventoryBalance::where('product_id', $p->id)->where('bin_id', $task['binId'])->pluck('on_hand', 'batch_id');
        $this->assertSame([7, 10], [$rows[$early->id], $rows[$late->id]]);
        $this->assertTrue($this->tasks($fo['number'])[0]['batchScan'], 'still asked for the rest of the task');
        $this->expectOk($this->pickTask($task, null, 'worker', ['scannedBatch' => $early->batch_no]));
        $this->assertFalse($this->tasks($fo['number'])[0]['batchScan'], 'a closed task asks for nothing');
        $this->assertReconciles();
    }

    public function test_substitute_reserves_the_rest_from_another_batch(): void
    {
        $p = $this->makeProduct();
        $first = $this->stock($p, 'A-07-3-B2', 10, 20);
        $second = $this->stock($p, 'A-07-3-B3', 10, 200);
        $so = $this->order([['sku' => $p->sku, 'qty' => 6, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $task = $fo['pickLists'][0]['tasks'][0];
        $this->assertSame(['A-07-3-B2', 6], [$task['bin']['code'], $task['qty']]);
        $url = "/api/fulfillment/pick-tasks/{$task['id']}/substitute";

        $this->expectOk($this->pickTask($task, 2));
        $this->expectRejected($this->postAs('sales', $url, ['reason' => 'damaged']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $url), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', $url, ['reason' => 'other']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/pick-tasks/NOPE/substitute', ['reason' => 'damaged']), 'TASK_NOT_FOUND', [404]);
        $sub = $this->expectOk($this->postAs('worker', $url, ['reason' => 'damaged', 'note' => 'كرتون مكسور']));
        $this->assertSame([4, 0, false, 'picking'], [$sub['substituted'], $sub['short'], $sub['binFrozen'], $sub['orderStatus']]);
        $this->assertCount(1, $sub['tasks']);

        $tasks = $this->tasks($fo['number']);
        $this->assertSame([['A-07-3-B2', 2, 2, 'done', false], ['A-07-3-B3', 4, 0, 'open', true]], array_map(fn ($t) => [$t['bin']['code'], $t['qty'], $t['pickedQty'], $t['status'], $t['substituted']], $tasks));
        $this->assertSame(['damaged', $second->batch_no], [$tasks[0]['shortReason'], $tasks[1]['batch']['batchNo']]);
        $this->assertSame([0, 4], [$this->row($p, 'A-07-3-B2')->reserved, $this->row($p, 'A-07-3-B3')->reserved]);
        $this->assertSame([6, 6, 0], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['reserved_qty', 'allocated_qty', 'short_qty'])));
        $this->assertFalse(SalesOrder::where('number', $so['number'])->value('has_shortage'), 'nothing is short: the order still ships in full');
        $this->assertSame([[6, 0]], array_map(fn ($l) => [$l['qty'], $l['shortQty']], $this->fo($fo['number'])['lines']));
        // the damaged stock is the inventory controller's to take out of circulation
        $this->assertSame('inv', OpsException::where('kind', 'damaged')->where('entity_number', $fo['number'])->value('owner_role'));
        $this->assertTrue(AuditLog::where('action', 'PICK.SUBSTITUTE')->where('entity_id', $task['id'])->exists());
        $this->expectRejected($this->postAs('worker', $url, ['reason' => 'damaged']), 'TASK_CLOSED', [409]);
        $this->assertReconciles();

        $done = $this->expectOk($this->pickTask($tasks[1]));
        $this->assertTrue($done['orderDone']);
        $this->assertSame('picked', FulfillmentOrder::where('number', $fo['number'])->value('status'));
        $this->assertSame([8, 6], [$this->onHand($p, 'A-07-3-B2'), $this->onHand($p, 'A-07-3-B3')]);
        $this->assertNotNull($first);
        $this->assertReconciles();
    }

    public function test_substitute_takes_what_exists_elsewhere_and_shorts_the_rest(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-1-B4', 5, 30);
        $this->stock($p, 'A-07-2-B4', 3, 60);
        $so = $this->order([['sku' => $p->sku, 'qty' => 8, 'price' => 10]]);
        $this->stock($p, 'A-07-3-B4', 2, 300); // arrives after the order: the only free stock
        $fo = $this->fulfil($so['number']);
        $tasks = collect($fo['pickLists'][0]['tasks'])->keyBy(fn ($t) => $t['bin']['code']);
        $this->assertSame([5, 3], [$tasks['A-07-1-B4']['qty'], $tasks['A-07-2-B4']['qty']]);

        $sub = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$tasks['A-07-1-B4']['id']}/substitute", ['reason' => 'notfound']));
        $this->assertSame([2, 3, true, 'picking'], [$sub['substituted'], $sub['short'], $sub['binFrozen'], $sub['orderStatus']]);
        $after = collect($this->tasks($fo['number']))->keyBy(fn ($t) => $t['bin']['code']);
        $this->assertSame([3, 'short'], [$after['A-07-1-B4']['qty'], $after['A-07-1-B4']['status']], 'the task keeps what is really short');
        $this->assertSame([2, 'open', true], [$after['A-07-3-B4']['qty'], $after['A-07-3-B4']['status'], $after['A-07-3-B4']['substituted']]);
        $this->assertTrue($this->row($p, 'A-07-1-B4')->blocked, 'not found: the row is frozen until it is counted');
        $this->assertSame([[5, 3]], array_map(fn ($l) => [$l['qty'], $l['shortQty']], $this->fo($fo['number'])['lines']));
        $this->assertStringContainsString('جُمّد', OpsException::where('kind', 'missing')->where('entity_number', $fo['number'])->value('text_ar'));
        $this->assertReconciles();

        $this->expectOk($this->pickTask($after['A-07-2-B4']));
        $done = $this->expectOk($this->pickTask($after['A-07-3-B4']));
        $this->assertTrue($done['orderDone']);
        $order = SalesOrder::where('number', $so['number'])->first();
        $this->assertSame([[5, 3, 8]], SalesOrderLine::where('so_id', $order->id)->get()->map(fn ($l) => [$l->qty, $l->short_qty, $l->ordered_qty])->all());
        $this->assertSame($done['backorder'], SalesOrder::where('backorder_of_id', $order->id)->value('number'));
        $this->assertReconciles();
    }

    public function test_substitute_with_nothing_else_is_a_short_pick_and_an_expired_batch_is_avoided_everywhere(): void
    {
        // nothing else in the warehouse: the whole task goes the short-pick way (here: nothing picked → no shipment)
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-1-B2', 5, 30);
        $so = $this->order([['sku' => $p->sku, 'qty' => 5, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $sub = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$fo['pickLists'][0]['tasks'][0]['id']}/substitute", ['reason' => 'expired']));
        $this->assertSame([0, 5, [], 'cancelled'], [$sub['substituted'], $sub['short'], $sub['tasks'], $sub['orderStatus']]);
        $this->assertSame(['confirmed', 'backordered'], array_values(SalesOrder::where('number', $so['number'])->first()->only(['status', 'fulfil_status'])));
        $this->assertSame('inv', OpsException::where('kind', 'other')->where('entity_number', $fo['number'])->value('owner_role'));
        $this->assertReconciles();

        // "expired" is about the batch, wherever it lies: the same batch in another bin is not offered again
        $q = $this->makeProduct();
        $batch = $this->stock($q, 'A-07-1-B3', 5, 10);
        $other = $this->inventory()->bin($this->ryd()->id, 'A-07-2-B3');
        DB::transaction(fn () => $this->inventory()->post(null, null, ['type' => 'opening', 'productId' => $q->id, 'batchId' => $batch->id, 'qty' => 5,
            'to' => ['warehouseId' => $this->ryd()->id, 'binId' => $other->id], 'reference' => ['type' => 'Test', 'number' => 'T-W3']]));
        $fresh = $this->stock($q, 'A-07-3-B3', 5, 300);
        $so2 = $this->order([['sku' => $q->sku, 'qty' => 3, 'price' => 10]]);
        $fo2 = $this->fulfil($so2['number']);
        $task = $fo2['pickLists'][0]['tasks'][0];
        $this->assertSame($batch->batch_no, $task['batch']['batchNo']);
        $sub = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/substitute", ['reason' => 'expired']));
        $this->assertSame([3, 0], [$sub['substituted'], $sub['short']]);
        $new = collect($this->tasks($fo2['number']))->firstWhere('substituted', true);
        $this->assertSame(['A-07-3-B3', $fresh->batch_no], [$new['bin']['code'], $new['batch']['batchNo']]);
        $this->assertSame('cancelled', PickTask::find($task['id'])->status, 'nothing was picked from the reported batch');
        $this->assertReconciles();
    }
}
