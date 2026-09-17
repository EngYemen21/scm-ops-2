<?php

namespace Tests\Feature\Sales;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DispatchRecord;
use App\Models\Driver;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\InventoryAllocation;
use App\Models\InventoryMovement;
use App\Models\LoadingPlan;
use App\Models\OpsException;
use App\Models\PickList;
use App\Models\ProductBarcode;
use App\Models\SalesOrder;
use App\Models\StagingEntry;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use Tests\ApiTestCase;

/**
 * The outbound story of the reference core-flow / acceptance suites on self-made data:
 * fulfill → pick (negatives, partial, short) → pack → loading plan → load (negatives) → dispatch.
 * The tests of this class run in order and share the documents created by the first one.
 */
class FulfillmentFlowTest extends ApiTestCase
{
    use SalesFixtures;

    /** @var array<string, mixed> state carried from one step of the story to the next */
    private static array $s = [];

    public function test_1_pick_wrong_scans_over_pick_partial_and_short_pick(): void
    {
        $product = $this->makeProduct('ambient', 2);
        $second = $this->makeProduct('ambient', 1);
        $this->stock($product, 'A-07-1-B1', 100);
        $this->stock($second, 'A-07-2-B1', 40);
        ProductBarcode::create(['product_id' => $product->id, 'barcode' => '628'.self::uid().'01', 'is_primary' => true]);
        $first = $this->makeCustomer();   // stop 1 (delivered first → loaded last)
        $last = $this->makeCustomer();    // stop 2 (delivered last → loaded first)
        $soA = $this->expectOk($this->orderFor($first, [['sku' => $product->sku, 'qty' => 20, 'price' => 10], ['sku' => $second->sku, 'qty' => 10, 'price' => 5]]));
        $soB = $this->expectOk($this->orderFor($last, [['sku' => $product->sku, 'qty' => 10, 'price' => 10]]));
        $soC = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]])); // never picked: leaves the trip at dispatch
        $foA = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$soA['number']}/fulfill"))['number'];
        $foC = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$soC['number']}/fulfill"))['number'];
        self::$s = compact('product', 'second', 'soA', 'soB', 'soC', 'foA', 'foC');

        $open = $this->expectOk($this->getAs('worker', '/api/fulfillment/pick-lists?warehouse=RYD&pageSize=500'));
        $mine = collect($open['items'])->first(fn ($pl) => $pl['fo']['number'] === $foA);
        $this->assertNotNull($mine, 'the new pick list is open');
        $this->assertSame(['number', 'status', 'customer'], array_keys($mine['fo']));
        $this->assertSame(['nameAr', 'zone'], array_keys($mine['fo']['customer']));

        $doc = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$foA}"));
        $this->assertSame(['code', 'nameAr', 'nameEn', 'zone', 'contact', 'address'], array_keys($doc['customer']));
        $this->assertSame(['number', 'status', 'window', 'dueDate', 'priority'], array_keys($doc['so']));
        $this->assertSame(['sku', 'nameAr', 'nameEn', 'weightKg', 'storageClass', 'barcodes'], array_keys($doc['lines'][0]['product']));
        $this->assertCount(1, $doc['lines'][0]['product']['barcodes']);
        [$t, $t2] = $doc['pickLists'][0]['tasks'];
        $this->assertSame(['code' => 'A-07-1-B1', 'zone' => ['code' => 'A']], $t['bin']);
        $this->assertSame([20, 10], [$t['qty'], $t2['qty']]);
        $confirm = "/api/fulfillment/pick-tasks/{$t['id']}/confirm";
        $scan = ['scannedBin' => 'A-07-1-B1', 'scannedProduct' => $product->sku];
        $movements = InventoryMovement::count();

        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => 'Z-99', 'scannedProduct' => $product->sku]), 'WRONG_BIN', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedBin' => 'A-07-1-B1', 'scannedProduct' => 'P00000']), 'WRONG_PRODUCT', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, $scan + ['qty' => 25]), 'OVER_PICK', [422]);
        $this->expectRejected($this->postAs('worker', $confirm, ['scannedProduct' => $product->sku]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', $confirm, $scan + ['qty' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', $confirm, $scan), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/pick-tasks/NOPE/confirm', $scan), 'TASK_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/orders/{$foA}/pack", ['cartons' => 1]), 'PACK_STATE', [422]);
        $this->assertSame($movements, InventoryMovement::count(), 'rejected scans move nothing');
        $this->assertSame(100, $this->onHand($product, 'A-07-1-B1'));

        // partial pick (scanned by barcode, lower-case bin), then the rest
        $p1 = $this->expectOk($this->postAs('worker', $confirm, ['scannedBin' => 'a-07-1-b1', 'scannedProduct' => ProductBarcode::where('product_id', $product->id)->value('barcode'), 'qty' => 19]));
        $this->assertSame([19, 1, false, false], [$p1['picked'], $p1['remaining'], $p1['lineDone'], $p1['orderDone']]);
        $this->assertStringStartsWith('TX-', $p1['movement']);
        $this->assertSame('picking', FulfillmentOrder::where('number', $foA)->value('status'));
        $this->assertSame('picking', SalesOrder::where('number', $soA['number'])->value('status'));
        $this->assertSame([81, 19], [$this->onHand($product, 'A-07-1-B1'), $this->onHand($product, 'PACK')]);
        $this->assertReconciles();
        $p2 = $this->expectOk($this->postAs('worker', $confirm, $scan + ['qty' => 1]));
        $this->assertSame([true, false], [$p2['lineDone'], $p2['orderDone']]);
        $this->expectRejected($this->postAs('worker', $confirm, $scan), 'TASK_DONE', [409]);
        $this->assertSame('consumed', InventoryAllocation::where('so_line_id', $soA['lines'][0]['id'])->value('status'));

        // short pick on the second line: 6 of 10 found, the rest is released and reported
        $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$t2['id']}/confirm", ['scannedBin' => 'A-07-2-B1', 'scannedProduct' => $second->sku, 'qty' => 6]));
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$t2['id']}/short"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', "/api/fulfillment/pick-tasks/{$t2['id']}/short", ['reason' => 'x']), 'FORBIDDEN', [403]);
        $short = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$t2['id']}/short", ['reason' => 'الموقع فارغ']));
        $this->assertSame(['ok' => true, 'shortQty' => 4], $short);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/pick-tasks/{$t['id']}/short", ['reason' => 'x']), 'TASK_CLOSED', [409]);
        $this->assertSame(0, $this->reserved($second), 'the unpicked reservation is released');
        $this->assertSame(34, $this->inventory()->availability($second->id, $this->ryd()->id)['total']);
        $exception = OpsException::where('kind', 'missing')->where('entity_number', $foA)->first();
        $this->assertSame(['w', 'wm', 'open'], [$exception->severity, $exception->owner_role, $exception->status]);

        $after = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$foA}"));
        $this->assertSame('picked', $after['status']);
        $this->assertSame([[20, 20], [6, 6]], array_map(fn ($l) => [$l['qty'], $l['pickedQty']], $after['lines']));
        $this->assertSame(['done', 'short'], array_column($after['pickLists'][0]['tasks'], 'status'));
        $this->assertSame('done', $after['pickLists'][0]['status']);
        $this->assertSame(['picking', 'picked'], array_column($after['history'], 'toStatus'));
        $this->assertSame(['pick', 'pick', 'pick'], array_column($after['movements'], 'type'));
        $this->assertSame(['number', 'type', 'qty', 'batchNo', 'createdAt', 'srcBin', 'dstBin', 'product'], array_keys($after['movements'][0]));
        $this->assertSame([['code' => 'A-07-1-B1'], ['code' => 'PACK']], [$after['movements'][0]['srcBin'], $after['movements'][0]['dstBin']]);
        $this->assertTrue(AuditLog::where('action', 'PICK.CONFIRM')->where('entity_id', $t['id'])->exists());
        $this->assertTrue(AuditLog::where('action', 'PICK.SHORT')->where('entity_id', $t2['id'])->exists());
        $this->assertSame(0, PickList::where('fo_id', $after['id'])->where('status', 'open')->count());
        $this->assertReconciles();
    }

    public function test_2_pack_moves_pack_to_outbound_staging(): void
    {
        ['product' => $product, 'second' => $second, 'foA' => $foA, 'soA' => $soA, 'soB' => $soB] = self::$s;
        $this->expectRejected($this->postAs('sales', "/api/fulfillment/orders/{$foA}/pack", ['cartons' => 3]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/orders/{$foA}/pack", ['cartons' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/orders/FO-NOPE/pack'), 'FO_NOT_FOUND', [404]);

        $packed = $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$foA}/pack", ['cartons' => 9, 'weightKg' => 212, 'volumeM3' => 0.9]));
        $this->assertSame('packed', $packed['status']);
        $this->assertStringStartsWith('PKG-', $packed['package']);
        $this->assertSame([0, 20], [$this->onHand($product, 'PACK'), $this->onHand($product, 'STG-OUT')]);
        $this->assertSame([0, 6], [$this->onHand($second, 'PACK'), $this->onHand($second, 'STG-OUT')]);
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/orders/{$foA}/pack"), 'PACK_STATE', [422]);

        $doc = $this->expectOk($this->getAs('disp', "/api/fulfillment/orders/{$foA}"));
        $this->assertSame(['packed', 9, 212, 0.9], [$doc['status'], $doc['cartons'], (int) $doc['weightKg'], $doc['cbm']]);
        $this->assertSame([20, 6], array_column($doc['lines'], 'packedQty'));
        $this->assertSame([[$packed['package'], 9, "LBL-{$foA}", 'worker']], array_map(fn ($p) => [$p['number'], $p['cartons'], $p['labelRef'], $p['packedBy']], $doc['packages']));
        $this->assertSame('packed', SalesOrder::where('number', $soA['number'])->value('status'));
        $this->assertSame(26, StagingEntry::where('reference_id', $doc['id'])->where('direction', 'out')->where('status', 'waiting')->value('qty'));
        $this->assertTrue(IntegrationEvent::where('type', 'OrderPacked')->where('payload->fo', $foA)->exists());

        // second order: body-less pack (cartons default to 1, weight stays the order weight)
        self::$s['foB'] = $foB = $this->expectOk($this->postAs('wm', "/api/sales/orders/{$soB['number']}/fulfill"))['number'];
        $task = $this->expectOk($this->getAs('worker', "/api/fulfillment/orders/{$foB}"))['pickLists'][0]['tasks'][0];
        $done = $this->expectOk($this->postAs('worker', "/api/fulfillment/pick-tasks/{$task['id']}/confirm", ['scannedBin' => 'A-07-1-B1', 'scannedProduct' => $product->sku]));
        $this->assertSame([10, 0, true, true], [$done['picked'], $done['remaining'], $done['lineDone'], $done['orderDone']]);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$foB}/pack"));
        $foBRow = FulfillmentOrder::where('number', $foB)->first();
        $this->assertSame(['packed', 1, 20.0], [$foBRow->status, $foBRow->cartons, $foBRow->weight_kg]);
        $this->assertSame(30, $this->onHand($product, 'STG-OUT'));
        $this->assertSame([[$foB, 'packed']], array_map(fn ($f) => [$f['number'], $f['status']], $this->expectOk($this->getAs('wm', "/api/fulfillment/orders?status=active&warehouse=RYD&q={$foB}"))['items']));
        $this->assertSame(0, $this->expectOk($this->getAs('wm', "/api/fulfillment/orders?status=alloc&q={$foB}"))['total']);
        $this->assertReconciles();
    }

    public function test_3_loading_plan_and_load_rules(): void
    {
        ['foA' => $foA, 'foB' => $foB, 'foC' => $foC, 'product' => $product] = self::$s;
        $vehicle = $this->makeVehicle('dry', 3000, 16);
        $driver = $this->makeDriver();
        $trip = $this->makeTrip([$foA, $foB, $foC], $vehicle, $driver);
        self::$s += ['trip' => $trip->number, 'vehicle' => $vehicle->code, 'driver' => $driver->code];
        $load = "/api/fulfillment/trips/{$trip->number}/load";

        $plan = $this->expectOk($this->getAs('disp', "/api/fulfillment/trips/{$trip->number}/loading"));
        $this->assertSame([$foC, $foB, $foA], array_column($plan['rows'], 'fo'), 'reverse delivery order: the last stop loads first');
        $this->assertSame($foB, $plan['nextToLoad'], 'the highest stop that is packed');
        $this->assertSame([false, true, true], array_column($plan['rows'], 'ready'));
        $this->assertSame(['code' => $vehicle->code, 'plateAr' => $vehicle->plate_ar, 'plateEn' => $vehicle->plate_en, 'state' => 'available', 'maxKg' => 3000, 'maxCbm' => 16, 'pallets' => 8, 'kind' => 'dry'], array_map(fn ($v) => is_float($v) ? (int) $v : $v, $plan['trip']['vehicle']));
        $this->assertSame(['code' => $driver->code, 'nameAr' => $driver->name_ar], $plan['trip']['driver']);
        $this->assertEquals([0, 0, 0, 0], [$plan['totals']['loadedKg'], $plan['totals']['loadedCbm'], $plan['totals']['utilKg'], $plan['totals']['utilCbm']]);
        $this->expectRejected($this->getAs('disp', '/api/fulfillment/trips/TRP-NOPE/loading'), 'TRIP_NOT_FOUND', [404]);

        $this->expectRejected($this->postAs('sales', $load, ['foNumber' => $foB]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $load), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foA]), 'LOAD_ORDER', [422]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foB, 'scannedVehicle' => 'XYZ 9999']), 'WRONG_VEHICLE', [422]);
        $wrongVehicle = OpsException::where('kind', 'wrongveh')->where('entity_number', $trip->number)->get();
        $this->assertCount(1, $wrongVehicle, 'the exception survives the rejection');
        $this->assertSame(['c', 'disp', $foB], [$wrongVehicle[0]->severity, $wrongVehicle[0]->owner_role, $wrongVehicle[0]->document_number]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foB, 'scannedOrder' => 'FO-0000']), 'WRONG_ORDER', [422]); // wrong-order loading
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foC]), 'NOT_PACKED', [422]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => 'FO-NOT-HERE']), 'FO_NOT_ON_TRIP', [422]);

        // over weight: a heavy packed order on a later stop
        $heavy = FulfillmentOrder::create(['number' => self::code('FO-T-HEAVY'), 'customer_id' => Customer::firstOrFail()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'packed', 'seq' => 9, 'cartons' => 10, 'weight_kg' => 3600, 'cbm' => 1, 'trip_id' => $trip->id]);
        TripStop::create(['trip_id' => $trip->id, 'seq' => 9, 'customer_id' => $heavy->customer_id, 'customer_ar' => 'اختبار وزن', 'fo_id' => $heavy->id]);
        TripOrder::create(['trip_id' => $trip->id, 'fo_id' => $heavy->id]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $heavy->number, 'scannedVehicle' => $vehicle->code]), 'OVER_WEIGHT', [422]);
        $this->assertSame(1, OpsException::where('kind', 'capacity')->where('entity_number', $trip->number)->count());
        $heavy->update(['weight_kg' => 10, 'cbm' => 40]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $heavy->number]), 'OVER_VOLUME', [422]);
        TripOrder::where('fo_id', $heavy->id)->delete();
        TripStop::where('fo_id', $heavy->id)->delete();
        $heavy->delete();

        // a vehicle that went to maintenance cannot be loaded
        $vehicle->update(['state' => 'maintenance']);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foB]), 'VEHICLE_STATE', [422]);
        $vehicle->update(['state' => 'available']);
        $this->assertSame('dassigned', Trip::find($trip->id)->status, 'rejections change nothing');
        $this->assertSame(30, $this->onHand($product, 'STG-OUT'));

        $l1 = $this->expectOk($this->postAs('worker', $load, ['foNumber' => $foB, 'scannedVehicle' => strtolower($vehicle->plate_en), 'scannedOrder' => $foB]));
        $this->assertSame([$foB, $vehicle->code, 1], [$l1['fo'], $l1['vehicle'], $l1['seq']]);
        $this->assertEquals([20, 0.1], [$l1['loadedKg'], $l1['loadedCbm']]);
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foB]), 'ALREADY_LOADED', [409]); // duplicate loading
        $this->assertSame(20, $this->onHand($product, 'STG-OUT'), 'the goods left warehouse stock once');
        $this->assertSame(['loading', 'loading'], [Trip::find($trip->id)->status, Vehicle::find($vehicle->id)->state]);
        $this->assertSame(['loaded', 'loaded'], [FulfillmentOrder::where('number', $foB)->value('status'), SalesOrder::where('number', self::$s['soB']['number'])->value('status')]);

        $next = $this->expectOk($this->getAs('worker', "/api/fulfillment/trips/{$trip->number}/loading"));
        $this->assertSame($foA, $next['nextToLoad']);
        $this->assertEquals([20, 1], [$next['totals']['loadedKg'], $next['totals']['utilKg']]);
        $l2 = $this->expectOk($this->postAs('worker', $load, ['foNumber' => strtolower($foA), 'scannedVehicle' => $vehicle->code]));
        $this->assertSame(2, $l2['seq']);
        $this->assertEquals([232, 1.0], [$l2['loadedKg'], $l2['loadedCbm']]);
        $this->assertSame([0, 0], [$this->onHand($product, 'STG-OUT'), $this->onHand(self::$s['second'], 'STG-OUT')]);
        $plan = LoadingPlan::with('lines')->where('trip_id', $trip->id)->firstOrFail();
        $this->assertSame(['open', 232.0, 2], [$plan->status, $plan->total_kg, $plan->lines->count()]);
        $this->assertSame(0, StagingEntry::whereIn('reference_number', [$foA, $foB])->where('status', 'waiting')->count());
        $this->assertReconciles();
    }

    public function test_4_dispatch_rules_and_side_effects(): void
    {
        ['trip' => $trip, 'foA' => $foA, 'foB' => $foB, 'foC' => $foC, 'vehicle' => $vehicle, 'driver' => $driver, 'soA' => $soA, 'soC' => $soC] = self::$s;
        $dispatch = "/api/fulfillment/trips/{$trip}/dispatch";
        $tripId = Trip::where('number', $trip)->value('id');

        // dispatch without permission: 403 and nothing changed
        $this->expectRejected($this->postAs('worker', $dispatch), 'FORBIDDEN', [403]);
        $this->assertSame('loading', Trip::find($tripId)->status);
        $this->assertSame(0, DispatchRecord::where('trip_id', $tripId)->count());

        // open critical exceptions of the loading attempts block dispatch until the dispatcher handles them
        $this->expectRejected($this->postAs('disp', $dispatch), 'BLOCKING_EXCEPTIONS', [422]);
        foreach (OpsException::where('entity_number', $trip)->where('status', '!=', 'resolved')->where('severity', 'c')->get() as $e) {
            $this->expectOk($this->postAs('disp', "/api/exceptions/{$e->number}/resolve", ['note' => 'تمت المعالجة — تحميل صحيح']));
        }

        // driver whose license expired
        Driver::where('code', $driver)->update(['license_expiry' => now()->subDay()]);
        $this->expectRejected($this->postAs('disp', $dispatch), 'DRIVER_STATE', [422]);
        Driver::where('code', $driver)->update(['license_expiry' => now()->addYear()]);

        $events = IntegrationEvent::where('type', 'ShipmentDispatched')->count();
        $d = $this->expectOk($this->postAs('disp', $dispatch)); // action button: no body
        $this->assertSame([$trip, 'onroute', 2], [$d['trip'], $d['status'], $d['orders']]);
        $this->expectRejected($this->postAs('disp', $dispatch), 'DISPATCH_DUPLICATE', [409]);

        $row = Trip::find($tripId);
        $this->assertSame('onroute', $row->status);
        $this->assertNotNull($row->dispatched_at);
        $this->assertNotNull($row->actual_start);
        $this->assertSame(['onroute', 'onroute'], [Vehicle::where('code', $vehicle)->value('state'), Driver::where('code', $driver)->value('state')]);
        $this->assertSame(['onroute', 'onroute'], [FulfillmentOrder::where('number', $foA)->value('status'), FulfillmentOrder::where('number', $foB)->value('status')]);
        $so = SalesOrder::where('number', $soA['number'])->first();
        $this->assertSame(['outfordel', $tripId], [$so->status, $so->trip_id]);
        $record = DispatchRecord::where('trip_id', $tripId)->firstOrFail();
        $this->assertSame([2, 232.0, 'disp'], [$record->orders_count, $record->total_kg, $record->dispatched_by]);
        $this->assertSame('completed', LoadingPlan::where('trip_id', $tripId)->value('status'));
        $this->assertSame($events + 2, IntegrationEvent::where('type', 'ShipmentDispatched')->count());

        // the order that was never loaded left the trip and its stop is skipped
        $left = FulfillmentOrder::where('number', $foC)->first();
        $this->assertSame([null, 'alloc'], [$left->trip_id, $left->status]);
        $this->assertSame(0, TripOrder::where('trip_id', $tripId)->where('fo_id', $left->id)->count());
        $this->assertSame('skipped', TripStop::where('trip_id', $tripId)->where('fo_id', $left->id)->value('status'));
        $this->assertSame('preparing', SalesOrder::where('number', $soC['number'])->value('status'));
        $this->assertTrue(AuditLog::where('action', 'TRIP.REMOVE_ORDER')->where('entity_number', $trip)->where('old_value', $foC)->exists());

        // the audit trail is server-side and complete for the flow
        $trail = AuditLog::where('action', 'STATUS')->whereIn('entity_number', [$foA, $trip])->get()->map(fn ($r) => "{$r->entity_type}:{$r->new_value}")->all();
        foreach (['FulfillmentOrder:picked', 'FulfillmentOrder:packed', 'FulfillmentOrder:loaded', 'FulfillmentOrder:onroute', 'Trip:loading', 'Trip:onroute'] as $expected) {
            $this->assertContains($expected, $trail);
        }
        $doc = $this->expectOk($this->getAs('sales', "/api/fulfillment/orders/{$foA}"));
        $this->assertSame(['number' => $trip, 'status' => 'onroute', 'vehicle' => ['code' => $vehicle, 'plateAr' => Vehicle::where('code', $vehicle)->value('plate_ar'), 'plateEn' => Vehicle::where('code', $vehicle)->value('plate_en')], 'driver' => ['code' => $driver, 'nameAr' => Driver::where('code', $driver)->value('name_ar')]], $doc['trip']);
        $this->assertSame(['pick', 'pick', 'pick', 'pack', 'pack', 'load', 'load'], array_column($doc['movements'], 'type'));
        $this->assertSame([$foA], array_column($this->expectOk($this->getAs('disp', "/api/fulfillment/orders?trip={$trip}&status=onroute&customer=".Customer::find($doc['customerId'])->code))['items'], 'number'));
        $this->assertReconciles();
    }

    public function test_5_dispatch_preconditions(): void
    {
        $product = $this->makeProduct();
        $this->stock($product, 'A-07-2-B2', 20);
        $so = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $fo = $this->packedOrder($so['number']);

        // no vehicle: nothing can be loaded
        $trip = $this->makeTrip([$fo], null, null, 'planned');
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]), 'NO_VEHICLE', [422]);
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"), 'TRIP_STATE', [422]);

        $trip->update(['vehicle_id' => $this->makeVehicle()->id]);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->id}/load", ['foNumber' => $fo]));
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"), 'NO_DRIVER', [422]);
        $trip->update(['driver_id' => $this->makeDriver(['blocked' => true])->id]);
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"), 'DRIVER_STATE', [422]);

        // a second packed order that is not on the truck yet blocks dispatch
        $so2 = $this->expectOk($this->orderFor($this->makeCustomer(), [['sku' => $product->sku, 'qty' => 5, 'price' => 10]]));
        $fo2 = FulfillmentOrder::where('number', $this->packedOrder($so2['number']))->firstOrFail();
        TripStop::create(['trip_id' => $trip->id, 'seq' => 2, 'customer_id' => $fo2->customer_id, 'customer_ar' => 'عميل ثانٍ', 'fo_id' => $fo2->id]);
        TripOrder::create(['trip_id' => $trip->id, 'fo_id' => $fo2->id]);
        $fo2->update(['trip_id' => $trip->id]);
        $trip->update(['driver_id' => $this->makeDriver()->id]);
        $this->expectRejected($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"), 'INCOMPLETE_LOADING', [422]);
        $this->assertSame('loading', Trip::find($trip->id)->status);

        // a trip already on the road cannot be loaded
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo2->number]));
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"));
        $this->expectRejected($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]), 'TRIP_STATE', [422]);
        $this->assertSame(0, $this->onHand($product, 'STG-OUT'));
        $this->assertReconciles();
    }
}
