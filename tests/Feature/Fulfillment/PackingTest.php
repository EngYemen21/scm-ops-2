<?php

namespace Tests\Feature\Fulfillment;

use App\Models\AuditLog;
use App\Models\Bin;
use App\Models\Carton;
use App\Models\Customer;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\InventoryMovement;
use App\Models\InventoryReservation;
use App\Models\Package;
use App\Models\PickList;
use App\Models\PickTask;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StagingEntry;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Services\Fulfillment\ScanCodes;
use Tests\ApiTestCase;

/** Cartons, the correction of a packed order, and the two ways back: unpack (STG-OUT → PACK) and put-back (PACK → bins). */
class PackingTest extends ApiTestCase
{
    use FulfillmentFixtures;

    public function test_carton_weights_and_the_correction_of_a_packed_order(): void
    {
        $p = $this->makeProduct('ambient', 2);
        $this->stock($p, 'A-07-1-B2', 40);
        $so = $this->order([['sku' => $p->sku, 'qty' => 6, 'price' => 10]]);
        $fo = $this->pickedOrder($so['number']);
        $foId = FulfillmentOrder::where('number', $fo)->value('id');
        $edit = "/api/fulfillment/orders/{$fo}/package";

        $this->expectRejected($this->patchAs('worker', $edit, ['cartons' => 2]), 'PACKAGE_STATE', [422]); // not packed yet
        $packed = $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$fo}/pack", ['cartons' => 3, 'cartonWeights' => [5, 6.5, 7]]));
        $this->assertSame([[1, 5.0], [2, 6.5], [3, 7.0]], array_map(fn ($c) => [$c['seq'], (float) $c['weightKg']], $packed['cartonList']));
        $this->assertSame(18.5, FulfillmentOrder::find($foId)->weight_kg, 'the order weighs what its cartons weigh');

