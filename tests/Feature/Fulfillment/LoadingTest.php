<?php

namespace Tests\Feature\Fulfillment;

use App\Models\AuditLog;
use App\Models\Carton;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\InventoryMovement;
use App\Models\LoadingLine;
use App\Models\LoadingPlan;
use App\Models\Package;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\StagingEntry;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\Vehicle;
use Tests\ApiTestCase;

/** Loading sequence, the order scan and its override, unload, the loading door and the outbound staging board. */
class LoadingTest extends ApiTestCase
{
    use FulfillmentFixtures;

    /** @return array{0:string,1:string} a packed order: [FO number, SO number] */
    private function packed(Product $p, int $qty = 5, float $kg = 50): array
    {
        $so = $this->order([['sku' => $p->sku, 'qty' => $qty, 'price' => 10]]);

        return [$this->packedOrder($so['number'], $kg), $so['number']];
    }

    public function test_an_earlier_stop_waits_for_a_later_stop_that_is_still_being_prepared(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-2-B2', 60);
        [$foA] = $this->packed($p);
        [$foB] = $this->packed($p);
        $soC = $this->order([['sku' => $p->sku, 'qty' => 5, 'price' => 10]]);
        $foC = $this->fulfil($soC['number'])['number']; // the LAST stop, not picked yet
        $trip = $this->makeTrip([$foA, $foB, $foC], $this->makeVehicle(), $this->makeDriver());
        $plan = "/api/fulfillment/trips/{$trip->number}/loading";

        $view = $this->expectOk($this->getAs('disp', $plan));
        $this->assertSame([$foC, $foB, $foA], array_column($view['rows'], 'fo'));
        $this->assertNull($view['nextToLoad'], 'nothing may go in before the last stop');
        $this->assertSame([[], [$foC], [$foC]], array_column($view['rows'], 'waitFor'));
        $this->assertSame([[], [], [$foB]], array_column($view['rows'], 'loadFirst'));
        $this->assertSame(['enforceSequence' => true, 'requireOrderScan' => true], $view['policy']);

        $wait = $this->expectRejected($this->loadScanned($trip->number, $foB), 'LOAD_WAIT', [422]);
        $this->assertSame([$foC], $wait['details']['waitFor']);
        $this->assertStringContainsString($foC, $wait['message']);
        $both = $this->expectRejected($this->loadScanned($trip->number, $foA), 'LOAD_WAIT', [422]);
        $this->assertSame([[$foC], [$foB]], [$both['details']['waitFor'], $both['details']['loadFirst']]);

        // out of turn is a supervisor's decision, with a reason, on record
        $this->expectRejected($this->loadScanned($trip->number, $foB, 'worker', ['overrideSequence' => true, 'reason' => 'x']), 'OVERRIDE_FORBIDDEN', [403]);
        $this->expectRejected($this->loadScanned($trip->number, $foB, 'disp', ['overrideSequence' => true]), 'OVERRIDE_REASON', [400]);
        $this->assertSame('packed', FulfillmentOrder::where('number', $foB)->value('status'), 'rejections load nothing');
        $ok = $this->expectOk($this->loadScanned($trip->number, $foB, 'disp', ['overrideSequence' => true, 'reason' => 'العميل الأخير اعتذر — يُزال لاحقًا']));
        $this->assertSame([['sequence'], 1], [$ok['overrides'], $ok['seq']]);
        $audit = AuditLog::where('action', 'LOAD.OVERRIDE')->where('entity_number', $foB)->firstOrFail();
        $this->assertSame(['disp', 'sequence'], [$audit->username, $audit->field]);

        // with the policy off only the order among packed orders counts
        $this->withPolicy(['loading.enforceSequence' => false], function () use ($trip, $foA, $plan, $foC) {
            $view = $this->expectOk($this->getAs('disp', $plan));
            $this->assertSame([$foA, [[], [], []]], [$view['nextToLoad'], array_column($view['rows'], 'waitFor')]);
            $this->assertSame(2, $this->expectOk($this->loadScanned($trip->number, $foA))['seq']);
            $this->assertSame([$foC], $view['dispatch']['notPacked']);
        });
        $this->assertReconciles();
    }

