<?php

namespace Tests\Feature\Fulfillment;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\InventoryReservation;
use App\Models\OpsException;
use App\Models\PickList;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Sales\CreditService;
use Tests\ApiTestCase;

/**
 * Short pick, beyond the main story of FulfillmentFlowTest (6 of 10 found → backorder order, frozen bin):
 * the cancel policy, the frozen-bin policy, an order of which nothing could be picked, and orders of another system.
 */
class ShortPickTest extends ApiTestCase
{
    use FulfillmentFixtures;

    private function shortUrl(array $task): string
    {
        return "/api/fulfillment/pick-tasks/{$task['id']}/short";
    }

    public function test_short_first_then_the_closing_pick_makes_one_backorder_order(): void
    {
        $p = $this->makeProduct();
        $q = $this->makeProduct();
        $this->stock($p, 'A-07-1-B2', 30);
        $this->stock($q, 'A-07-1-B3', 30);
        $so = $this->order([['sku' => $p->sku, 'qty' => 10, 'price' => 10], ['sku' => $q->sku, 'qty' => 5, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $tasks = collect($fo['pickLists'][0]['tasks'])->keyBy(fn ($t) => $t['product']['sku']);

        // policy off: "not found" does not freeze the row
        $short = $this->withPolicy(['fulfillment.blockBinOnShortPick' => false], fn () => $this->expectOk($this->postAs('worker', $this->shortUrl($tasks[$p->sku]), ['reason' => 'notfound'])));
        $this->assertSame([10, false, 'picking', null], [$short['shortQty'], $short['binFrozen'], $short['orderStatus'], $short['backorder']]);
        $this->assertFalse($this->row($p, 'A-07-1-B2')->blocked);
        $this->assertSame(30, $this->inventory()->availability($p->id, $this->ryd()->id)['total'], 'the units are free again');
        // the order is flagged at once; the commercial decision is taken once, when the pick list closes
        $order = SalesOrder::where('number', $so['number'])->first();
        $this->assertSame(['picking', true], [$order->status, $order->has_shortage]);
        $this->assertSame([10, 0, 0], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['qty', 'short_qty', 'reserved_qty'])));
        $this->assertSame(0, SalesOrder::where('backorder_of_id', $order->id)->count());
        $this->assertSame('picking', FulfillmentOrder::where('number', $fo['number'])->value('status'));

        $done = $this->expectOk($this->pickTask($tasks[$q->sku]));
        $this->assertTrue($done['orderDone']);
        $backorder = SalesOrder::with('lines')->where('backorder_of_id', $order->id)->get();
        $this->assertCount(1, $backorder, 'ONE backorder order for the whole pick list');
        $this->assertSame($backorder[0]->number, $done['backorder']);
        $this->assertSame([[$p->id, 10]], $backorder[0]->lines->map(fn ($l) => [$l->product_id, $l->qty])->all());
        $this->assertSame([0, 10, 10], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['qty', 'short_qty', 'ordered_qty'])));
        $this->assertSame('picked', SalesOrder::where('number', $so['number'])->value('status'));
        $doc = $this->fo($fo['number']);
        $this->assertSame([[0, 0, 10], [5, 5, 0]], array_map(fn ($l) => [$l['qty'], $l['pickedQty'], $l['shortQty']], $doc['lines']));
        $this->assertSame('released', InventoryReservation::where('so_line_id', $so['lines'][0]['id'])->value('status'));
        $this->assertReconciles();
    }

    public function test_cancel_policy_cancels_the_short_quantity_and_tells_sales(): void
    {
        $this->withPolicy(['fulfillment.backorderShortPicks' => false], function () {
            $p = $this->makeProduct();
            $q = $this->makeProduct();
            $this->stock($p, 'A-07-1-B2', 30);
            $this->stock($q, 'A-07-1-B3', 30);
            $customer = $this->makeCustomer();
            $so = $this->order([['sku' => $p->sku, 'qty' => 10, 'price' => 10], ['sku' => $q->sku, 'qty' => 5, 'price' => 10]], $customer);
            $fo = $this->fulfil($so['number']);
            $tasks = collect($fo['pickLists'][0]['tasks'])->keyBy(fn ($t) => $t['product']['sku']);

            $this->expectOk($this->pickTask($tasks[$q->sku]));
            $this->expectOk($this->pickTask($tasks[$p->sku], 4));
            $short = $this->expectOk($this->postAs('worker', $this->shortUrl($tasks[$p->sku]), ['reason' => 'damaged', 'note' => 'كراتين مبللة']));
            $this->assertSame([6, 'damaged', false, 'picked', null], [$short['shortQty'], $short['reason'], $short['binFrozen'], $short['orderStatus'], $short['backorder']]);

            $order = SalesOrder::where('number', $so['number'])->first();
            $this->assertSame(['picked', true], [$order->status, $order->has_shortage]);
            $this->assertSame(0, SalesOrder::where('backorder_of_id', $order->id)->count(), 'no backorder under the cancel policy');
            $this->assertSame([4, 6, 10, 4], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['qty', 'short_qty', 'ordered_qty', 'reserved_qty'])));
            $this->assertTrue(AuditLog::where('action', 'SO.SHORT_CANCEL')->where('entity_number', $so['number'])->exists());
            // the order is worth less: the customer's exposure follows
            $this->assertEqualsWithDelta($this->app->make(CreditService::class)->exposure($customer->id)['exposure'], (float) Customer::find($customer->id)->balance, 0.001);
            // damaged stock: not frozen, but the inventory controller is asked to take it out of circulation
            $this->assertFalse($this->row($p, 'A-07-1-B2')->blocked);
            $this->assertSame(['w', 'inv'], array_values(OpsException::where('kind', 'damaged')->where('entity_number', $fo['number'])->firstOrFail()->only(['severity', 'owner_role'])));
            $this->assertSame('wm', OpsException::where('kind', 'missing')->where('entity_number', $fo['number'])->value('owner_role'));
            $this->assertReconciles();
        });
    }

    public function test_an_order_of_which_nothing_was_picked_is_never_shipped_empty(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-2-B3', 20);
        $so = $this->order([['sku' => $p->sku, 'qty' => 8, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $task = $fo['pickLists'][0]['tasks'][0];

        $short = $this->expectOk($this->postAs('worker', $this->shortUrl($task), ['reason' => 'damaged']));
        $this->assertSame([8, 'cancelled', null], [$short['shortQty'], $short['orderStatus'], $short['backorder']]);
        $doc = $this->fo($fo['number']);
        $this->assertSame(['cancelled', 'cancelled', ['short']], [$doc['status'], $doc['pickLists'][0]['status'], array_column($doc['pickLists'][0]['tasks'], 'status')]);
        $this->assertSame(['picking', 'cancelled'], array_column($doc['history'], 'toStatus'));
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/orders/{$fo['number']}/pack"), 'PACK_STATE', [422]);

        // the sales order is whole again and waits for stock: nothing reserved, nothing split, nothing cancelled
        $order = SalesOrder::where('number', $so['number'])->first();
        $this->assertSame(['confirmed', 'backordered', false], [$order->status, $order->fulfil_status, $order->has_shortage]);
        $this->assertSame([8, 0, 0, 0, 0], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['qty', 'short_qty', 'reserved_qty', 'allocated_qty', 'picked_qty'])));
        $this->assertSame(0, SalesOrder::where('backorder_of_id', $order->id)->count());
        $this->assertSame(0, $this->reserved($p));
        $this->assertSame(0, InventoryReservation::where('so_id', $order->id)->where('status', 'active')->count());
        $this->assertReconciles();

        // the way out: allocation reserves it again and a NEW fulfilment order is made
        $again = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/allocate"));
        $this->assertSame('allocated', $again['status']);
        $next = $this->fulfil($so['number']);
        $this->assertNotSame($fo['number'], $next['number']);
        $this->assertSame('alloc', $next['status']);
        $this->assertReconciles();
    }

    public function test_nothing_picked_cancels_our_order_under_the_cancel_policy(): void
    {
        $this->withPolicy(['fulfillment.backorderShortPicks' => false], function () {
            $p = $this->makeProduct();
            $this->stock($p, 'A-07-2-B3', 20);
            $customer = $this->makeCustomer();
            $so = $this->order([['sku' => $p->sku, 'qty' => 6, 'price' => 10]], $customer);
            $fo = $this->fulfil($so['number']);

            $short = $this->expectOk($this->postAs('worker', $this->shortUrl($fo['pickLists'][0]['tasks'][0]), ['reason' => 'notfound']));
            $this->assertSame(['cancelled', true], [$short['orderStatus'], $short['binFrozen']]);
            $this->assertSame('cancelled', FulfillmentOrder::where('number', $fo['number'])->value('status'));
            $this->assertSame('cancelled', PickList::where('fo_id', $fo['id'])->value('status'));
            $this->assertSame('cancelled', SalesOrder::where('number', $so['number'])->value('status'));
            $this->assertEqualsWithDelta(0, (float) Customer::find($customer->id)->balance, 0.001, 'a cancelled order is no exposure');
            $this->assertTrue($this->row($p, 'A-07-2-B3')->blocked, 'not found: the row waits for a count');
            $this->assertSame(0, $this->reserved($p));
            $this->assertReconciles();
        });
    }

    public function test_an_order_from_another_system_is_told_line_by_line_and_never_split(): void
    {
        $p = $this->makeProduct();
        $q = $this->makeProduct();
        $this->stock($p, 'A-07-3-B2', 20);
        $this->stock($q, 'A-07-3-B4', 20);
        $so = $this->order([['sku' => $p->sku, 'qty' => 10, 'price' => 10], ['sku' => $q->sku, 'qty' => 4, 'price' => 10]]);
        $ref = $this->fromAnotherSystem($so['number']);
        $fo = $this->fulfil($so['number']);
        $tasks = collect($fo['pickLists'][0]['tasks'])->keyBy(fn ($t) => $t['product']['sku']);

        $this->expectOk($this->pickTask($tasks[$p->sku], 7));
        $this->expectOk($this->postAs('worker', $this->shortUrl($tasks[$p->sku]), ['reason' => 'notfound']));
        $order = SalesOrder::where('number', $so['number'])->first();
        $this->assertTrue($order->has_shortage);
        $this->assertSame([10, 0, 7], array_values(SalesOrderLine::find($so['lines'][0]['id'])->only(['qty', 'short_qty', 'reserved_qty'])), 'the commercial quantity is the other system\'s to change');
        $event = IntegrationEvent::where('type', 'order.short')->where('subject', $ref)->firstOrFail();
        // (a JSON column does not keep key order: compare by key)
        $this->assertEquals([['sku' => $p->sku, 'ordered' => 10, 'picked' => 7, 'short' => 3], ['sku' => $q->sku, 'ordered' => 4, 'picked' => 0, 'short' => 0]], $event->payload['lines']);
        $this->assertSame([$fo['number'], 'notfound'], [$event->payload['fulfilmentOrder'], $event->payload['reason']]);

        // a later normal pick closes the list: the completion still says the order is short (the flag used to be lost here)
        $this->expectOk($this->pickTask($tasks[$q->sku]));
        $completed = IntegrationEvent::where('type', 'picking.completed')->where('subject', $ref)->firstOrFail();
        $this->assertTrue($completed->payload['shortPicked']);
        $this->assertSame([[10, 7, 3], [4, 4, 0]], array_map(fn ($l) => [$l['ordered'], $l['picked'], $l['short']], $completed->payload['lines']));
        $this->assertSame(0, SalesOrder::where('backorder_of_id', $order->id)->count(), 'never split here');
        $this->assertSame('picked', SalesOrder::where('number', $so['number'])->value('status'));
        $this->assertReconciles();
    }

    public function test_another_systems_order_with_nothing_picked_waits_whatever_the_policy(): void
    {
        $this->withPolicy(['fulfillment.backorderShortPicks' => false], function () {
            $p = $this->makeProduct();
            $this->stock($p, 'A-07-3-B2', 20);
            $so = $this->order([['sku' => $p->sku, 'qty' => 5, 'price' => 10]]);
            $ref = $this->fromAnotherSystem($so['number']);
            $fo = $this->fulfil($so['number']);

            $this->expectOk($this->postAs('worker', $this->shortUrl($fo['pickLists'][0]['tasks'][0]), ['reason' => 'other', 'note' => 'الصنف موقوف للفحص']));
            $order = SalesOrder::where('number', $so['number'])->first();
            $this->assertSame(['confirmed', 'backordered'], [$order->status, $order->fulfil_status], 'cancelling it is the other system\'s decision');
            $this->assertSame('cancelled', FulfillmentOrder::where('number', $fo['number'])->value('status'));
            $event = IntegrationEvent::where('type', 'order.short')->where('subject', $ref)->firstOrFail();
            $this->assertSame([true, 'backordered', 5], [$event->payload['fulfilmentCancelled'], $event->payload['status'], $event->payload['lines'][0]['short']]);
            $this->assertSame(0, IntegrationEvent::where('type', 'picking.completed')->where('subject', $ref)->count());
            $this->assertReconciles();
        });
    }
}