        $this->expectRejected($this->patchAs('sales', $edit, ['cartons' => 2]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->patchAs('worker', $edit), 'NO_CHANGES', [400]);
        $this->expectRejected($this->patchAs('worker', $edit, ['cartons' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('worker', $edit, ['cartonWeights' => [1, 2]]), 'CARTON_WEIGHTS', [400]);
        $this->expectRejected($this->patchAs('worker', '/api/fulfillment/orders/FO-NOPE/package', ['cartons' => 2]), 'FO_NOT_FOUND', [404]);
        // a packed order that has no package row (written by hand / before packages existed) has nothing to correct
        $bare = FulfillmentOrder::create(['number' => self::code('FO-T-BARE'), 'customer_id' => Customer::firstOrFail()->id, 'warehouse_id' => $this->ryd()->id, 'status' => 'packed', 'seq' => 1, 'cartons' => 1, 'weight_kg' => 5, 'cbm' => 0.1]);
        $this->expectRejected($this->patchAs('worker', "/api/fulfillment/orders/{$bare->number}/package", ['cartons' => 2]), 'PACKAGE_NOT_FOUND', [404]);
        $bare->delete();

        // same number of cartons: the labels stay, the weights follow
        $same = $this->expectOk($this->patchAs('worker', $edit, ['weightKg' => 21]));
        $this->assertSame([false, 3, 21.0], [$same['cartonsReissued'], $same['cartons'], (float) $same['weightKg']]);
        $this->assertSame([7.0, 7.0, 7.0], Carton::where('fo_id', $foId)->where('status', 'packed')->orderBy('seq')->pluck('weight_kg')->all());
        $this->assertSame(3, Carton::where('fo_id', $foId)->count());

        // another number of cartons: the old labels are void, new ones are issued under the same codes
        $re = $this->expectOk($this->patchAs('worker', $edit, ['cartons' => 2, 'cartonWeights' => [10, 12], 'volumeM3' => 0.4]));
        $this->assertSame([true, 2, 22.0, 0.4], [$re['cartonsReissued'], $re['cartons'], (float) $re['weightKg'], (float) $re['volumeM3']]);
        $this->assertSame(["{$fo}-C01", "{$fo}-C02"], array_column($re['cartonList'], 'code'));
        $this->assertSame([2, 3], [Carton::where('fo_id', $foId)->where('status', 'packed')->count(), Carton::where('fo_id', $foId)->where('status', 'void')->count()]);
        $this->assertNull(ScanCodes::fo("{$fo}-C03"), 'a carton that no longer exists identifies nothing');
        $this->assertSame($foId, ScanCodes::fo("{$fo}-C02")?->id);
        $pkg = Package::where('fo_id', $foId)->firstOrFail();
        $this->assertSame([2, 22.0, 0.4, 'packed'], [$pkg->cartons, $pkg->weight_kg, $pkg->volume_m3, $pkg->status]);
        $row = FulfillmentOrder::find($foId);
        $this->assertSame([2, 22.0, 0.4], [$row->cartons, $row->weight_kg, $row->cbm]);
        $this->assertSame(['worker'], AuditLog::where('action', 'PACK.EDIT')->where('entity_number', $fo)->pluck('username')->unique()->values()->all());
        $this->assertSame(2, AuditLog::where('action', 'PACK.EDIT')->where('entity_number', $fo)->count(), 'every correction is on record');

        // on a trip: the trip's load follows, and a load beyond the vehicle is announced before the dock refuses it
        $trip = $this->makeTrip([$fo], $this->makeVehicle('dry', 25, 16), $this->makeDriver());
        $onTrip = $this->expectOk($this->patchAs('worker', $edit, ['weightKg' => 30, 'volumeM3' => 1.2]));
        $this->assertSame([$trip->number, 30.0, 1.2, 1], [$onTrip['trip']['number'], (float) $onTrip['trip']['kg'], (float) $onTrip['trip']['cbm'], $onTrip['trip']['pallets']]);
        $this->assertNotNull($onTrip['warning']);
        $this->assertSame([30.0, 1.2, 1], array_values(Trip::find($trip->id)->only(['kg', 'cbm', 'pallets'])));
        $this->assertSame(30.0, (float) TripStop::where('trip_id', $trip->id)->value('kg'));
        $this->assertNull($this->expectOk($this->patchAs('worker', $edit, ['weightKg' => 20]))['warning']);

        // once it is on the truck the package is what was loaded
        $this->expectOk($this->loadScanned($trip->number, $fo));
        $this->expectRejected($this->patchAs('worker', $edit, ['cartons' => 5]), 'PACKAGE_STATE', [422]);
        $this->assertReconciles();
    }

    public function test_unpack_brings_the_order_back_to_the_packing_station(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-1-B3', 40);
        $so = $this->order([['sku' => $p->sku, 'qty' => 5, 'price' => 10]]);
        $fo = $this->packedOrder($so['number']);
        $foId = FulfillmentOrder::where('number', $fo)->value('id');
        $unpack = "/api/fulfillment/orders/{$fo}/unpack";
        $movements = InventoryMovement::count();

        $this->expectRejected($this->postAs('sales', $unpack, ['reason' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $unpack), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/orders/FO-NOPE/unpack', ['reason' => 'x']), 'FO_NOT_FOUND', [404]);
        $this->assertSame($movements, InventoryMovement::count());

        $un = $this->expectOk($this->postAs('worker', $unpack, ['reason' => 'عدد الكراتين خاطئ']));
        $this->assertSame([true, 'picked'], [$un['ok'], $un['status']]);
        $this->assertSame([5, 0], [$this->onHand($p, 'PACK'), $this->onHand($p, 'STG-OUT')]);
        $this->assertSame(['picked', 'picked'], [FulfillmentOrder::find($foId)->status, SalesOrder::where('number', $so['number'])->value('status')]);
        $pkg = Package::where('fo_id', $foId)->firstOrFail();
        $this->assertSame(['void', 'worker', 'عدد الكراتين خاطئ'], [$pkg->status, $pkg->voided_by, $pkg->void_reason]);
        $this->assertSame([0, 2], [Carton::where('fo_id', $foId)->where('status', '!=', 'void')->count(), Carton::where('fo_id', $foId)->where('status', 'void')->count()]);
        $this->assertSame(0, StagingEntry::where('reference_id', $foId)->where('status', 'waiting')->count());
        $doc = $this->fo($fo);
        $this->assertSame([[], [0], ['void']], [$doc['cartonList'], array_column($doc['lines'], 'packedQty'), array_column($doc['packages'], 'status')]);
        $this->assertSame('move', collect($doc['movements'])->last()['type']);
        $this->assertSame(['STG-OUT', 'PACK'], [collect($doc['movements'])->last()['srcBin']['code'], collect($doc['movements'])->last()['dstBin']['code']]);
        $this->assertSame(['picked', 'packed', 'picked'], array_column($doc['history'], 'toStatus'));
        $this->assertTrue(AuditLog::where('action', 'PACK.UNPACK')->where('entity_number', $fo)->exists());
        $this->expectRejected($this->postAs('worker', $unpack, ['reason' => 'x']), 'UNPACK_STATE', [422]);
        $this->assertReconciles();

        // pack again: a new package, and the carton codes start at C01 again
        $again = $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$fo}/pack", ['cartons' => 1, 'weightKg' => 9]));
        $this->assertNotSame($pkg->number, $again['package']);
        $this->assertSame(["{$fo}-C01"], array_column($again['cartonList'], 'code'));
        $this->assertSame(['void', 'packed'], array_column($this->fo($fo)['packages'], 'status'));
        $this->assertSame(5, $this->onHand($p, 'STG-OUT'));

        // an order on the truck is unloaded first
        $trip = $this->makeTrip([$fo], $this->makeVehicle(), $this->makeDriver());
        $this->expectOk($this->loadScanned($trip->number, $fo));
        $this->expectRejected($this->postAs('worker', $unpack, ['reason' => 'x']), 'UNPACK_STATE', [422]);
        $this->assertReconciles();
    }

    public function test_put_back_returns_what_was_picked_and_the_order_waits_again(): void
    {
        $p = $this->makeProduct();
        $q = $this->makeProduct();
        $this->stock($p, 'A-07-2-B2', 20);
        $this->stock($q, 'A-07-2-B3', 20);
        $so = $this->order([['sku' => $p->sku, 'qty' => 6, 'price' => 10], ['sku' => $q->sku, 'qty' => 4, 'price' => 10]]);
        $fo = $this->fulfil($so['number']);
        $tasks = collect($fo['pickLists'][0]['tasks'])->keyBy(fn ($t) => $t['product']['sku']);
        $putback = "/api/fulfillment/orders/{$fo['number']}/putback";

        $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'x']), 'PUTBACK_STATE', [422]); // nothing started yet: cancel the order instead
        $this->expectOk($this->pickTask($tasks[$p->sku], 4));
        $this->assertSame([16, 4], [$this->onHand($p, 'A-07-2-B2'), $this->onHand($p, 'PACK')]);
        $this->expectRejected($this->postAs('worker', $putback, ['reason' => 'x']), 'FORBIDDEN', [403]); // a supervisor's decision
        $this->expectRejected($this->postAs('wm', $putback), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('wm', '/api/fulfillment/orders/FO-NOPE/putback', ['reason' => 'x']), 'FO_NOT_FOUND', [404]);
        $this->assertSame(4, $this->onHand($p, 'PACK'), 'rejections move nothing');

        $back = $this->expectOk($this->postAs('wm', $putback, ['reason' => 'العميل أجّل الطلب']));
        $this->assertSame([true, 'cancelled', 4, $so['number'], 'confirmed'], [$back['ok'], $back['status'], $back['returnedQty'], $back['order'], $back['orderStatus']]);
        $this->assertSame([20, 0], [$this->onHand($p, 'A-07-2-B2'), $this->onHand($p, 'PACK')]);
        $this->assertSame([0, 0], [$this->reserved($p), $this->reserved($q)]);
        $doc = $this->fo($fo['number']);
        $this->assertSame(['cancelled', ['cancelled'], ['cancelled', 'cancelled']], [$doc['status'], array_column($doc['pickLists'], 'status'), array_column($doc['pickLists'][0]['tasks'], 'status')]);
        $last = collect($doc['movements'])->last();
        $this->assertSame(['restock', 'PACK', 'A-07-2-B2', 4], [$last['type'], $last['srcBin']['code'], $last['dstBin']['code'], $last['qty']]);
        $order = SalesOrder::where('number', $so['number'])->first();
        $this->assertSame(['confirmed', 'backordered', false], [$order->status, $order->fulfil_status, $order->has_shortage]);
        $this->assertSame([[6, 0, 0, 0], [4, 0, 0, 0]], SalesOrderLine::where('so_id', $order->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->qty, $l->reserved_qty, $l->allocated_qty, $l->picked_qty])->all());
        $this->assertSame(0, InventoryReservation::where('so_id', $order->id)->where('status', 'active')->count());
        $this->assertTrue(AuditLog::where('action', 'PICK.PUTBACK')->where('entity_number', $fo['number'])->exists());
        $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'x']), 'PUTBACK_STATE', [422]);
        $this->assertReconciles();

        // the way out: the order is allocated and fulfilled again
        $this->assertSame('allocated', $this->expectOk($this->postAs('wm', "/api/sales/orders/{$so['number']}/allocate"))['status']);
        $this->assertNotSame($fo['number'], $this->fulfil($so['number'])['number']);
        $this->assertReconciles();
    }

    public function test_put_back_into_another_bin_with_the_order_cancelled(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-3-B2', 10);
        $customer = $this->makeCustomer();
        $so = $this->order([['sku' => $p->sku, 'qty' => 5, 'price' => 10]], $customer);
        $fo = $this->pickedOrder($so['number']);
        $task = $this->tasks($fo)[0];
        $putback = "/api/fulfillment/orders/{$fo}/putback";
        $bin = Bin::where('warehouse_id', $this->ryd()->id)->where('code', 'A-07-3-B2')->firstOrFail();

        // the bin it came from no longer takes stock: the rejection names the task, and another bin is given for it
        $bin->update(['status' => 'blocked']);
        try {
            $refused = $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'إلغاء من العميل', 'cancelOrder' => true]), 'BIN_UNUSABLE', [422]);
            $this->assertSame([$task['id'], 'A-07-3-B2', 5], [$refused['details']['taskId'], $refused['details']['bin'], $refused['details']['qty']]);
            $this->assertSame('picked', FulfillmentOrder::where('number', $fo)->value('status'));
            $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'x', 'bins' => [$task['id'] => 'NO-SUCH-BIN']]), 'BIN_NOT_FOUND', [404]);
            $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'x', 'bins' => [$task['id'] => 'FZ-01-1-B3']]), 'BIN_UNUSABLE', [422]); // frozen zone for an ambient product
            $back = $this->expectOk($this->postAs('wm', $putback, ['reason' => 'إلغاء من العميل', 'cancelOrder' => true, 'bins' => [$task['id'] => 'A-07-3-B3']]));
        } finally {
            $bin->update(['status' => 'active']);
        }
        $this->assertSame([5, 'cancelled'], [$back['returnedQty'], $back['orderStatus']]);
        $this->assertSame([5, 5, 0], [$this->onHand($p, 'A-07-3-B3'), $this->onHand($p, 'A-07-3-B2'), $this->onHand($p, 'PACK')]);
        $this->assertSame('cancelled', SalesOrder::where('number', $so['number'])->value('status'));
        $this->assertEqualsWithDelta(0, (float) Customer::find($customer->id)->balance, 0.001, 'a cancelled order is no exposure');
        $this->assertSame('cancelled', PickList::where('fo_id', FulfillmentOrder::where('number', $fo)->value('id'))->value('status'));
        $this->assertReconciles();

        // a packed order is unpacked first
        $so2 = $this->order([['sku' => $p->sku, 'qty' => 2, 'price' => 10]]);
        $packed = $this->packedOrder($so2['number']);
        $this->expectRejected($this->postAs('wm', "/api/fulfillment/orders/{$packed}/putback", ['reason' => 'x']), 'PUTBACK_STATE', [422]);
        $this->expectOk($this->postAs('worker', "/api/fulfillment/orders/{$packed}/unpack", ['reason' => 'إلغاء']));
        $this->expectOk($this->postAs('wm', "/api/fulfillment/orders/{$packed}/putback", ['reason' => 'إلغاء']));
        $this->assertSame([0, 0], [$this->onHand($p, 'PACK'), $this->onHand($p, 'STG-OUT')]);
        $this->assertReconciles();
    }

    public function test_put_back_takes_the_order_off_its_trip_and_never_cancels_another_systems_order(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-3-B4', 30);
        $so = $this->order([['sku' => $p->sku, 'qty' => 3, 'price' => 10]]);
        $ref = $this->fromAnotherSystem($so['number']);
        $fo = $this->pickedOrder($so['number']);
        $other = $this->packedOrder($this->order([['sku' => $p->sku, 'qty' => 2, 'price' => 10]])['number'], 12);
        $trip = $this->makeTrip([$fo, $other], $this->makeVehicle(), $this->makeDriver());
        $foRow = FulfillmentOrder::where('number', $fo)->first();
        $putback = "/api/fulfillment/orders/{$fo}/putback";

        $this->expectRejected($this->postAs('wm', $putback, ['reason' => 'x', 'cancelOrder' => true]), 'PUTBACK_EXTERNAL', [422]);
        $back = $this->expectOk($this->postAs('wm', $putback, ['reason' => 'إعادة تخطيط']));
        $this->assertSame([$trip->number, 'confirmed'], [$back['trip'], $back['orderStatus']]);
        $this->assertNull($foRow->refresh()->trip_id);
        $this->assertSame(0, TripOrder::where('trip_id', $trip->id)->where('fo_id', $foRow->id)->count());
        $this->assertSame('skipped', TripStop::where('trip_id', $trip->id)->where('fo_id', $foRow->id)->value('status'));
        $this->assertSame(12.0, Trip::find($trip->id)->kg, 'the trip carries what is left on it');
        $plan = $this->expectOk($this->getAs('disp', "/api/fulfillment/trips/{$trip->number}/loading"));
        $this->assertSame([$other], array_column($plan['rows'], 'fo'), 'the order is no longer in the loading plan');
        $this->assertSame(['confirmed', 'backordered'], array_values(SalesOrder::where('number', $so['number'])->first()->only(['status', 'fulfil_status'])));
        $event = IntegrationEvent::where('type', 'order.putback')->where('subject', $ref)->firstOrFail();
        $this->assertSame(['backordered', $fo], [$event->payload['status'], $event->payload['fulfilmentOrder']]);
        $this->assertSame(0, PickTask::whereIn('pick_list_id', PickList::where('fo_id', $foRow->id)->select('id'))->where('status', '!=', 'cancelled')->count());
        $this->assertReconciles();
    }
}
