<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Bin;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\InventoryAllocation;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Core\SettingsService;
use App\Services\Sales\SalesService;
use Illuminate\Support\Facades\Artisan;
use Tests\ApiTestCase;

/**
 * Sales orders: credit gate, availability, FEFO reservation, an order accepted with a shortage (linked backorder),
 * cancel with release, allocate what is available, fulfill (pick list sequencing by temperature then location).
 */
class SalesOrdersTest extends ApiTestCase
{
    use SalesFixtures;

    public function test_order_reserves_fefo_and_returns_the_document(): void
    {
        $customer = $this->makeCustomer(100000);
        $this->receivable($customer, 1000); // an unpaid invoice: what the customer owes before this order
        $product = $this->makeProduct('ambient', 2);
        $late = $this->stock($product, 'A-07-1-B1', 50, 300);
        $soon = $this->stock($product, 'A-07-2-B1', 20, 30);

        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 30, 'price' => 60]]));
        $this->assertStringStartsWith('SO-', $so['number']);
        $this->assertSame('allocated', $so['status']);
        $this->assertSame('full', $so['fulfilStatus']);
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
        $this->assertNull($so['backorderOf']);
        $this->assertSame([], $so['backorders']);
        $this->assertFalse($so['hasShortage']);
        $this->assertSame([], $so['waiting']);
        $this->assertSame([], $so['pods']);
        $this->assertSame([], $so['returns']);
        $this->assertSame([[null, 'allocated', 'حجز FEFO']], array_map(fn ($h) => [$h['fromStatus'], $h['toStatus'], $h['note']], $so['history']));
        $this->assertCount(1, $so['reservations']);
        $this->assertCount(2, $so['reservations'][0]['allocations']);

        // side effects: customer exposure (unpaid invoice 1,000 + this open order 2,070), audit, alloc ledger rows (no quantity change)
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
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/sales/orders?customer={$customer->code}&shortage=1"))['total']);
        $this->assertSame(1, $this->expectOk($this->getAs('wm', "/api/sales/orders?q={$so['number']}"))['total']);
        $this->assertSame($so['id'], $this->expectOk($this->getAs('wm', "/api/sales/orders/{$so['id']}"))['id'], 'lookup by id or number');
        $this->assertReconciles();
    }

    public function test_overselling_is_rejected_as_a_whole_and_says_what_is_available(): void
    {
        $customer = $this->makeCustomer();
        $plenty = $this->makeProduct();
        $scarce = $this->makeProduct();
        $this->stock($plenty, 'A-07-1-B3', 100);
        $this->stock($scarce, 'A-07-1-B4', 5);

        $body = $this->expectRejected($this->orderFor($customer, [['sku' => $plenty->sku, 'qty' => 10, 'price' => 5], ['sku' => $scarce->sku, 'qty' => 6, 'price' => 5]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([$scarce->sku, 5, 6], [$body['details']['sku'], $body['details']['available'], $body['details']['requested']]);
        // … with everything the form needs to offer "accept with the rest on backorder"
        $this->assertTrue($body['details']['backorderAllowed']);
        $this->assertSame([[$scarce->sku, 5, 6]], array_map(fn ($s) => [$s['sku'], $s['available'], $s['requested']], $body['details']['shortages']));
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count(), 'no partial order');
        $this->assertSame(0, $this->reserved($plenty));
        $this->assertSame(0, InventoryReservation::where('product_id', $plenty->id)->count());
        $this->assertSame('0.00', Customer::find($customer->id)->balance);

        // two lines of one product share its availability: 3 + 3 out of 5 is short by one
        $twice = $this->expectRejected($this->orderFor($customer, [['sku' => $scarce->sku, 'qty' => 3, 'price' => 5], ['sku' => $scarce->sku, 'qty' => 3, 'price' => 5]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([2, 3], [$twice['details']['available'], $twice['details']['requested']]);
        $this->assertSame(0, $this->reserved($scarce));
        $this->assertReconciles();
    }

    public function test_an_order_with_a_shortage_is_accepted_on_request_and_the_rest_becomes_one_linked_backorder(): void
    {
        $customer = $this->makeCustomer(100000);
        $plenty = $this->makeProduct('ambient', 2);
        $scarce = $this->makeProduct('ambient', 1);
        $none = $this->makeProduct('ambient', 1);
        $this->stock($plenty, 'A-07-1-B3', 100);
        $this->stock($scarce, 'A-07-1-B4', 5);
        $lines = [['sku' => $plenty->sku, 'qty' => 10, 'price' => 5], ['sku' => $scarce->sku, 'qty' => 8, 'price' => 5], ['sku' => $none->sku, 'qty' => 4, 'price' => 5]];
        $body = ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'dueDate' => now()->addDays(3)->toDateString(), 'lines' => $lines];

        // the flag is honoured only while the policy allows it
        $settings = $this->app->make(SettingsService::class);
        $settings->set('sales.allowBackorder', false);
        try {
            $off = $this->expectRejected($this->postAs('sales', '/api/sales/orders', $body + ['allowBackorder' => true]), 'INSUFFICIENT_AVAILABLE', [422]);
            $this->assertFalse($off['details']['backorderAllowed']);
            $this->assertFalse($this->expectOk($this->getAs('sales', '/api/sales/policy'))['allowBackorder']);
        } finally {
            $settings->set('sales.allowBackorder', true);
        }
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->expectRejected($this->postAs('sales', '/api/sales/orders', $body + ['allowBackorder' => 'maybe']), 'INVALID_INPUT', [400]);

        $so = $this->expectOk($this->postAs('sales', '/api/sales/orders', $body + ['allowBackorder' => true]));
        // the order ships what the warehouse has: every line it keeps is fully reserved …
        $this->assertSame(['allocated', 'full', true], [$so['status'], $so['fulfilStatus'], $so['hasShortage']]);
        $this->assertSame([[10, 10, null, 0], [5, 5, 8, 3], [0, 0, 4, 4]], array_map(fn ($l) => [$l['qty'], $l['reservedQty'], $l['orderedQty'], $l['shortQty']], $so['lines']), 'qty · reserved · ordered · short');
        $this->assertEquals(25, $so['kg'], 'weight of what this order ships (10 × 2 kg + 5 × 1 kg)');
        $this->assertEquals(round(75 * 1.15, 2), $so['totals']['total']);
        $this->assertSame([15, 0], [$this->reserved($plenty) + $this->reserved($scarce), $this->reserved($none)]);
        // … and what it could not ship waits on ONE linked order
        $this->assertCount(1, $so['backorders']);
        $this->assertSame(['confirmed', 'backordered'], [$so['backorders'][0]['status'], $so['backorders'][0]['fulfilStatus']]);
        $bo = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$so['backorders'][0]['number']}"));
        $this->assertSame(['number' => $so['number'], 'status' => 'allocated'], $bo['backorderOf']);
        $this->assertSame([[$scarce->sku, 3, 0], [$none->sku, 4, 0]], array_map(fn ($l) => [$l['product']['sku'], $l['qty'], $l['reservedQty']], $bo['lines']));
        $this->assertSame([[$scarce->sku, 3, 0], [$none->sku, 4, 0]], array_map(fn ($w) => [$w['sku'], $w['need'], $w['available']], $bo['waiting']));
        $this->assertSame([$so['customer']['code'], $so['dueDate'], $so['priority']], [$bo['customer']['code'], $bo['dueDate'], $bo['priority']], 'same customer and delivery terms');
        $note = $so['history'][0]['note'];
        $this->assertStringContainsString($bo['number'], $note);
        $this->assertTrue(AuditLog::where('action', 'SO.BACKORDER')->where('entity_number', $so['number'])->exists());
        // the customer owes the value of everything accepted, shipped now or later
        $this->assertSame(number_format(110 * 1.15, 2, '.', ''), Customer::find($customer->id)->balance);

        // lists: what waits · what was cut · the backorder orders
        $of = fn (string $filter) => array_column($this->expectOk($this->getAs('sales', "/api/sales/orders?customer={$customer->code}&{$filter}"))['items'], 'number');
        $this->assertSame([$bo['number']], $of('waiting=allocation'));
        $this->assertSame([$so['number']], $of('shortage=1'));
        $this->assertSame([$bo['number']], $of('backorders=1'));

        // the original goes on alone: its execution document has no line for what it does not ship
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $this->assertSame([[1, 10], [2, 5]], array_map(fn ($l) => [$l['lineNo'], $l['qty']], $fo['lines']));
        $this->assertSame(15, $fo['cartons']);
        $this->expectRejected($this->postAs('wm', "/api/sales/orders/{$bo['number']}/fulfill"), 'SO_NOT_ALLOCATED', [422]);

        // stock arrives for one product: the backorder takes it and keeps waiting for the other …
        $this->stock($scarce, 'A-07-1-B4', 20);
        $this->assertSame(0, Artisan::call('scm:backorders-allocate', ['--limit' => 500])); // a wide budget: other classes' waiting orders are in the queue too
        $run = json_decode(trim(Artisan::output()), true);
        $this->assertGreaterThanOrEqual(1, $run['partial']);
        $partial = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$bo['number']}"));
        $this->assertSame(['confirmed', 'partial', [3, 0]], [$partial['status'], $partial['fulfilStatus'], array_column($partial['lines'], 'reservedQty')]);
        // … and is complete when the rest is there
        $this->stock($none, 'A-07-2-B4', 4);
        $this->assertSame(0, Artisan::call('scm:backorders-allocate', ['--limit' => 500]));
        $this->assertContains($bo['number'], json_decode(trim(Artisan::output()), true)['orders']);
        $done = $this->expectOk($this->getAs('sales', "/api/sales/orders/{$bo['number']}"));
        $this->assertSame(['allocated', 'full', [3, 4]], [$done['status'], $done['fulfilStatus'], array_column($done['lines'], 'reservedQty')]);
        $this->assertSame([], $done['waiting']);
        $this->assertSame(0, $this->inventory()->availability($none->id, $this->ryd()->id)['total']);
        $this->assertReconciles();
    }

    public function test_an_order_with_nothing_available_waits_itself_and_is_served_when_stock_arrives(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $so = $this->expectOk($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD', 'dueDate' => now()->addDays(3)->toDateString(),
            'allowBackorder' => true, 'lines' => [['sku' => $product->sku, 'qty' => 12, 'price' => 10]]]));
        $this->assertSame(['confirmed', 'backordered', false, []], [$so['status'], $so['fulfilStatus'], $so['hasShortage'], $so['backorders']], 'no second order: this one is the backorder');
        $this->assertSame([[12, 0, null, 0]], array_map(fn ($l) => [$l['qty'], $l['reservedQty'], $l['orderedQty'], $l['shortQty']], $so['lines']));
        $this->assertSame([[null, 'confirmed']], array_map(fn ($h) => [$h['fromStatus'], $h['toStatus']], $so['history']));
        $this->assertSame(1, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame('138.00', Customer::find($customer->id)->balance);

        $this->stock($product, 'A-07-3-B1', 12);
        $run = $this->app->make(SalesService::class)->retryBackorders(500);
        $this->assertContains($so['number'], $run['orders']);
        $this->assertSame('allocated', SalesOrder::where('number', $so['number'])->value('status'));
        $this->assertSame(12, $this->reserved($product));
        $this->assertReconciles();
    }

    public function test_waiting_orders_get_stock_oldest_first_and_an_unfillable_one_does_not_block_the_queue(): void
    {
        $sales = $this->app->make(SalesService::class);
        $sales->retryBackorders(500); // whatever older tests left waiting takes what it can first
        $customer = $this->makeCustomer();
        [$contested, $absent, $other] = [$this->makeProduct(), $this->makeProduct(), $this->makeProduct()];
        $wait = fn ($product, int $qty) => $this->expectOk($this->postAs('sales', '/api/sales/orders', ['customerCode' => $customer->code, 'warehouseCode' => 'RYD',
            'dueDate' => now()->addDays(3)->toDateString(), 'allowBackorder' => true, 'lines' => [['sku' => $product->sku, 'qty' => $qty, 'price' => 10]]]))['number'];
        $first = $wait($contested, 5);
        $stuck = $wait($absent, 5);      // nothing will arrive for this one
        $second = $wait($contested, 5);
        $last = $wait($other, 2);
        $status = fn (string $n) => SalesOrder::where('number', $n)->value('status');

        // 5 units for two orders that both want 5: the older one is served, the newer keeps waiting
        $this->stock($contested, 'A-07-3-B2', 5);
        $this->stock($other, 'A-07-3-B3', 2);
        // … and with a budget of ONE attempt the order nothing can be done for is skipped, not counted
        $run = $sales->retryBackorders(1);
        $this->assertSame([1, [$first]], [$run['tried'], $run['orders']]);
        $this->assertSame(['allocated', 'confirmed', 'confirmed', 'confirmed'], array_map($status, [$first, $stuck, $second, $last]));
        $next = $sales->retryBackorders(1);
        $this->assertSame([$last], $next['orders'], 'the stuck order and the one whose stock is gone did not use up the attempt');
        $this->assertSame(['confirmed', 'confirmed', 'allocated'], array_map($status, [$stuck, $second, $last]));
        $idle = $sales->retryBackorders();
        $this->assertSame([0, 0, 0, []], [$idle['tried'], $idle['completed'], $idle['partial'], $idle['orders']], 'nothing left to do');
        $this->assertGreaterThanOrEqual(2, $idle['checked']);
        // orders of another system are the integration layer's to top up
        $foreign = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed', 'source_system' => 'sales', 'external_ref' => self::code('ORD')]);
        SalesOrderLine::create(['so_id' => $foreign->id, 'line_no' => 1, 'product_id' => $other->id, 'qty' => 1, 'price' => 10]);
        $this->stock($other, 'A-07-3-B3', 1);
        $this->assertSame([], $sales->retryBackorders()['orders']);
        $this->assertSame('confirmed', $foreign->refresh()->status);
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

    public function test_credit_limit_is_enforced_against_what_the_customer_really_owes(): void
    {
        $customer = $this->makeCustomer(500000);
        $invoice = $this->receivable($customer, 236000);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B1', 400);

        $body = $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]), 'CREDIT_LIMIT', [422]);
        $this->assertEquals(['limit' => 500000, 'balance' => 236000, 'receivables' => 236000, 'openOrders' => 0, 'order' => 345000], $body['details']);
        $this->assertStringContainsString('500,000', $body['message']);
        $this->assertSame(0, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame(0, $this->reserved($product));

        // within the limit it passes; a customer without a limit (0) is never blocked under the default policy
        $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 10, 'price' => 3000]]));
        $this->assertSame('270500.00', Customer::find($customer->id)->balance, 'unpaid invoice 236,000 + open order 34,500');
        $this->expectOk($this->orderFor($this->makeCustomer(0), [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]));

        // once the invoice is paid the same order is accepted: the limit is measured against what is still owed
        $this->expectRejected($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]), 'CREDIT_LIMIT', [422]);
        $this->expectOk($this->postAs('finance', '/api/finance/receipts', ['customer' => $customer->code, 'amount' => 236000, 'reference' => 'TRF-CREDIT', 'allocations' => [['invoice' => $invoice->number, 'amount' => 236000]]]));
        $this->assertSame('34500.00', Customer::find($customer->id)->balance);
        $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 100, 'price' => 3000]]));
        $this->assertSame('379500.00', Customer::find($customer->id)->balance);
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

    public function test_a_discount_above_the_limit_needs_the_discount_approver_on_a_direct_order(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B1', 50);
        $order = fn (string $user, float $disc) => $this->orderFor($customer, [['sku' => $product->sku, 'qty' => 2, 'price' => 100, 'discPct' => $disc]], $user);

        // at the limit (30 %) anyone who may sell may give it; nobody is stamped
        $ok = $this->expectOk($order('sales', 30));
        $this->assertNull($ok['discountApprovedBy']);
        $this->assertEquals(161, $ok['totals']['total']);
        // above it the order is refused for a user without discount.approve — and nothing is created or reserved
        $refused = $this->expectRejected($order('sales', 45), 'DISCOUNT_APPROVAL', [422]);
        $this->assertEquals(['discountPct' => 45, 'maxPct' => 30], $refused['details']);
        $this->assertSame(1, SalesOrder::where('customer_id', $customer->id)->count());
        $this->assertSame(2, $this->reserved($product));
        // a holder of the permission may, and the order carries who allowed it
        $approved = $this->expectOk($order('admin', 45));
        $this->assertSame('admin', $approved['discountApprovedBy']);
        $this->assertStringContainsString('"discountApprovedBy":"admin"', AuditLog::where('action', 'SO.CREATE')->where('entity_number', $approved['number'])->value('new_value'));
        $this->expectRejected($order('sales', 101), 'INVALID_INPUT', [400]);
        $this->assertReconciles();
    }

    public function test_cancel_releases_reservations_and_the_customer_exposure(): void
    {
        $customer = $this->makeCustomer();
        $this->receivable($customer, 500);
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-3-B2', 40);
        $so = $this->expectOk($this->orderFor($customer, [['sku' => $product->sku, 'qty' => 25, 'price' => 100]]));
        $this->assertSame('3375.00', Customer::find($customer->id)->balance);
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill")); // FO still "alloc": cancellable
        $this->assertSame(25, $this->reserved($product));

        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('sales', "/api/sales/orders/{$so['number']}/cancel", ['reason' => 'طلب العميل'])));
        $this->assertSame(0, $this->reserved($product));
        $this->assertSame(40, $this->inventory()->availability($product->id, $this->ryd()->id)['total']);
        $this->assertSame(0, InventoryReservation::where('so_id', $so['id'])->where('status', 'active')->count());
        $this->assertSame(0, InventoryAllocation::where('product_id', $product->id)->where('status', 'active')->count());
        $this->assertSame('500.00', Customer::find($customer->id)->balance, 'only the unpaid invoice is left');
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

    /**
     * The state machine allows picking → cancelled for the warehouse's put-back (the goods go back to their bins first).
     * A plain cancel never does that, so it stays refused once the picker started.
     */
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
        $this->assertSame('46.00', Customer::find($customer->id)->balance, 'still owed: the order is still open');
        $this->assertReconciles();
    }

    public function test_allocate_reserves_what_is_available_for_an_order_left_waiting_for_stock(): void
    {
        $customer = $this->makeCustomer();
        $product = $this->makeProduct();
        // an order imported / left in "confirmed" (waiting allocation): no reservation yet
        $waiting = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed', 'due_date' => now()->addDay()]);
        SalesOrderLine::create(['so_id' => $waiting->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 15, 'price' => 20]);
        $allocate = "/api/sales/orders/{$waiting->number}/allocate";
        $this->assertSame([$waiting->number], array_column($this->expectOk($this->getAs('sales', "/api/sales/orders?waiting=allocation&customer={$customer->code}"))['items'], 'number'));

        $this->expectRejected($this->postAs('worker', $allocate), 'FORBIDDEN', [403]); // so.allocate: sales and the warehouse manager, not a picker
        // nothing available: refused, nothing changed
        $none = $this->expectRejected($this->postAs('sales', $allocate), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([$product->sku, 0, 15], [$none['details']['sku'], $none['details']['available'], $none['details']['requested']]);
        $this->expectRejected($this->postAs('wm', "/api/sales/orders/{$waiting->number}/fulfill"), 'SO_NOT_ALLOCATED', [422]);
        $this->assertSame(['confirmed', null], [$waiting->refresh()->status, $waiting->fulfil_status]);

        // part of it arrived: the order takes it and keeps waiting for the rest
        $this->stock($product, 'A-07-3-B4', 6);
        $part = $this->expectOk($this->postAs('sales', $allocate));
        $this->assertSame(['confirmed', 'partial'], [$part['status'], $part['fulfilStatus']]);
        $this->assertSame([6, 6], [$part['lines'][0]['reservedQty'], $part['lines'][0]['allocatedQty']]);
        $this->assertSame(['complete' => false, 'gained' => 6, 'missing' => [['sku' => $product->sku, 'nameAr' => $product->name_ar, 'need' => 9]]], $part['allocation']);
        $this->assertSame([[$product->sku, 9, 0]], array_map(fn ($w) => [$w['sku'], $w['need'], $w['available']], $part['waiting']));
        $this->assertTrue(AuditLog::where('action', 'SO.ALLOCATE_PARTIAL')->where('entity_number', $waiting->number)->exists());
        $this->expectRejected($this->postAs('wm', "/api/sales/orders/{$waiting->number}/fulfill"), 'SO_NOT_ALLOCATED', [422]);
        $this->expectRejected($this->postAs('wm', $allocate), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(6, $this->reserved($product));

        // the rest arrived
        $this->stock($product, 'A-07-3-B4', 9);
        $so = $this->expectOk($this->postAs('wm', $allocate));
        $this->assertSame(['allocated', 'full'], [$so['status'], $so['fulfilStatus']]);
        $this->assertSame([15, 15], [$so['lines'][0]['reservedQty'], $so['lines'][0]['allocatedQty']]);
        $this->assertSame('A-07-3-B4', $so['lines'][0]['allocations'][0]['bin']['code']);
        $this->assertTrue($so['allocation']['complete']);
        $this->assertSame(15, $this->reserved($product));
        $this->expectRejected($this->postAs('admin', $allocate), 'SO_NOT_WAITING', [422]);

        // an order with nothing left on it (everything it asked for moved to a backorder) is not an order to reserve for
        $empty = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => $customer->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'confirmed', 'due_date' => now()->addDay()]);
        SalesOrderLine::create(['so_id' => $empty->id, 'line_no' => 1, 'product_id' => $product->id, 'qty' => 0, 'ordered_qty' => 5, 'short_qty' => 5, 'price' => 20]);
        $this->expectRejected($this->postAs('sales', "/api/sales/orders/{$empty->number}/allocate"), 'SO_EMPTY', [422]);
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

    public function test_the_pick_walk_goes_ambient_then_chilled_and_frozen_last(): void
    {
        $bin = fn (string $zoneType) => Bin::where('warehouse_id', $this->ryd()->id)->where('status', 'active')->whereHas('zone', fn ($z) => $z->where('type', $zoneType))->orderBy('code')->value('code');
        [$frozenBin, $chilledBin, $hazmatBin] = [$bin('frozen'), $bin('chilled'), $bin('hazmat')];
        $frozen = $this->makeProduct('frozen');
        $chilled = $this->makeProduct('chilled');
        $hazmat = $this->makeProduct('ambient');   // hazmat goods are ambient goods kept in the hazmat zone
        $ambient = $this->makeProduct('ambient');
        $this->stock($frozen, $frozenBin, 5);
        $this->stock($chilled, $chilledBin, 5);
        $this->stock($hazmat, $hazmatBin, 5);
        $this->stock($ambient, 'C-01-1-B1', 5);
        // ordered coldest first, and the zone codes alone (C · CH · FZ · HZ) would put hazmat after frozen
        $so = $this->expectOk($this->orderFor($this->makeCustomer(), array_map(fn ($p) => ['sku' => $p->sku, 'qty' => 2, 'price' => 10], [$frozen, $chilled, $hazmat, $ambient])));
        $fo = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/fulfill"));
        $tasks = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$fo['number']}"))['pickLists'][0]['tasks'];
        $this->assertSame([[1, 'C-01-1-B1'], [2, $hazmatBin], [3, $chilledBin], [4, $frozenBin]], array_map(fn ($t) => [$t['seq'], $t['bin']['code']], $tasks), 'ambient, hazmat, chilled, frozen');
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
        $this->expectOk($this->getAs('driver', '/api/sales/policy'));
    }
}
