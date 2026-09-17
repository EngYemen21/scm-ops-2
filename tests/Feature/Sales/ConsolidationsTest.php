<?php

namespace Tests\Feature\Sales;

use App\Models\FulfillmentOrder;
use App\Models\OrderConsolidation;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Warehouse;
use Tests\ApiTestCase;

/** Order consolidation batches: create / add / remove / advance (readypick creates the fulfillment orders). */
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
        // an order belongs to one batch only
        $this->expectRejected($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$a['number'], $c['number']]]), 'OC_STATE', [422]);
        $this->assertSame($oc['number'], $this->expectOk($this->getAs('sales', "/api/sales/orders/{$a['number']}"))['consolidation']['number']);

        $added = $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => $c['number']]));
        $this->assertCount(3, $added['orders']);
        $removed = $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$oc['id']}/remove", ['orderNumber' => $c['number']]));
        $this->assertCount(2, $removed['orders']);
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

        // readypick → picking is free; picking → packed needs every order packed
        $this->assertSame('picking', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $this->expectRejected($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NOT_PACKED', [422]);
        $foNumbers = [];
        foreach ([$a, $b] as $so) {
            $foNumbers[] = $this->packedOrder($so['number']); // fulfill is idempotent: returns the FO created by the batch
        }
        $this->assertSame('packed', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        $this->assertSame('readydisp', $this->expectOk($this->postAs('wm', "/api/sales/consolidations/{$oc['number']}/advance"))['status']);
        // dispatching needs a trip that carries every fulfillment order
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NO_TRIP', [422]);
        $trip = $this->makeTrip($foNumbers, $this->makeVehicle(), $this->makeDriver());
        $dispatched = $this->expectOk($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"));
        $this->assertSame(['dispatched', $trip->id], [$dispatched['status'], $dispatched['tripId']]);
        $this->expectRejected($this->postAs('disp', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_NOT_DELIVERED', [422]);
        $this->assertSame(['open', 'consolidating', 'readypick', 'picking', 'packed', 'readydisp', 'dispatched'], array_column($dispatched['history'], 'toStatus'));
        $this->assertReconciles();
    }

    public function test_orders_waiting_allocation_block_readypick_and_nothing_is_created(): void
    {
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-1-B2', 20);
        $ok = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $waiting = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed']);
        SalesOrderLine::create(['so_id' => $waiting->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 500, 'price' => 1]);

        $oc = $this->expectOk($this->postAs('sales', '/api/sales/consolidations', ['orderNumbers' => [$ok['number'], $waiting->number], 'rule' => 'نفس المسار']));
        $this->assertSame('نفس المسار', $oc['rule']);
        $this->expectOk($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/advance"));
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/advance"), 'OC_UNALLOCATED', [422]);
        // all-or-nothing: the fulfillment order of the first order was rolled back with the rejection
        $this->assertSame(0, FulfillmentOrder::where('so_id', $ok['id'])->count());
        $this->assertSame('allocated', SalesOrder::find($ok['id'])->status);
        $this->assertSame('consolidating', OrderConsolidation::where('number', $oc['number'])->value('status'));
        $this->expectRejected($this->getAs('sales', '/api/sales/consolidations/OC-NOPE'), 'OC_NOT_FOUND', [404]);

        // orders of another warehouse cannot join
        $jed = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $this->makeCustomer()->id, 'warehouse_id' => Warehouse::where('code', 'JED')->firstOrFail()->id, 'status' => 'confirmed']);
        $this->expectRejected($this->postAs('sales', "/api/sales/consolidations/{$oc['number']}/add", ['orderNumber' => $jed->number]), 'OC_WAREHOUSE', [422]);
        $this->assertReconciles();
    }
}