    public function test_the_order_scan_any_code_of_the_order_and_the_policy(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-2-B2', 60);
        [$foA] = $this->packed($p);
        [$foB] = $this->packed($p);
        [$foC] = $this->packed($p);
        $trip = $this->makeTrip([$foA, $foB, $foC], $this->makeVehicle(), $this->makeDriver());
        $load = "/api/fulfillment/trips/{$trip->number}/load";
        $pkgC = Package::where('fo_id', FulfillmentOrder::where('number', $foC)->value('id'))->value('number');

        // what the dispatch page's scan field sends: one code, as the order AND as its proof — here a carton, then a package
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => "{$foC}-C09", 'scannedOrder' => "{$foC}-C09"]), 'FO_NOT_ON_TRIP', [422]); // no such carton
        $c = $this->expectOk($this->postAs('worker', $load, ['foNumber' => "{$foC}-C02", 'scannedOrder' => "{$foC}-C02"]));
        $this->assertSame([$foC, 1], [$c['fo'], $c['seq']]);
        $this->assertSame("{$foC}-C02", LoadingLine::where('fo_id', FulfillmentOrder::where('number', $foC)->value('id'))->value('scanned_order'));
        $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foB, 'scannedOrder' => $pkgC]), 'WRONG_ORDER', [422]); // the package of another order
        $this->expectOk($this->postAs('worker', $load, ['foNumber' => $foB, 'scannedOrder' => "LBL-{$foB}"])); // the shipping-label reference

        // policy off: the scan is welcome but not required (a wrong one is still refused)
        $this->withPolicy(['loading.requireOrderScan' => false], function () use ($load, $foA, $foB) {
            $this->expectRejected($this->postAs('worker', $load, ['foNumber' => $foA, 'scannedOrder' => $foB]), 'WRONG_ORDER', [422]);
            $free = $this->expectOk($this->postAs('worker', $load, ['foNumber' => $foA]));
            $this->assertSame([[], 3], [$free['overrides'], $free['seq']]);
        });
        $this->assertSame(0, AuditLog::where('action', 'LOAD.OVERRIDE')->whereIn('entity_number', [$foA, $foB, $foC])->count());
        $this->assertReconciles();
    }

    public function test_unload_is_the_exact_reverse_of_load(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-2-B2', 60);
        [$foA, $soA] = $this->packed($p, 5, 40);
        [$foB] = $this->packed($p, 5, 60);
        $ref = $this->fromAnotherSystem($soA);
        $vehicle = $this->makeVehicle();
        $trip = $this->makeTrip([$foA, $foB], $vehicle, $this->makeDriver());
        $unload = "/api/fulfillment/trips/{$trip->number}/unload";
        $idA = FulfillmentOrder::where('number', $foA)->value('id');

        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => $foA, 'reason' => 'x']), 'TRIP_STATE', [422]); // nothing is loaded yet
        $this->expectOk($this->loadScanned($trip->number, $foB));
        $this->expectOk($this->loadScanned($trip->number, $foA));
        $this->assertSame([0, 'loading', 'loading'], [$this->onHand($p, 'STG-OUT'), Trip::find($trip->id)->status, Vehicle::find($vehicle->id)->state]);

        $this->expectRejected($this->postAs('sales', $unload, ['foNumber' => $foA, 'reason' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => $foA]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => 'FO-NOPE', 'reason' => 'x']), 'FO_NOT_ON_TRIP', [422]);
        $this->assertSame(0, InventoryMovement::where('type', 'unload')->where('reference_id', $idA)->count());

        // a carton of the order names it
        $off = $this->expectOk($this->postAs('worker', $unload, ['foNumber' => "{$foA}-C01", 'reason' => 'العميل ألغى قبل الانطلاق']));
        $this->assertSame([$foA, 'packed', 'loading', 1], [$off['fo'], $off['status'], $off['tripStatus'], $off['remainingLoaded']]);
        $this->assertSame(5, $this->onHand($p, 'STG-OUT'));
        $this->assertSame(['packed', false, null], array_values(FulfillmentOrder::find($idA)->only(['status', 'loaded', 'loaded_at'])));
        $this->assertSame('packed', SalesOrder::where('number', $soA)->value('status'));
        $this->assertSame(['packed'], Carton::where('fo_id', $idA)->pluck('status')->unique()->values()->all());
        $this->assertSame('waiting', StagingEntry::where('reference_id', $idA)->where('direction', 'out')->value('status'));
        $this->assertSame([false, null], array_values(TripOrder::where('trip_id', $trip->id)->where('fo_id', $idA)->first()->only(['loaded', 'load_seq'])));
        $plan = LoadingPlan::where('trip_id', $trip->id)->firstOrFail();
        $this->assertSame(['unloaded', 60.0], [LoadingLine::where('loading_plan_id', $plan->id)->where('fo_id', $idA)->value('status'), $plan->total_kg]);
        // the goods come back at the cost they left at, so goods in transit closes to zero
        $loadMv = InventoryMovement::where('type', 'load')->where('reference_id', $idA)->firstOrFail();
        $unloadMv = InventoryMovement::where('type', 'unload')->where('reference_id', $idA)->firstOrFail();
        $this->assertSame([(float) $loadMv->unit_cost, (float) $loadMv->total_cost, 5], [(float) $unloadMv->unit_cost, (float) $unloadMv->total_cost, $unloadMv->qty]);
        $this->assertNotNull($unloadMv->dst_bin_id);
        $this->assertNull($unloadMv->src_bin_id);
        $this->assertTrue(AuditLog::where('action', 'LOAD.UNLOAD')->where('entity_number', $foA)->exists());
        $this->assertSame('packed', IntegrationEvent::where('type', 'order.unloaded')->where('subject', $ref)->firstOrFail()->payload['status']);
        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => $foA, 'reason' => 'x']), 'NOT_LOADED', [422]);
        $view = $this->expectOk($this->getAs('disp', "/api/fulfillment/trips/{$trip->number}/loading"));
        $this->assertSame([[$foB, 1, true], [$foA, null, false]], array_map(fn ($r) => [$r['fo'], $r['loadSeq'], $r['loaded']], $view['rows']));
        $this->assertReconciles();

        // the last order off: the truck is empty, the trip is a plan again and the vehicle is no longer loading
        $empty = $this->expectOk($this->postAs('worker', $unload, ['foNumber' => $foB, 'reason' => 'تغيير المركبة']));
        $this->assertSame(['dassigned', 0], [$empty['tripStatus'], $empty['remainingLoaded']]);
        $this->assertSame(['dassigned', 'assigned'], [Trip::find($trip->id)->status, Vehicle::find($vehicle->id)->state]);
        $this->assertSame(10, $this->onHand($p, 'STG-OUT'));
        $this->assertSame(0.0, LoadingPlan::find($plan->id)->total_kg);
        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => $foB, 'reason' => 'x']), 'TRIP_STATE', [422]);

        // and it loads again: the same loading lines, a fresh sequence
        $this->assertSame(1, $this->expectOk($this->loadScanned($trip->number, $foB))['seq']);
        $this->assertSame(2, $this->expectOk($this->loadScanned($trip->number, $foA))['seq']);
        $this->assertSame([2, 2], [LoadingLine::where('loading_plan_id', $plan->id)->count(), LoadingLine::where('loading_plan_id', $plan->id)->where('status', 'loaded')->count()]);
        $this->assertSame(['loaded'], Carton::where('fo_id', $idA)->pluck('status')->unique()->values()->all());
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"));
        $this->expectRejected($this->postAs('worker', $unload, ['foNumber' => $foA, 'reason' => 'x']), 'TRIP_STATE', [422]);
        $this->assertSame(0, $this->onHand($p, 'STG-OUT'));
        $this->assertReconciles();
    }

    public function test_loading_door_and_the_staging_board(): void
    {
        $p = $this->makeProduct();
        $this->stock($p, 'A-07-2-B2', 60);
        [$a1] = $this->packed($p);
        [$b1] = $this->packed($p);
        [$b2] = $this->packed($p);
        [$free] = $this->packed($p); // packed, on no trip yet
        $tripA = $this->makeTrip([$a1], $this->makeVehicle(), $this->makeDriver());
        $tripB = $this->makeTrip([$b1, $b2], $this->makeVehicle(), $this->makeDriver());
        $dock = fn (Trip $t) => "/api/fulfillment/trips/{$t->number}/dock";
        $door = self::freeDoor();

        $this->expectRejected($this->postAs('sales', $dock($tripA), ['dockCode' => $door]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('worker', $dock($tripA)), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('worker', $dock($tripA), ['dockCode' => 'D99']), 'DOCK_NOT_FOUND', [422]);
        $this->expectRejected($this->postAs('worker', '/api/fulfillment/trips/TRP-NOPE/dock', ['dockCode' => $door]), 'TRIP_NOT_FOUND', [404]);
        $set = $this->expectOk($this->postAs('worker', $dock($tripA), ['dockCode' => strtolower($door)]));
        $this->assertSame([$tripA->number, $door], [$set['trip'], $set['dockCode']]);
        $this->assertTrue(AuditLog::where('action', 'TRIP.DOCK')->where('entity_number', $tripA->number)->where('new_value', $door)->exists());
        $view = $this->expectOk($this->getAs('disp', "/api/fulfillment/trips/{$tripA->number}/loading"));
        $this->assertSame([$door, $door, 6], [$view['dock']['code'], $view['trip']['dockCode'], count($view['dock']['options'])]);
        $this->assertSame($door, $this->fo($a1)['trip']['dockCode']);

        // two planned trips may name the same door; the one that starts loading holds it
        $this->expectOk($this->postAs('disp', $dock($tripB), ['dockCode' => $door]));
        $this->expectOk($this->loadScanned($tripA->number, $a1));
        $busy = collect($this->expectOk($this->getAs('disp', "/api/fulfillment/trips/{$tripB->number}/loading"))['dock']['options'])->firstWhere('code', $door);
        $this->assertSame($tripA->number, $busy['busyBy']);
        $this->expectRejected($this->loadScanned($tripB->number, $b2), 'DOCK_BUSY', [422]);
        $this->assertSame('packed', FulfillmentOrder::where('number', $b2)->value('status'));
        $other = self::freeDoor($door);
        $this->expectOk($this->postAs('disp', $dock($tripB), ['dockCode' => $other]));
        $this->expectRejected($this->postAs('disp', $dock($tripB), ['dockCode' => $door]), 'DOCK_BUSY', [422]);
        $this->expectOk($this->loadScanned($tripB->number, $b2));

        // outbound staging read as lanes: trip → door → the orders still waiting, and the orders with no trip
        $board = $this->expectOk($this->getAs('worker', '/api/fulfillment/staging?warehouse=RYD'));
        $lane = collect($board['groups'])->first(fn ($g) => ($g['trip']['number'] ?? null) === $tripB->number);
        $this->assertSame([$other, 'loading', [$b1]], [$lane['trip']['dockCode'], $lane['trip']['status'], array_column($lane['orders'], 'fo')]);
        $this->assertSame([1, 5], [$lane['orders'][0]['stopSeq'], $lane['orders'][0]['qty']]);
        $noTrip = collect($board['groups'])->first(fn ($g) => $g['trip'] === null);
        $this->assertContains($free, array_column($noTrip['orders'], 'fo'));
        $this->assertNull(collect($board['groups'])->first(fn ($g) => ($g['trip']['number'] ?? null) === $tripA->number), 'a trip with nothing left in staging has no lane');
        $this->assertSame($board['total'], collect($board['groups'])->sum(fn ($g) => count($g['orders'])));

        // the door can be cleared while the trip is here, and is history once it left
        $this->assertNull($this->expectOk($this->postAs('worker', $dock($tripA), ['dockCode' => null]))['dockCode']);
        $this->expectOk($this->postAs('worker', $dock($tripA), ['dockCode' => $door]));
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$tripA->number}/dispatch"));
        $this->expectRejected($this->postAs('worker', $dock($tripA), ['dockCode' => $other]), 'TRIP_STATE', [422]);
        $this->assertSame($door, Trip::find($tripA->id)->dock_code);
        $this->assertReconciles();
    }

    /** A door of RYD no loading trip holds (other test classes of this run may have left one busy). */
    private static function freeDoor(?string $except = null): string
    {
        $busy = Trip::whereIn('status', ['loading', 'ready'])->whereNotNull('dock_code')->pluck('dock_code')->all();
        foreach (['D1', 'D2', 'D3', 'D4', 'D5', 'D6'] as $d) {
            if ($d !== $except && ! in_array($d, $busy, true)) {
                return $d;
            }
        }

        return 'D1';
    }
}
