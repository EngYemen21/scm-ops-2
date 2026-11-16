<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\InventoryBalance;
use App\Models\InventoryHold;
use App\Models\InventoryMovement;
use App\Models\TransferLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WarehouseTransfer;
use App\Services\Core\SettingsService;
use App\Services\Inventory\TransfersService;
use App\Support\AppError;
use App\Support\AuthUser;
use Tests\ApiTestCase;
use Tests\Feature\Transport\TransportFixtures;

/**
 * Operational hardening W6 — the source side of a transfer: the quantity is HELD when the transfer is approved
 * (refused when the bin cannot cover it), given back on cancel, and leaves through that hold when the transfer ships —
 * with the carrier that takes it (policy transfers.requireCarrier).
 */
class TransferShippingTest extends ApiTestCase
{
    use InventoryFixtures;
    use TransportFixtures;

    /** @var array<string,mixed>|null sequential story: 60 units in bin R1 (RYD), empty R2 (RYD) and J1 (JED) */
    private static ?array $fx = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$fx === null) {
            $tag = self::uid();
            $src = $this->makeZone('RYD', "S{$tag}", ["S{$tag}-R1", "S{$tag}-R2"]);
            $dst = $this->makeZone('JED', "S{$tag}", ["S{$tag}-J1"]);
            $p = $this->makeProduct($tag);
            $this->stock($p['product'], $p['batch'], $src['wh'], $src['bins']["S{$tag}-R1"], 60, "OPEN-{$tag}");
            self::$fx = ['tag' => $tag, 'sku' => $p['product']->sku, 'pid' => $p['product']->id, 'batchNo' => $p['batch']->batch_no, 'batchId' => $p['batch']->id,
                'r1' => $src['bins']["S{$tag}-R1"], 'r2' => $src['bins']["S{$tag}-R2"], 'j1' => $dst['bins']["S{$tag}-J1"], 'ids' => []];
        }
    }

    private function create(int $qty, array $over = []): array
    {
        $res = $this->postAs('wm', '/api/inventory/transfers', $over + [
            'fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(), 'reasonCode' => 'shortage', 'submit' => true,
            'lines' => [['sku' => self::$fx['sku'], 'qty' => $qty, 'batchNo' => self::$fx['batchNo'], 'fromBin' => self::$fx['r1']->code, 'toBin' => self::$fx['j1']->code]],
        ]);
        $res->assertStatus(201);

        return $res->json();
    }

    private function act(string $id, string $action, array $body = []): array
    {
        return $this->expectOk($this->postAs('wm', "/api/inventory/transfers/{$id}/{$action}", $body));
    }

    private function row(): InventoryBalance
    {
        return $this->balance(self::$fx['pid'], self::$fx['r1']->id, self::$fx['batchId']);
    }

    /** [on hand, reserved] of the source row. */
    private function r1(): array
    {
        return [$this->row()->on_hand, $this->row()->reserved];
    }

    private function statusOf(string $id): string
    {
        return WarehouseTransfer::findOrFail($id)->status;
    }

    public function test_approval_holds_the_quantity_and_refuses_what_the_bin_cannot_cover(): void
    {
        $a = $this->create(40);
        $b = $this->create(30); // both requests fit the 60 on hand when they are made: a request reserves nothing
        $this->assertSame([60, 0], $this->r1());

        $this->act($a['id'], 'approve');
        $this->assertSame([60, 40], $this->r1(), 'approved = held in the source bin');
        $this->assertSame(40, $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$a['id']}"))['heldQty']);

        // the held quantity is out of reach of a manual move, a new request and the approval of another transfer
        $move = $this->expectRejected($this->postAs('wm', '/api/inventory/move', ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'fromBin' => self::$fx['r1']->code, 'toBin' => self::$fx['r2']->code, 'batchNo' => self::$fx['batchNo'], 'qty' => 21, 'reason' => 'slot']), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['available' => 20, 'requested' => 21], $move['details']);
        $this->assertSame(20, $this->expectOk($this->getAs('wm', '/api/inventory/products/'.self::$fx['sku'].'/stock'))['totalAvailable']);
        $new = $this->expectRejected($this->postAs('wm', '/api/inventory/transfers', ['fromWarehouseCode' => 'RYD', 'toWarehouseCode' => 'JED', 'date' => now()->toDateString(),
            'lines' => [['sku' => self::$fx['sku'], 'qty' => 21, 'batchNo' => self::$fx['batchNo'], 'fromBin' => self::$fx['r1']->code]]]), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(20, $new['details']['available']);

        $refused = $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b['id']}/approve"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['line' => 1, 'sku' => self::$fx['sku'], 'bin' => self::$fx['r1']->code, 'available' => 20, 'requested' => 30, 'reason' => 'short'], $refused['details']);
        $this->assertStringContainsString('سطر 1', $refused['message']);
        $this->assertSame(['requested', 0], [$this->statusOf($b['id']), InventoryHold::where('owner_id', $b['id'])->count()], 'a refused approval holds nothing and leaves the request as it was');
        $this->assertSame([60, 40], $this->r1());

        // cancelling the approved transfer frees its quantity: now the second one can be approved
        $this->assertSame('cancelled', $this->act($a['id'], 'cancel')['status']);
        $this->assertSame([60, 0], $this->r1());
        $this->assertSame('approved', $this->act($b['id'], 'approve')['status']);
        $this->assertSame([60, 30], $this->r1());
        self::$fx['ids']['b'] = $b['id'];
        $this->assertReconciled();
    }

    public function test_approval_is_refused_while_the_source_row_is_quarantined_or_frozen_by_a_count(): void
    {
        $d = $this->create(10);
        $flag = ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['r1']->code, 'batchNo' => self::$fx['batchNo'], 'reason' => 'فحص جودة'];
        $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', $flag + ['quarantine' => true]));
        $q = $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/approve"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame(['quarantine', 0], [$q['details']['reason'], $q['details']['available']]);
        $this->expectOk($this->postAs('wm', '/api/inventory/quarantine', $flag + ['quarantine' => false]));

        $this->row()->update(['blocked' => true]); // what an open count does to the rows it freezes
        $f = $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d['id']}/approve"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame('blocked', $f['details']['reason']);
        $this->row()->update(['blocked' => false]);
        $this->assertSame(['requested', 30], [$this->statusOf($d['id']), $this->row()->reserved]);

        $this->assertSame('approved', $this->act($d['id'], 'approve')['status']);
        $this->assertSame([60, 40], $this->r1());
        self::$fx['ids']['d'] = $d['id'];
        $this->assertReconciled();
    }

    public function test_ship_moves_the_held_quantity_keeps_its_cost_and_records_the_carrier(): void
    {
        $b = self::$fx['ids']['b'];
        $this->act($b, 'start-picking');
        $this->act($b, 'picking-done');
        $this->assertSame([60, 40], $this->r1(), 'picking / ready are status steps: nothing moves');

        // a row frozen or quarantined AFTER the approval does not leave: the hold stays, the way out is named
        $movements = InventoryMovement::count();
        $this->row()->update(['blocked' => true]);
        $frozen = $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship"), 'ROW_BLOCKED', [422]);
        $this->assertSame(['line' => 1, 'bin' => self::$fx['r1']->code], $frozen['details']);
        $this->row()->update(['blocked' => false, 'quarantine' => true]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship"), 'SOURCE_QUARANTINED', [422]);
        $this->row()->update(['quarantine' => false]);
        $this->assertSame(['ready', $movements, 30], [$this->statusOf($b), InventoryMovement::count(), (int) InventoryHold::where('owner_id', $b)->where('status', 'active')->sum('qty')]);

        // unknown vehicle / driver
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship", ['vehicleCode' => 'V-NOPE']), 'VEHICLE_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship", ['driverCode' => 'DRV-NOPE']), 'DRIVER_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship", ['carrier' => str_repeat('x', 121)]), 'INVALID_INPUT', [400]);
        $this->assertSame('ready', $this->statusOf($b));

        // a vehicle is never named without its driver (nor the reverse), whatever the policy says
        $vehicle = Vehicle::where('code', $this->mkVehicle()['code'])->firstOrFail();
        $driver = Driver::where('code', $this->mkDriver()['code'])->firstOrFail();
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship", ['vehicleCode' => $vehicle->code]), 'CARRIER_REQUIRED', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship", ['driverCode' => $driver->code]), 'CARRIER_REQUIRED', [422]);
        $this->assertSame('ready', $this->statusOf($b));

        $shipped = $this->act($b, 'ship', ['vehicleCode' => $vehicle->code, 'driverCode' => $driver->code, 'carrier' => ' ناقل الاختبار ', 'waybill' => 'WB-7001', 'note' => 'تحميل مسائي']);
        $this->assertSame(['transit', 'ناقل الاختبار', 'WB-7001', $vehicle->code, $driver->code, 'onroute'], [$shipped['status'], $shipped['carrier'], $shipped['waybill'], $shipped['vehicle']['code'], $shipped['driver']['code'], $shipped['trip']['status']]);
        $this->assertSame(['id', 'code', 'plateAr', 'plateEn'], array_keys($shipped['vehicle']));
        $this->assertSame([30, 10], $this->r1(), 'the 30 left through their hold; the other transfer still holds its 10');
        $this->assertSame(['consumed'], InventoryHold::where('owner_id', $b)->pluck('status')->all());
        $mv = InventoryMovement::where('reference_id', $b)->where('type', 'transit')->firstOrFail();
        $this->assertSame([30, 10.0, 300.0], [$mv->qty, (float) $mv->unit_cost, (float) $mv->total_cost]);
        $this->assertSame(10.0, (float) TransferLine::where('transfer_id', $b)->firstOrFail()->unit_cost);
        $audit = AuditLog::where('action', 'TRANSFER.SHIP')->where('entity_id', $b)->firstOrFail();
        $this->assertStringContainsString('WB-7001', json_encode($audit->new_value));
        $detail = $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$b}"));
        $this->assertSame([$vehicle->code, $driver->code, 'WB-7001', 30, 0, false], [$detail['vehicle']['code'], $detail['driver']['code'], $detail['waybill'], $detail['inTransitQty'], $detail['heldQty'], $detail['policy']['requireCarrier']]);

        // after shipping nothing can be cancelled, and a second ship is refused
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/cancel"), 'TRANSFER_SHIPPED', [422]);
        $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$b}/ship"), 'TRANSFER_TRANSITION', [422]);
        $this->act($b, 'receive');
        $this->assertSame(0.0, $this->inTransitNet($b));
        $this->assertReconciled();
    }

    public function test_policy_require_carrier_refuses_a_shipment_nobody_carries(): void
    {
        $d = self::$fx['ids']['d'];
        $this->act($d, 'start-picking');
        $this->act($d, 'picking-done');
        $vehicle = Vehicle::where('code', $this->mkVehicle()['code'])->firstOrFail();
        $driver = Driver::where('code', $this->mkDriver()['code'])->firstOrFail();
        $settings = $this->app->make(SettingsService::class);
        $settings->set('transfers.requireCarrier', true);
        try {
            $this->assertTrue($this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$d}"))['policy']['requireCarrier']);
            $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d}/ship"), 'CARRIER_REQUIRED', [422]);
            $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d}/ship", ['vehicleCode' => $vehicle->code]), 'CARRIER_REQUIRED', [422]);
            $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d}/ship", ['driverCode' => $driver->code, 'waybill' => 'WB-1']), 'CARRIER_REQUIRED', [422]);
            $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$d}/ship", ['carrier' => '   ']), 'CARRIER_REQUIRED', [422]);
            $this->assertSame(['ready', [30, 10]], [$this->statusOf($d), $this->r1()], 'a refused shipment moves nothing');

            // a named carrier is enough …
            $byCarrier = $this->act($d, 'ship', ['carrier' => 'شركة النقل السريع']);
            $this->assertSame(['transit', 'شركة النقل السريع', null, null], [$byCarrier['status'], $byCarrier['carrier'], $byCarrier['vehicle'], $byCarrier['driver']]);

            // … and so is a vehicle with its driver
            $e = $this->create(5);
            foreach (['approve', 'start-picking', 'picking-done'] as $step) {
                $this->act($e['id'], $step);
            }
            $byTruck = $this->act($e['id'], 'ship', ['vehicleCode' => $vehicle->code, 'driverCode' => $driver->code]);
            $this->assertSame(['transit', null, $vehicle->code, $driver->code], [$byTruck['status'], $byTruck['carrier'], $byTruck['vehicle']['code'], $byTruck['driver']['code']]);
        } finally {
            $settings->set('transfers.requireCarrier', false);
        }
        $this->assertSame([15, 0], $this->r1());
        $this->act($d, 'receive');
        $this->act($e['id'], 'receive');
        $this->assertReconciled();
    }

    /** `tripId` joins a transfer trip that is still on the road and takes its vehicle and driver; a delivery trip is refused. */
    public function test_ship_accepts_a_transfer_trip_and_takes_its_vehicle_and_driver(): void
    {
        $first = $this->create(3);
        foreach (['approve', 'start-picking', 'picking-done'] as $step) {
            $this->act($first['id'], $step);
        }
        $run = $this->act($first['id'], 'ship', ['vehicleCode' => $this->mkVehicle()['code'], 'driverCode' => $this->mkDriver()['code']]);
        $trip = Trip::findOrFail($run['trip']['id']);
        $delivery = Trip::where('kind', 'delivery')->whereNotNull('vehicle_id')->whereNotNull('driver_id')->orderBy('number')->firstOrFail();
        $t = $this->create(2);
        foreach (['approve', 'start-picking', 'picking-done'] as $step) {
            $this->act($t['id'], $step);
        }
        $u = User::where('username', 'wm')->firstOrFail();
        $wm = new AuthUser($u->id, $u->username, $u->name_ar, $u->name_en, ['wm'], ['inventory.transfer'], [], null, 'test-request');
        $service = $this->app->make(TransfersService::class);

        try {
            $service->ship($wm, $t['id'], null, ['tripId' => 'TRP-NOPE']);
            $this->fail('an unknown trip must be refused');
        } catch (AppError $e) {
            $this->assertSame('TRIP_NOT_FOUND', $e->errorCode);
        }
        try {
            $service->ship($wm, $t['id'], null, ['tripId' => $delivery->number]);
            $this->fail('a delivery trip cannot carry a transfer');
        } catch (AppError $e) {
            $this->assertSame('TRIP_NOT_TRANSFER', $e->errorCode);
        }
        $this->assertSame('ready', $this->statusOf($t['id']), 'a refused trip ships nothing');
        $shipped = $service->ship($wm, $t['id'], null, ['tripId' => $trip->number]);
        $this->assertSame(['transit', $trip->id, $trip->vehicle_id, $trip->driver_id], [$shipped['status'], $shipped['tripId'], $shipped['vehicleId'], $shipped['driverId']]);
        $this->assertSame($trip->number, $this->expectOk($this->getAs('wm', "/api/inventory/transfers/{$t['id']}"))['trip']['number']);
        $this->assertSame([2, 3], [$trip->stops()->count(), (int) $trip->stops()->max('seq') + 1], 'the second transfer is the second stop of the same trip');
        $this->act($first['id'], 'receive');
        $this->act($t['id'], 'receive');
        $this->assertSame('completed', $trip->refresh()->status);
        $this->assertReconciled();
    }

    /** A transfer approved before holds existed has none: shipping holds it first — with the same refusal as an approval. */
    public function test_a_transfer_approved_before_holds_existed_is_held_when_it_ships(): void
    {
        $old = $this->create(4);
        WarehouseTransfer::whereKey($old['id'])->update(['status' => 'ready']);
        $short = $this->create(6); // 10 on hand: both requests fit, but only one of them can leave once the stock shrinks
        WarehouseTransfer::whereKey($short['id'])->update(['status' => 'ready']);
        $this->assertSame([10, 0], $this->r1());

        $this->assertSame('transit', $this->act($old['id'], 'ship')['status']);
        $this->assertSame([6, 0], $this->r1());
        $this->assertSame(['consumed'], InventoryHold::where('owner_id', $old['id'])->pluck('status')->all());
        $this->assertSame(10.0, (float) TransferLine::where('transfer_id', $old['id'])->firstOrFail()->unit_cost);

        $this->expectOk($this->postAs('wm', '/api/inventory/adjust', ['sku' => self::$fx['sku'], 'warehouseCode' => 'RYD', 'binCode' => self::$fx['r1']->code, 'batchNo' => self::$fx['batchNo'], 'qtyDelta' => -2, 'reason' => 'كسر']));
        $movements = InventoryMovement::count();
        $refused = $this->expectRejected($this->postAs('wm', "/api/inventory/transfers/{$short['id']}/ship"), 'INSUFFICIENT_AVAILABLE', [422]);
        $this->assertSame([1, 4, 6, 'short'], [$refused['details']['line'], $refused['details']['available'], $refused['details']['requested'], $refused['details']['reason']]);
        $this->assertSame(['ready', $movements, [4, 0]], [$this->statusOf($short['id']), InventoryMovement::count(), $this->r1()], 'nothing moved, nothing stays held');
        // not a dead end: the transfer that cannot leave is cancelled
        $this->assertSame('cancelled', $this->act($short['id'], 'cancel')['status']);
        $this->act($old['id'], 'receive');
        $this->assertReconciled();
    }
}
