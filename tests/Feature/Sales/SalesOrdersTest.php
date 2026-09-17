<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\InventoryAllocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Tests\ApiTestCase;

/** Sales orders: credit limit, availability, FEFO reservation, cancel with release, allocate, fulfill (pick list sequencing). */
class SalesOrdersTest extends ApiTestCase
{
    use SalesFixtures;

    public function test_order_reserves_fefo_and_returns_the_document(): void
    {
        $customer = $this->makeCustomer(100000, 1000);
        $product = $this->makeProduct('ambient', 2);
        $late = $this->stock($product, 'A-07-1-B1', 50, 300);
        $soon = $this->stock($product, 'A-07-2-B1', 20, 30);

        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 30, 'price' => 60]]));
        $this->assertStringStartsWith('SO-', $so['number']);
        $this->assertSame('allocated', $so['status']);
        $this->assertSame('normal', $so['priority']);
        $this->assertEquals(60, $so['kg']);
        $this->assertEquals(0.3, $so['cbm']);
        $this->assertSame([30, 30], [$so['lines'][0]['reservedQty'], $so['lines'][0]['allocatedQty']]);
        $allocations = $so['lines'][0]['allocations'];
        $this->assertSame([[$soon->batch_no, 'A-07-2-B1', 20], [$late->batch_no, 'A-07-1-B1', 10]], array_map(fn ($a) => [$a['batch']['batchNo'], $a['bin']['code'], $a['qty']], $allocations), 'earliest expiry first');
        $this->assertSame(['batchNo', 'expiryDate'], array_keys($allocations[0]['batch']));
        $this->assertSame(['code'], array_keys($allocations[0]['bin']));

        // contract of the document
        $this->assertSame(['code', 'nameAr', 'nameEn', 'zone', 'city', 'terms'], array_keys($so['customer']));
        $this->assertSame(['code' => 'RYD', 'nameAr' => $this->ryd()->name_ar], $so['warehouse']);
        $this->assertSame(['sku', 'nameAr', 'nameEn', 'weightKg', 'storageClass'], array_keys($so['lines'][0]['product']));
        $this->assertEquals(['sub' => 1800, 'vatPct' => 15, 'vat' => 270, 'total' => 2070], $so['totals']);
        $this->assertSame([], $so['fos']);
        $this->assertNull($so['quotation']);
        $this->assertNull($so['consolidation']);
        $this->assertSame([], $so['pods']);
        $this->assertSame([], $so['returns']);
        $this->assertSame([[null, 'allocated', 'حجز FEFO']], array_map(fn ($h) => [$h['fromStatus'], $h['toStatus'], $h['note']], $so['history']));
        $this->assertCount(1, $so['reservations']);
        $this->assertCount(2, $so['reservations'][0]['allocations']);

        // side effects: customer balance, audit, alloc ledger rows (no quantity change)
        $this->assertSame('3070.00', Customer::find($customer->id)->balance);
        $this->assertTrue(AuditLog::where('action', 'SO.CREATE')->where('entity_number', $so['number'])->exists());
        $this->assertSame(2, InventoryMovement::where('type', 'alloc')->where('reference_number', $so['number'])->count());
        $this->assertSame(30, $this->reserved($product));
        $this->assertSame(40, $this->inventory()->availability($product->id, $this->ryd()->id)['total']);

        // lists
        $list = $this->expectOk($this->getAs('wm', "/api/sales/orders?customer={$customer->code}&status=allocated&warehouse=RYD"));
        $this->assertSame([$so['number']], array_column($list['items'], 'number'));
        $this->assertEquals(2070, $list['items'][0]['totals']['total']);
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/sales/orders?customer={$customer->code}&waiting=allocation"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('wm', "/api/sales/orders?q={$so['number']}"))['total']);
        $this->assertSame($so['id'], $this->expectOk($this->getAs('wm', "/api/sales/orders/{$so['id']}"))['id'], 'lookup by id or number');
        $this->assertReconciles();
    }

    public function test_overselling_is_rejected_as_a_whole(): void
    {
        $customer = $this->makeCustomer();
        $plenty = $this->makeProduct();
        $scarce = $this->makeProduct();
        $this->stock($plenty, 'A-07-1-B3', 100);
        $this->stock($scarce, 'A-07-1-B4', 5);

        $body = $this->expectRejected($this->orderFor($customer, [['sku' => $plenty->sku, 'qty' => 10, 'price' => 5], ['sku' => $scarce->sku, 'qty' => 6, 'price' => 5]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['sku' => $scarce->sku, 'available' => 5, 'requested' => 6], $body['details']);
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count(), 'no partial order');
        $this->assertSame(0, $this->reserved($plenty));
        $this->assertSame(0, InventoryReservation::where('product_id', $plenty->id)->count());
        $this->assertSame('0.00', Customer::find($customer->id)->balance);
        $this->assertReconciles();
    }

    public function test_expired_and_quarantined_batches_are_excluded_from_allocation(): void
    {
        $customer = $this->makeCustomer();
        $expired = $this->makeProduct();
        $this->stock($expired, 'A-07-1-B1', 50, -1);
        $this->expectRejected($this->orderFor($customer, [['sku' => $expired->sku, 'qty' => 10, 'price' => 5]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(0, $this->inventory()->availability($expired->id)['total']);

        $quarantined = $this->makeProduct();
        $this->stock($quarantined, 'A-07-1-B2', 50, 90, true);
        $this->expectRejected($this->orderFor($customer, [['sku' => $quarantined->sku, 'qty' => 10, 'price' => 5]]), 'INSUFFICIENT_AVAILABLE', [422]);

        // an expired batch never wins FEFO over a valid one
        $valid = $this->stock($expired, 'A-07-2-B2', 12, 60);
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $expired->sku, 'qty' => 12, 'price' => 5]]));
        $this->assertSame([$valid->batch_no], array_map(fn ($a) => $a['batch']['batchNo'], $so['lines'][0]['allocations']));
        $this->assertReconciles();
    }

    public function test_credit_limit_is_enforced_from_settings(): void
    {
        $customer = $this->makeCustomer(500000, 236000);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B1', 200);

        $body = $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]), 'CREDIT_LIMIT', [422]);
        $this->assertEquals(['limit' => 500000, 'balance' => 236000, 'order' => 345000], $body['details']);
        $this->assertStringContainsString('500,000', $body['message']);
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame(0, $this->reserved($product));

        // within the limit it passes; a customer without a limit (0) is never blocked
        $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 10, 'price' => 3000]]));
        $this->expectOk($this->orderFor($this->makeCustomer(0), [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]));
        $this->assertReconciles();
    }

    public function test_validation_and_lookups(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->expectRejected($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'dueDate' => '2026-12-01', 'priority' => 'urgent', 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'dueDate' => '2026-12-01', 'lines' => [['sku' => $product->sku, 'qty' => 0, 'price' => 1]]]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'NOPE', 'dueDate' => '2026-12-01', 'lines' => [['sku' => $product->sku, 'qty' => 1, 'price' => 1]]]), 'WH_NOT_FOUND', [404]);
        $product->update(['active' => false]);
        $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 1, 'price' => 1]]), 'SKU_INACTIVE', [400]);
        $customer->update(['active' => false]);
        $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 1, 'price' => 1]]), 'CUSTOMER_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('sales', '/api/sales/orders/SO-NOPE'), 'SO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('sales', '/api/sales/orders/SO-NOPE/cancel'), 'SO_NOT_FOUND', [404]);
    }

    public function test_cancel_releases_reservations_and_customer_balance(): void
    {
        $customer = $this->makeCustomer(0, 500);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B2', 40);
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 25, 'price' => 100]]));
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill")); // FO still "alloc": cancellable
        $this->assertSame(25, $this->reserved($product));

        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('sales', "/api/sales/orders/{$so['number']}/cancel", ['reason' => 'طلب العميل'])));
        $this->assertSame(0, $this->reserved($product));
        $this->assertSame(40, $this->inventory()->availability($product->id, $this->ryd()->id)['total']);
        $this->assertSame(0, InventoryReservation::where('so_id', $so['id'])->where('status', 'active')->count());
        $this->assertSame(0, InventoryAllocation::where('product_id', $product->id)->where('status', 'active')->count());
        $this->assertSame('500.00', Customer::find($customer->id)->balance);
        $this->assertSame('cancelled', FulfillmentOrder::where('number', $fo['number'])->value('status'));
        $this->assertSame(['cancelled'], PickTask::where('product_id', $product->id)->pluck('status')->unique()->all());

        $after = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$so['number']}"));
        $this->assertSame('cancelled', $after['status']);
        $this->assertSame([0, 0], [$after['lines'][0]['reservedQty'], $after['lines'][0]['allocatedQty']]);
        $this->assertSame([], $after['lines'][0]['allocations']);
        $last = end($after['history']);
        $this->assertSame(['preparing', 'cancelled', 'طلب العميل'], [$last['fromStatus'], $last['toStatus'], $last['note']]);
        $this->expectRejected($this->postAs('sales', "/api/sales/orders/{$so['number']}/cancel"), 'SO_CANCEL', [422]);
        $this->assertReconciles();
    }

    public function test_cancel_is_refused_once_picking_started(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B3', 10);
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 4, 'price' => 10]]));
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $task = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$fo['number']}"))['pickLists'][0]['tasks'][0];
        $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => 'A-07-3-B3', 'scannedProduct' => $product->sku, 'qty' => 1]));

        $this->expectRejected($this->postAs('sales', "/api/sales/orders/{$so['number']}/cancel"), 'SO_CANCEL', [422]);
        $this->assertSame('picking', SalesOrder::where('number', $so['number'])->value('status'));
        $this->assertSame(3, $this->reserved($product));
        $this->assertReconciles();
    }

    public function test_allocate_an_order_left_waiting_for_stock(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        // an order imported / left in "confirmed" (waiting allocation): no reservation yet
        $waiting = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed', 'due_date' => now()->addDay()]);
        SalesOrderLine::create(['so_id' => $waiting->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 15, 'price' => 20]);
        $this->assertSame([$waiting->number], array_column($this->expectOk($this->getAs('sales', "/api/sales/orders?waiting=allocation&customer={$customer->code}"))['items'], 'number'));

        $this->expectRejected($this->postAs('sales', "/api/sales/orders/{$waiting->number}/allocate"), 'FORBIDDEN', [403]); // so.allocate is not a sales permission
        $this->expectRejected($this->postAs('admin', "/api/sales/orders/{$waiting->number}/allocate"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->expectRejected($this->postAs('wm', "/api/sales/orders/{$waiting->number}/fulfill"), 'SO_NOT_ALLOCATED', [422]);
        $this->assertSame('confirmed', $waiting->refresh()->status);

        $this->stock($product, 'A-07-3-B4', 15); // stock arrived
        $so = $this->expectOk($this->postAs('admin', "/api/sales/orders/{$waiting->number}/allocate"));
        $this->assertSame('allocated', $so['status']);
        $this->assertSame([15, 15], [$so['lines'][0]['reservedQty'], $so['lines'][0]['allocatedQty']]);
        $this->assertSame('A-07-3-B4', $so['lines'][0]['allocations'][0]['bin']['code']);
        $this->assertSame(15, $this->reserved($product));
        $this->expectRejected($this->postAs('admin', "/api/sales/orders/{$waiting->number}/allocate"), 'SO_NOT_WAITING', [422]);
        $this->assertReconciles();
    }

    public function test_fulfill_creates_the_execution_document_and_a_location_sequenced_pick_list(): void
    {
        $customer = $this->makeCustomer(0, 0, 'شرق الرياض');
        $product = $this->makeProduct();
        $other = $this->makeProduct();
        $soon = $this->stock($product, 'B-01-1-B1', 10, 20);   // FEFO takes zone B first …
        $late = $this->stock($product, 'A-07-1-B1', 30, 200);
        $this->stock($other, 'A-07-2-B3', 8, null);              // … and a product without batches
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 25, 'price' => 10], ['sku' => $other->sku, 'qty' => 8, 'price' => 4]]));
        $this->assertSame(['B-01-1-B1', 'A-07-1-B1'], array_map(fn ($a) => $a['bin']['code'], $so['lines'][0]['allocations']));

        $this->expectRejected($this->postAs('sales', '/api/sales/orders/SO-NOPE/fulfill'), 'SO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('driver', "/api/sales/orders/{$so['number']}/fulfill"), 'FORBIDDEN', [403]);
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $this->assertStringStartsWith('FO-', $fo['number']);
        $this->assertSame(['alloc', 33, 'شرق الرياض'], [$fo['status'], $fo['cartons'], $fo['zoneAr']]);
        $this->assertSame([25, 8], array_column($fo['lines'], 'qty'));
        $again = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $this->assertSame($fo['number'], $again['number'], 'idempotent per sales order');
        $this->assertSame(1, FulfillmentOrder::where('so_id', $so['id'])->count());

        // … but the walk is sequenced by zone → aisle → rack → bin
        $doc = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$fo['number']}"));
        $tasks = $doc['pickLists'][0]['tasks'];
        $this->assertSame([[1, 'A-07-1-B1', 15, $late->batch_no], [2, 'A-07-2-B3', 8, null], [3, 'B-01-1-B1', 10, $soon->batch_no]], array_map(fn ($t) => [$t['seq'], $t['bin']['code'], $t['qty'], $t['batchNo']], $tasks));
        $this->assertStringStartsWith('PL-', $doc['pickLists'][0]['number']);

        $order = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$so['number']}"));
        $this->assertSame('preparing', $order['status']);
        $this->assertSame(['id', 'number', 'status', 'tripId', 'trip'], array_keys($order['fos'][0]));
        $this->assertSame([$fo['number'], 'alloc', null], [$order['fos'][0]['number'], $order['fos'][0]['status'], $order['fos'][0]['trip']]);
        $this->assertTrue(AuditLog::where('action', 'FO.CREATE')->where('entity_number', $fo['number'])->exists());
        $this->assertReconciles();
    }

    public function test_permission_denied_changes_nothing(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-2-B4', 10);
        $movements = InventoryMovement::count();
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'driver')->count();

        $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 1, 'price' => 60]], 'driver'), 'FORBIDDEN', [403]);
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame($movements, InventoryMovement::count());
        $this->assertSame(0, $this->reserved($product));
        $this->assertSame($denied + 1, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->where('username', 'driver')->count());

        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 2, 'price' => 60]]));
        $this->expectRejected($this->postAs('worker', "/api/sales/orders/{$so['number']}/cancel"), 'FORBIDDEN', [403]);
        $this->assertSame('allocated', SalesOrder::where('number', $so['number'])->value('status'));
        $this->assertSame(2, $this->reserved($product));
        $this->expectOk($this->getAs('driver', "/api/sales/orders/{$so['number']}")); // reads are open
    }
}
