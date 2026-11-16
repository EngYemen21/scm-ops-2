<?php

namespace Tests\Feature\Inventory;

use App\Models\Driver;
use App\Models\InventoryHold;
use App\Models\Product;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Tests\ApiTestCase;
use Tests\Feature\Transport\TransportFixtures;

/**
 * Operational hardening — the transfer ↔ transport link: a transfer that leaves on the company's own vehicle and driver
 * is a trip (vehicle and driver busy, visible to dispatch, costed when closed); the destination's first receipt is the
 * proof of delivery of its stop; an outside carrier makes no trip.
 */
class TransferTripTest extends ApiTestCase
{
    use InventoryFixtures;
    use TransportFixtures;

    /** @var array<string,mixed>|null 100 units in bin R1 (RYD), empty J1 (JED) */
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
            self::$fx = ['sku' => $p['product']->sku, 'pid' => $p['product']->id, 'batchNo' => $p['batch']->batch_no, 'batchId' => $p['batch']->id,
                'r1' => $src['bins']["T{$tag}-R1"], 'j1' => $dst['bins']["T{$tag}-J1"]];
        }
    }

    /** A transfer approved, picked and ready to leave. */
    private function ready(int $qty): string
    {
        $res = $this->postAs('wm', '/api/inventory/transfers', [
            'fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(), 'reasonCode' => 'rebalance', 'submit' => true,
            'lines' => [['sku' => self::$fx['sku'], 'qty' => $qty, 'batchNo' => self::$fx['batchNo'], 'fromBin' => self::$fx['r1']->code, 'toBin' => self::$fx['j1']->code]],
        ]);
        $res->assertStatus(201);
        $id = $res->json('id');
        foreach (['approve', 'start-picking', 'picking-done'] as $step) {
            $this->act($id, $step);
        }

        return $id;
    }

    private function act(string $id, string $action, array $body = []): array
    {
        return $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$id}/{$action}", $body));
    }

    private function onHand(): int
    {
        return (int) $this->balance(self::$fx['pid'], self::$fx['r1']->id, self::$fx['batchId'])->on_hand;
    }

    public function test_own_vehicle_and_driver_make_the_transfer_a_trip_closed_by_the_destination_receipt(): void
    {
        $vehicle = $this->mkVehicle();
        $driver = $this->mkDriver();
        $crew = ['vehicleCode' => $vehicle['code'], 'driverCode' => $driver['code']];

        // ── ship: the trip exists and is on the road from this moment
        $a = $this->ready(10);
        $shipped = $this->act($a, 'ship', $crew + ['waybill' => 'WB-T1']);
        $this->assertSame(['transit', 'onroute'], [$shipped['status'], $shipped['trip']['status']]);
        $trip = Trip::with('stops')->findOrFail($shipped['trip']['id']);
        $ryd = Warehouse::where('code', 'RYD')->firstOrFail();
        $jed = Warehouse::where('code', 'JED')->firstOrFail();
        $this->assertSame(['transfer', 'onroute', $ryd->id, $vehicle['id'], $driver['id']], [$trip->kind, $trip->status, $trip->warehouse_id, $trip->vehicle_id, $trip->driver_id]);
        $this->assertNotNull($trip->actual_start);
        $this->assertStringContainsString($jed->name_ar, $trip->route_ar);
        $this->assertTrue(TripCost::where('trip_id', $trip->id)->exists(), 'the trip has a cost sheet like any trip');
        $this->assertSame(['onroute', 'onroute'], [Vehicle::find($vehicle['id'])->state, Driver::find($driver['id'])->state]);
        $stop = $trip->stops->first();
        $this->assertSame([1, $a, 'pending', 10, "مستودع {$jed->name_ar}", null, null], [$stop->seq, $stop->transfer_id, $stop->status, $stop->items, $stop->customer_ar, $stop->fo_id, $stop->customer_id]);

        // dispatch sees it as a trip of its own kind
        $seen = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}"));
        $this->assertSame(['transfer', 'onroute', 1], [$seen['kind'], $seen['status'], count($seen['stops'])]);

        // ── a second transfer on the same vehicle and driver rides the same trip
        $b = $this->ready(5);
        $second = $this->act($b, 'ship', $crew);
        $this->assertSame($trip->id, $second['trip']['id']);
        $this->assertSame([[1, $a], [2, $b]], TripStop::where('trip_id', $trip->id)->orderBy('seq')->get()->map(fn ($s) => [$s->seq, $s->transfer_id])->all());

        // ── the vehicle is on the road: it cannot leave again with another driver, and a refused shipment moves nothing
        $c = $this->ready(4);
        $before = $this->onHand();
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$c}/ship", ['vehicleCode' => $vehicle['code'], 'driverCode' => $this->mkDriver()['code']]), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$c}/ship", ['vehicleCode' => $this->mkVehicle()['code'], 'driverCode' => $driver['code']]), 'DRIVER_BUSY', [422]);
        $this->assertSame(['ready', $before, 4], [WarehouseTransfer::find($c)->status, $this->onHand(), (int) InventoryHold::where('owner_id', $c)->where('status', 'active')->sum('qty')]);

        // ── an outside carrier is not a trip
        $outside = $this->act($c, 'ship', ['carrier' => 'ناقل خارجي', 'waybill' => 'WB-OUT']);
        $this->assertSame(['transit', null, null, 'ناقل خارجي'], [$outside['status'], $outside['trip'], $outside['vehicle'], $outside['carrier']]);

        // ── the destination's first receipt closes the stop: short → partial, and the truck still has a stop to unload
        $this->act($a, 'receive', ['lines' => [['lineNo' => 1, 'receivedQty' => 6]]]);
        $this->assertSame(['partial', 'onroute'], [TripStop::where('transfer_id', $a)->value('status'), $trip->refresh()->status]);
        $this->assertNotNull(TripStop::where('transfer_id', $a)->value('completed_at'));

        // a transfer cannot join a truck that is already being unloaded
        $d = $this->ready(3);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d}/ship", $crew), 'TRIP_UNLOADING', [422]);
        $this->assertSame('ready', WarehouseTransfer::find($d)->status);

        // the last stop received in full → the trip is done (partial: one stop was short) and the vehicle is on its way back
        $this->act($b, 'receive');
        $this->assertSame(['delivered', 'partial', 'returning'], [TripStop::where('transfer_id', $b)->value('status'), $trip->refresh()->status, Vehicle::find($vehicle['id'])->state]);

        // the rest of the short transfer arriving later belongs to the transfer, not to the stop
        $this->act($a, 'receive');
        $this->assertSame(['received', 'partial'], [WarehouseTransfer::find($a)->status, TripStop::where('transfer_id', $a)->value('status')]);

        // ── dispatch closes the trip like any other: vehicle and driver are free again
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$trip->number}/close"));
        $this->assertSame('closed', $closed['status']);
        $this->assertSame(['available', 'available'], [Vehicle::find($vehicle['id'])->state, Driver::find($driver['id'])->state]);

        // the same pair can now take the transfer that was refused
        $again = $this->act($d, 'ship', $crew);
        $this->assertNotSame($trip->id, $again['trip']['id']);
        $this->act($d, 'receive');
        $this->act($c, 'receive');
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$again['trip']['number']}/close"));
        $this->assertReconciled();
    }

    public function test_a_vehicle_or_driver_that_may_not_drive_ships_nothing(): void
    {
        $t = $this->ready(6);
        $before = $this->onHand();
        $good = $this->mkVehicle();
        $driver = $this->mkDriver();

        // expired insurance
        $expired = $this->mkVehicle();
        Vehicle::whereKey($expired['id'])->update(['insurance_expiry' => now()->subDays(3)]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t}/ship", ['vehicleCode' => $expired['code'], 'driverCode' => $driver['code']]), 'VEHICLE_CANNOT_ASSIGN', [422]);

        // expired licence
        $unlicensed = $this->mkDriver();
        Driver::whereKey($unlicensed['id'])->update(['license_expiry' => now()->subDays(2)]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t}/ship", ['vehicleCode' => $good['code'], 'driverCode' => $unlicensed['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);

        // a truck too small for the load
        $small = $this->mkVehicle('dry', 1);
        Product::whereKey(self::$fx['pid'])->update(['weight_kg' => 12]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$t}/ship", ['vehicleCode' => $small['code'], 'driverCode' => $driver['code']]), 'VEHICLE_UNFIT', [422]);

        $this->assertSame(['ready', $before, 0], [WarehouseTransfer::find($t)->status, $this->onHand(), Trip::where('kind', 'transfer')->whereIn('vehicle_id', [$expired['id'], $good['id'], $small['id']])->count()]);
        $this->assertSame(['available', 'available'], [Vehicle::find($good['id'])->state, Driver::find($driver['id'])->state]);

        // nothing arrived at all → the stop failed, the trip is over, and the variance is the transfer's business
        $shipped = $this->act($t, 'ship', ['vehicleCode' => $good['code'], 'driverCode' => $driver['code']]);
        $this->assertSame(72.0, (float) Trip::find($shipped['trip']['id'])->kg, '6 units × 12 kg');
        $this->act($t, 'receive', ['lines' => [['lineNo' => 1, 'receivedQty' => 0]]]);
        $this->assertSame(['failed', 'partial', 'partial'], [TripStop::where('transfer_id', $t)->value('status'), Trip::find($shipped['trip']['id'])->status, WarehouseTransfer::find($t)->status]);
        $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$t}/resolve-variance", ['resolution' => 'returned', 'note' => 'عادت الشاحنة بالحمولة كاملة']));
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$shipped['trip']['number']}/close"));
        $this->assertSame($before, $this->onHand(), 'everything came back to the source bin');
        $this->assertReconciled();
    }
}
