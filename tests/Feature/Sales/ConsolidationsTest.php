<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\FulfillmentOrder;
use App\Models\OrderConsolidation;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/**
 * Order consolidation batches: create / add / remove / advance (readypick creates the fulfillment orders) / cancel, and
 * what happens to a batch when one of its orders is cancelled, still waits for stock, or goes back to waiting.
 */
class ConsolidationsTest extends ApiTestCase
{
    use SalesFixtures;

    public function test_create_add_remove_and_advance(): void
    {
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B1', 100);
        $a = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $b = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 6, 'price' => 10]]));
        $c = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 7, 'price' => 10]]));

        $this->expectRejected($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number']]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], 'SO-NOPE']]), 'SO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('worker', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $b['number']]]), 'FORBIDDEN', [403]);
        $this->assertNull(SalesOrder::find($a['id'])->consolidation_id);

        $oc = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $b['number']]]));
        $this->assertStringStartsWith('OC-', $oc['number']);
        $this->assertSame(['open', 'المنطقة + تاريخ التسليم', 'RYD'], [$oc['status'], $oc['rule'], $oc['warehouse']['code']]);
        $this->assertEqualsCanonicalizing([$a['number'], $b['number']], array_column($oc['orders'], 'number'));
        $this->assertSame(['sku', 'nameAr'], array_keys($oc['orders'][0]['lines'][0]['product']));
        $this->assertSame(['open'], array_column($oc['history'], 'toStatus'));
        $this->assertSame([], $oc['trips']);
        // what the screen may offer: every order may still leave, others may join, the batch may be cancelled
        $this->assertSame([true, true], [$oc['actions']['canAdd'], $oc['actions']['canCancel']]);
        $this->assertEqualsCanonicalizing([$a['number'], $b['number']], $oc['actions']['removable']);
        // an order belongs to one batch only
        $this->expectRejected($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $c['number']]]), 'OC_STATE', [422]);
        $this->assertSame($oc['number'], $this->expectOk($this->getAs('sales', "/api/sales/orders/{$a['number']}"))['consolidation']['number']);

        $added = $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => $c['number']]));
        $this->assertCount(3, $added['orders']);
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => $c['number']]), 'OC_IN_BATCH', [422]);
        $removed = $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$oc['id']}/remove", ['orderNumber' => $c['number']]));
        $this->assertCount(2, $removed['orders']);
        // removing what is not in the batch is refused, not silently "done"
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['id']}/remove", ['orderNumber' => $c['number']]), 'OC_NOT_MEMBER', [422]);
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/orders/{$c['number']}/remove"), 'OC_NOT_MEMBER', [422]);
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => 'SO-NOPE']), 'SO_NOT_FOUND', [404]);

        $list = $this->expectOk($this->getAs('disp', '/api/sales/consolidations?status=open&warehouse=RYD&pageSize=200'));
        $row = collect($list['items'])->firstWhere('number', $oc['number']);
        $this->assertSame(['code' => 'RYD'], $row['warehouse']);
        $this->assertSame(['number', 'status', 'customer', 'kg', 'cbm', 'fos'], array_keys($row['orders'][0]));

        // open → consolidating → readypick: every order gets its fulfillment order + pick list (action buttons, no body)
        $this->assertSame('consolidating', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $ready = $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"));
        $this->assertSame('readypick', $ready['status']);
        $this->assertSame(['preparing', 'preparing'], array_column($ready['orders'], 'status'));
        $this->assertSame([1, 1], array_map(fn ($o) => count($o['fos']), $ready['orders']));
        $this->assertSame(2, FulfillmentOrder::whereIn('so_id', [$a['id'], $b['id']])->where('status', 'alloc')->count());
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => $c['number']]), 'OC_LOCKED', [422]);
        $this->assertSame([false, false], [$ready['actions']['canAdd'], $ready['actions']['canCancel']], 'the batch is being fulfilled: nobody joins, and it is not dissolved over live orders');
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/cancel"), 'OC_LOCKED', [422]);

        // readypick → picking is free; picking → packed needs every order packed — and says which are not
        $this->assertSame('picking', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $late = $this->expectRejected($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NOT_PACKED', [422]);
        $this->assertEqualsCanonicalizing([$a['number'], $b['number']], $late['details']['orders']);
        // an order whose picking is in the batch's hands cannot simply leave it
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/orders/{$a['number']}/remove"), 'OC_LOCKED', [422]);
        $foNumbers = [];
        foreach ([$a, $b] as $so) {
            $foNumbers[] = $this->packedOrder($so['number']); // fulfill is idempotent: returns the FO created by the batch
        }
        $this->assertSame('packed', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $this->assertSame('readydisp', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        // dispatching needs a trip that carries every fulfillment order
        $noTrip = $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NO_TRIP', [422]);
        $this->assertEqualsCanonicalizing($foNumbers, $noTrip['details']['fulfilmentOrders']);
        $trip = $this->makeTrip($foNumbers, $this->makeVehicle(), $this->makeDriver());
        $dispatched = $this->expectOk($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"));
        $this->assertSame(['dispatched', $trip->id], [$dispatched['status'], $dispatched['tripId']]);
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NOT_DELIVERED', [422]);
        $this->assertSame(['open', 'consolidating', 'readypick', 'picking', 'packed', 'readydisp', 'dispatched'], array_column($dispatched['history'], 'toStatus'));

        // a partial or a failed delivery is a finished delivery: the batch is not blocked for ever by one of them
        FulfillmentOrder::where('number', $foNumbers[0])->update(['status' => 'partial']);
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NOT_DELIVERED', [422]);
        FulfillmentOrder::where('number', $foNumbers[1])->update(['status' => 'failed']);
        $this->assertSame('done', $this->expectOk($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_DONE', [422]);
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/cancel"), 'OC_DONE', [422]);
        $this->assertReconciles();
    }

    /** Rewritten for the 2026-11 rule: the waiting order is tried once, named, and the batch can go on without it. */
    public function test_an_order_waiting_for_stock_is_tried_at_readypick_and_the_batch_can_go_on_without_it(): void
    {
        $product = $this->makeProduct();
        $scarce = $this->makeProduct();
        $this->stock($product, 'A-07-1-B2', 20);
        $this->stock($scarce, 'A-07-2-B2', 3);
        $ok = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $short = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed']);
        SalesOrderLine::create(['so_id' => $short->id, 'line_no' => 1, 'product_id' => $scarce->id, 'qty' => 500, 'price' => 1]);
        $late = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed']);
        SalesOrderLine::create(['so_id' => $late->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 10, 'price' => 1]);

        $oc = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$ok['number'], $short->number, $late->number], 'rule' => 'نفس المسار']));
        $this->assertSame('نفس المسار', $oc['rule']);
        $url = "/api/sales/consolidations/{$oc['number']}";
        $this->expectOk($this->postAs('sales', "{$url}/advance"));
        // both waiting orders are tried: one can be served in full, the other cannot — and is named
        $body = $this->expectRejected($this->postAs('sales', "{$url}/advance"), 'OC_UNALLOCATED', [422]);
        $this->assertSame([$short->number], $body['details']['orders']);
        $this->assertStringContainsString($short->number, $body['message']);
        // all-or-nothing: no fulfillment order, and the reservations the attempt made are gone with the refusal
        $this->assertSame(0, FulfillmentOrder::whereIn('so_id', [$ok['id'], $short->id, $late->id])->count());
        $this->assertSame(['allocated', 'confirmed', 'confirmed'], [SalesOrder::find($ok['id'])->status, $short->refresh()->status, $late->refresh()->status]);
        $this->assertSame([5, 0], [$this->reserved($product), $this->reserved($scarce)]);
        $this->assertSame('consolidating', OrderConsolidation::where('number', $oc['number'])->value('status'));
        $this->expectRejected($this->getAs('sales', '/api/sales/consolidations/OC-NOPE'), 'OC_NOT_FOUND', [404]);

        // orders of another warehouse cannot join; nor an order that is not confirmed / allocated, nor one of another batch
        $jed = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => Warehouse::where('code', 'JED')->firstOrFail()->id, 'status' => 'confirmed']);
        $this->expectRejected($this->postAs('sales', "{$url}/add", ['orderNumber' => $jed->number]), 'OC_WAREHOUSE', [422]);
        $gone = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'delivered']);
        $this->expectRejected($this->postAs('sales', "{$url}/add", ['orderNumber' => $gone->number]), 'OC_STATE', [422]);
        $x = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]));
        $y = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 1, 'price' => 10]]));
        $other = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$x['number'], $y['number']]]));
        $inOther = $this->expectRejected($this->postAs('sales', "{$url}/add", ['orderNumber' => $x['number']]), 'OC_IN_BATCH', [422]);
        $this->assertStringContainsString($other['number'], $inOther['message']);
        $this->assertSame($other['id'], SalesOrder::find($x['id'])->consolidation_id, 'still in its own batch');

        // the batch goes on without the order that cannot be served (action button with the order in the path)
        $this->expectRejected($this->postAs('worker', "{$url}/orders/{$short->number}/remove"), 'FORBIDDEN', [403]);
        $left = $this->expectOk($this->postAs('sales', "{$url}/orders/{$short->number}/remove", ['reason' => 'لا مخزون']));
        $this->assertEqualsCanonicalizing([$ok['number'], $late->number], array_column($left['orders'], 'number'));
        $this->assertNull($short->refresh()->consolidation_id);
        $this->assertTrue(AuditLog::where('action', 'OC.REMOVE')->where('entity_number', $oc['number'])->where('new_value', 'like', "%{$short->number}%")->exists());
        $ready = $this->expectOk($this->postAs('sales', "{$url}/advance"));
        $this->assertSame('readypick', $ready['status']);
        $this->assertSame(['preparing', 'preparing'], array_column($ready['orders'], 'status'), 'the order that waited was reserved by the batch and released to picking');
        $this->assertSame(17, $this->reserved($product), '5 + 10 of the batch, 1 + 1 of the other batch');
        // before picking starts an order may still leave: it keeps its fulfillment order and goes on alone
        $alone = $this->expectOk($this->postAs('sales', "{$url}/orders/{$late->number}/remove"));
        $this->assertSame([$ok['number']], array_column($alone['orders'], 'number'), 'a batch may be left with one order');
        $this->assertSame('alloc', FulfillmentOrder::where('so_id', $late->id)->value('status'));
        $this->assertReconciles();
    }

    public function test_cancelled_orders_leave_the_batch_and_an_empty_batch_is_cancelled_not_advanced(): void
    {
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B3', 50);
        $a = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $b = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 6, 'price' => 10]]));
        $oc = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $b['number']]]));
        $url = "/api/sales/consolidations/{$oc['number']}";

        // a cancelled order used to block the batch with a generic "not allocated": now it simply leaves at the next step
        $this->expectOk($this->postAs('sales', "/api/sales/orders/{$a['number']}/cancel", ['reason' => 'ألغاه العميل']));
        $this->assertSame($oc['id'], SalesOrder::find($a['id'])->consolidation_id, 'cancelling does not rewrite the batch behind the user\'s back');
        $next = $this->expectOk($this->postAs('wm', "{$url}/advance"));
        $this->assertSame(['consolidating', [$b['number']]], [$next['status'], array_column($next['orders'], 'number')]);
        $this->assertNull(SalesOrder::find($a['id'])->consolidation_id);
        $this->assertTrue(AuditLog::where('action', 'OC.DETACH')->where('entity_number', $oc['number'])->where('new_value', 'like', "%{$a['number']}%")->exists());
        $ready = $this->expectOk($this->postAs('wm', "{$url}/advance"));
        $this->assertSame(['readypick', ['preparing']], [$ready['status'], array_column($ready['orders'], 'status')]);

        // the last order is cancelled too: nothing to advance — the detachment stays although the advance was refused
        $this->expectOk($this->postAs('sales', "/api/sales/orders/{$b['number']}/cancel"));
        $this->expectRejected($this->postAs('wm', "{$url}/advance"), 'OC_EMPTY', [422]);
        $this->assertNull(SalesOrder::find($b['id'])->consolidation_id);
        $empty = $this->expectOk($this->getAs('wm', $url));
        $this->assertSame(['readypick', [], true], [$empty['status'], $empty['orders'], $empty['actions']['canCancel']], 'an empty batch has one way out at any stage: cancel');
        $this->expectRejected($this->postAs('worker', "{$url}/cancel"), 'FORBIDDEN', [403]);
        $this->assertSame(['ok' => true, 'number' => $oc['number'], 'released' => []], $this->expectOk($this->postAs('wm', "{$url}/cancel", ['reason' => 'لا طلبات'])));
        $this->expectRejected($this->getAs('wm', $url), 'OC_NOT_FOUND', [404]);
        $this->assertTrue(AuditLog::where('action', 'OC.CANCEL')->where('entity_number', $oc['number'])->exists());

        // cancelling an open batch gives its orders back to the pool, untouched
        $c = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 2, 'price' => 10]]));
        $d = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 3, 'price' => 10]]));
        $open = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$c['number'], $d['number']]]));
        $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$open['number']}/advance"));
        $out = $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$open['number']}/cancel")); // action button: no body
        $this->assertEqualsCanonicalizing([$c['number'], $d['number']], $out['released']);
        $this->assertSame([[null, 'allocated'], [null, 'allocated']], SalesOrder::whereIn('id', [$c['id'], $d['id']])->get()->map(fn ($s) => [$s->consolidation_id, $s->status])->all());
        $this->assertSame(5, $this->reserved($product), 'reservations of the released orders are untouched');
        $again = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$c['number'], $d['number']]]));
        $this->assertNotSame($open['number'], $again['number']);
        $this->assertReconciles();
    }

    public function test_an_order_that_went_back_to_waiting_does_not_hold_the_batch_for_ever(): void
    {
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B4', 50);
        $a = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $b = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 6, 'price' => 10]]));
        $oc = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $b['number']]]));
        $url = "/api/sales/consolidations/{$oc['number']}";
        foreach (['consolidating', 'readypick', 'picking'] as $status) {
            $this->assertSame($status, $this->expectOk($this->postAs('wm', "{$url}/advance"))['status']);
        }
        $this->packedOrder($a['number']);
        // the warehouse puts order b back (what the put-back of picking does): its fulfillment order is cancelled, its
        // reservation released, and the order waits for stock again
        DB::transaction(function () use ($b) {
            $this->inventory()->release($b['id']);
            $foIds = FulfillmentOrder::where('so_id', $b['id'])->pluck('id');
            FulfillmentOrder::whereIn('id', $foIds)->update(['status' => 'cancelled']);
            PickTask::whereIn('pick_list_id', PickList::whereIn('fo_id', $foIds)->select('id'))->update(['status' => 'cancelled']);
            PickList::whereIn('fo_id', $foIds)->update(['status' => 'cancelled']);
            SalesOrder::whereKey($b['id'])->update(['status' => 'confirmed', 'fulfil_status' => 'backordered']);
        });

        $late = $this->expectRejected($this->postAs('wm', "{$url}/advance"), 'OC_NOT_PACKED', [422]);
        $this->assertSame([$b['number']], $late['details']['orders']);
        $this->assertSame([$b['number']], $this->expectOk($this->getAs('wm', $url))['actions']['removable'], 'only the order without a live fulfillment order may leave now');
        $this->expectRejected($this->postAs('wm', "{$url}/orders/{$a['number']}/remove"), 'OC_LOCKED', [422]);
        $this->expectOk($this->postAs('wm', "{$url}/orders/{$b['number']}/remove"));
        $this->assertSame('packed', $this->expectOk($this->postAs('wm', "{$url}/advance"))['status']);
        // the order that left is an ordinary waiting order again: reserved and released on its own
        $this->assertSame('allocated', $this->expectOk($this->postAs('wm', "/api/sales/orders/{$b['number']}/allocate"))['status']);
        $this->assertSame('alloc', $this->expectOk($this->postAs('wm', "/api/sales/orders/{$b['number']}/fulfill"))['status']);
        $this->assertReconciles();
    }
}
