<?php

namespace Tests\Feature\Transport;

use App\Finance\Models\Journal;
use App\Models\AuditLog;
use App\Models\Trip;
use App\Models\TripCost;
use App\Services\Core\SettingsService;
use App\Services\Transport\TripCostService;
use Tests\ApiTestCase;

/**
 * The trip cost sheet (TripCostService): every component, where its figure comes from (`basis`), the recompute
 * endpoint, and the facts that rebuild it by themselves (fuel records, expense vouchers).
 *
 * A "run" is a trip created through the API and then put on a vehicle / driver with its actual times straight in the
 * table: costing reads the trip, it does not care how the trip got there (assignment, loading and delivery have their
 * own tests).
 */
class TripCostTest extends ApiTestCase
{
    use TransportFixtures;

    /** @return string the trip number (km null = a trip with no distance: the column holds 0) */
    private function mkRun(?array $vehicle, ?array $driver, ?float $km, ?float $hours, bool $closed = true): string
    {
        $trip = $this->mkTrip([$this->mkFo(100)->number], $km !== null ? ['km' => $km] : []);
        $end = now();
        Trip::where('number', $trip['number'])->update([
            'vehicle_id' => $vehicle['id'] ?? null, 'driver_id' => $driver['id'] ?? null, 'km' => $km ?? 0,
            'actual_start' => $hours !== null ? $end->copy()->subMinutes((int) round($hours * 60))->format('Y-m-d H:i:s.v') : null,
            'actual_end' => $hours !== null ? $end->format('Y-m-d H:i:s.v') : null,
            'status' => $closed ? 'closed' : 'onroute', 'closed_at' => $closed ? $end->format('Y-m-d H:i:s.v') : null,
        ]);

        return $trip['number'];
    }

    private function recompute(string $trip): array
    {
        $res = $this->postAs('disp', "/api/transport/trips/{$trip}/cost/recompute"); // action button: no body
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());

        return $this->expectOk($res);
    }

    private function policy(array $values, callable $do): void
    {
        $settings = $this->app->make(SettingsService::class);
        foreach ($values as $key => $value) {
            $settings->set($key, $value);
        }
        try {
            $do();
        } finally {
            foreach (array_keys($values) as $key) {
                $settings->set($key, 0);
            }
        }
    }

    public function test_nothing_configured_means_zero_and_says_why(): void
    {
        $v = $this->mkVehicle('dry');
        $d = $this->mkDriver();
        $trip = $this->mkRun($v, $d, 120, 10);
        $sheet = $this->recompute($trip);

        $this->assertSame($trip, $sheet['number']);
        $this->assertEquals(0, $sheet['total']);
        foreach (TripCostService::FIELDS as $field) {
            $this->assertEquals(0, $sheet[$field], $field);
            $this->assertArrayHasKey($field, $sheet['basis'], "basis says where {$field} comes from");
        }
        $this->assertNotNull($sheet['computedAt']);
        $source = fn (string $field) => [$sheet['basis'][$field]['source'], $sheet['basis'][$field]['detail']['reason'] ?? null];
        $this->assertSame(['not_configured', 'no_history'], $source('fuel'), 'no fuel on the trip and the vehicle has no fuel history');
        $this->assertSame(['not_configured', 'no_rate'], $source('driver'), 'neither the driver nor fleet.driverHourlyCost has a rate');
        $this->assertSame(['not_configured', 'no_rate'], $source('ot'));
        $this->assertSame(['not_configured', 'no_rate'], $source('maint'), 'no history of 500 km and fleet.maintCostPerKm = 0');
        $this->assertSame(['not_configured', 'no_rate'], $source('dep'), 'no purchase cost / life and fleet.depreciationPerKm = 0');
        $this->assertSame(['actual', null], $source('tolls'), 'vouchers are facts: none linked = 0, actual');
        $this->assertSame(0, $sheet['basis']['tolls']['detail']['vouchers']);
        $this->assertSame(['not_configured', 'no_account_role'], $source('parking'));
        $this->assertSame(['not_configured', 'no_account_role'], $source('third'));
        $this->assertSame(['actual', null], $source('other'));

        // a trip with no vehicle, no driver and no distance is costed too — closing never depends on master data
        $bare = $this->recompute($this->mkRun(null, null, null, null, false));
        $this->assertEquals(0, $bare['total']);
        $this->assertSame(['no_vehicle', 'no_driver', 'no_vehicle', 'no_vehicle'], array_map(fn ($f) => $bare['basis'][$f]['detail']['reason'], ['fuel', 'driver', 'maint', 'dep']));
        $noKm = $this->recompute($this->mkRun($v, $d, null, null, false));
        $this->assertSame(['no_km', 'no_km', 'no_km'], array_map(fn ($f) => $noKm['basis'][$f]['detail']['reason'], ['fuel', 'maint', 'dep']));

        // no journal: trip costing is management accounting
        $this->assertSame(0, Journal::where('source_type', 'Trip')->count());
    }

    public function test_driver_time_maintenance_and_depreciation(): void
    {
        $v = $this->mkVehicle('dry', 3000, 16, 8, ['purchaseCost' => 120000, 'salvageValue' => 20000, 'lifeKm' => 400000]);
        $this->assertSame(['120000.00', '20000.00', 400000], [$v['purchaseCost'], $v['salvageValue'], $v['lifeKm']]);
        $d = $this->mkDriver(['hourlyCost' => 30]);
        $this->assertSame('30.00', $d['hourlyCost']);
        $trip = $this->mkRun($v, $d, 120, 10);

        // 10 hours at 30/h: 8 regular + 2 overtime × 1.5; depreciation (120000 − 20000) ÷ 400000 = 0.25 per km
        $sheet = $this->recompute($trip);
        $this->assertEquals([240, 90, 30], [$sheet['driver'], $sheet['ot'], $sheet['dep']]);
        $this->assertSame(['actual', 'driver'], [$sheet['basis']['driver']['source'], $sheet['basis']['driver']['detail']['rateSource']]);
        $this->assertEquals([8, 10, 30], [$sheet['basis']['driver']['detail']['hours'], $sheet['basis']['driver']['detail']['tripHours'], $sheet['basis']['driver']['detail']['rate']]);
        $this->assertEquals([2, 8, 1.5], [$sheet['basis']['ot']['detail']['hours'], $sheet['basis']['ot']['detail']['afterHours'], $sheet['basis']['ot']['detail']['multiplier']]);
        $this->assertSame(['allocated', 'vehicle'], [$sheet['basis']['dep']['source'], $sheet['basis']['dep']['detail']['rateSource']]);
        $this->assertEquals(0.25, $sheet['basis']['dep']['detail']['ratePerKm']);
        $this->assertSame('not_configured', $sheet['basis']['maint']['source']);
        $this->assertEquals(360, $sheet['total']);

        // the sheet is what the trip room shows; recomputing is idempotent and audited
        $room = $this->expectOk($this->getAs('sales', "/api/transport/trips/{$trip}"));
        $this->assertEquals(360, $room['costTotal']);
        $this->assertEquals([240, 90, 30], [$room['cost']['driver'], $room['cost']['ot'], $room['cost']['dep']]);
        $this->assertSame('actual', $room['cost']['basis']['driver']['source']);
        $again = $this->recompute($trip);
        $this->assertEquals($sheet['total'], $again['total']);
        $this->assertSame(1, TripCost::where('trip_id', $room['id'])->count());
        $this->assertSame(2, AuditLog::where('action', 'TRIP.COST_RECOMPUTE')->where('entity_number', $trip)->count());

        // policies: the driver without his own rate takes fleet.driverHourlyCost; a vehicle without a cost card takes the per-km rates
        $plainVehicle = $this->mkVehicle('dry');
        $plainDriver = $this->mkDriver();
        $other = $this->mkRun($plainVehicle, $plainDriver, 200, 6);
        $this->policy(['fleet.driverHourlyCost' => 20, 'fleet.maintCostPerKm' => 0.1, 'fleet.depreciationPerKm' => 0.3], function () use ($other) {
            $sheet = $this->recompute($other);
            $this->assertEquals([120, 0, 20, 60], [$sheet['driver'], $sheet['ot'], $sheet['maint'], $sheet['dep']]);
            $this->assertSame(['policy', 'policy', 'policy'], [$sheet['basis']['driver']['detail']['rateSource'], $sheet['basis']['maint']['detail']['rateSource'], $sheet['basis']['dep']['detail']['rateSource']]);
            $this->assertSame(['actual', 'allocated', 'allocated'], [$sheet['basis']['ot']['source'], $sheet['basis']['maint']['source'], $sheet['basis']['dep']['source']]);
            $this->assertEquals(0, $sheet['basis']['ot']['detail']['hours'], 'six hours: no overtime');
        });
        $this->assertEquals(0, $this->recompute($other)['total'], 'policies back to 0: not configured again');

        // a driver whose trip has no actual times yet costs nothing, and the sheet says the times are missing
        $running = $this->recompute($this->mkRun($v, $d, 120, null, false));
        $this->assertSame(['not_configured', 'no_times'], [$running['basis']['driver']['source'], $running['basis']['driver']['detail']['reason']]);
        $this->assertEquals([0, 0, 30], [$running['driver'], $running['ot'], $running['dep']]);
    }

    public function test_maintenance_rate_comes_from_the_vehicle_history_once_it_covers_500_km(): void
    {
        $v = $this->mkVehicle('dry');
        $trip = $this->mkRun($v, null, 120, 4);

        // 120 km of closed trips only: under 500 km the policy rate applies (0 → not configured)
        $sheet = $this->recompute($trip);
        $this->assertSame(['not_configured', 'policy'], [$sheet['basis']['maint']['source'], $sheet['basis']['maint']['detail']['rateSource']]);
        $this->assertEquals(120, $sheet['basis']['maint']['detail']['historyKm']);

        // + 1,000 km closed five days ago and a maintenance order closed at 500 SAR → 500 ÷ 1,120 km
        $old = $this->mkRun($v, null, 1000, 8);
        Trip::where('number', $old)->update(['closed_at' => now()->subDays(5)->format('Y-m-d H:i:s.v'), 'actual_end' => now()->subDays(5)->format('Y-m-d H:i:s.v')]);
        $mo = $this->expectOk($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'kind' => 'oil', 'desc' => 'زيت', 'shop' => 'ورشة', 'startDate' => now()->subDays(3)->toDateString()]));
        $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$mo['number']}/close", ['endDate' => now()->subDays(2)->toDateString(), 'cost' => 500]));
        $sheet = $this->recompute($trip);
        $this->assertSame(['allocated', 'history'], [$sheet['basis']['maint']['source'], $sheet['basis']['maint']['detail']['rateSource']]);
        $this->assertEquals([1120, 500], [$sheet['basis']['maint']['detail']['historyKm'], $sheet['basis']['maint']['detail']['historyCost']]);
        $this->assertEquals(round(120 * 500 / 1120, 2), $sheet['maint']);

        // an order that is still open, cancelled, or older than a year is not history
        $open = $this->expectOk($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'desc' => 'مفتوح', 'shop' => 'ورشة', 'startDate' => now()->toDateString(), 'cost' => 9000]));
        $this->assertEquals(round(120 * 500 / 1120, 2), $this->recompute($trip)['maint']);
        $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$open['number']}/cancel", ['reason' => 'فُتح بالخطأ']));
        $this->assertEquals(round(120 * 500 / 1120, 2), $this->recompute($trip)['maint']);

        // the older trip is costed from the history as it stood when IT ended: the order closed after it does not count
        $earlier = $this->recompute($old);
        $this->assertEquals(0, $earlier['maint']);
        $this->assertSame(['allocated', 'history'], [$earlier['basis']['maint']['source'], $earlier['basis']['maint']['detail']['rateSource']], '1,000 km of history with no maintenance cost: a real 0, from history');
    }

    public function test_fuel_is_actual_when_linked_and_estimated_from_the_vehicle_history_otherwise(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000
        $linked = $this->mkRun($v, null, 100, 3);
        $unlinked = $this->mkRun($v, null, 120, 3);
        $base = ['vehicleCode' => $v['code'], 'date' => '2026-09-15'];

        // a fuel record on the trip rebuilds its sheet by itself (FleetPosting::rebuildTripCosts → compute)
        $a = $this->expectOk($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1100, 'liters' => 20, 'cost' => 46, 'tripNumber' => $linked]));
        $this->assertSame($linked, $a['tripNumber']);
        $cost = TripCost::findOrFail(Trip::where('number', $linked)->value('id'));
        $this->assertEquals(46, $cost->fuel);
        $this->assertSame(['actual', 1, [$a['number']]], [$cost->basis['fuel']['source'], $cost->basis['fuel']['detail']['records'], $cost->basis['fuel']['detail']['numbers']]);
        $this->assertNotNull($cost->computed_at);

        // one fuel record is not a history: no estimate for the trip that has no fuel of its own
        $sheet = $this->recompute($unlinked);
        $this->assertEquals(0, $sheet['fuel']);
        $this->assertSame(['not_configured', 'no_history', 1], [$sheet['basis']['fuel']['source'], $sheet['basis']['fuel']['detail']['reason'], $sheet['basis']['fuel']['detail']['records']]);

        // second fill: 200 km on 40 L = 5 km/L (as the first), last price 2.5 SAR/L → 120 km ÷ 5 × 2.5 = 60
        $this->expectOk($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1300, 'liters' => 40, 'cost' => 100]));
        $sheet = $this->recompute($unlinked);
        $this->assertEquals(60, $sheet['fuel']);
        $this->assertSame(['estimated', 'records'], [$sheet['basis']['fuel']['source'], $sheet['basis']['fuel']['detail']['kmPerLSource']]);
        $this->assertEquals([5, 2.5, 120], [$sheet['basis']['fuel']['detail']['kmPerL'], $sheet['basis']['fuel']['detail']['pricePerL'], $sheet['basis']['fuel']['detail']['km']]);
        $this->assertEquals(46, $this->recompute($linked)['fuel'], 'the trip with its own fuel keeps the actual figure');

        // the trip must exist and run on the fuelled vehicle
        $stranger = $this->mkRun($this->mkVehicle('dry'), null, 50, 1);
        $this->expectRejected($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1400, 'liters' => 10, 'cost' => 25, 'tripNumber' => 'TRP-NOPE']), 'TRIP_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1400, 'liters' => 10, 'cost' => 25, 'tripNumber' => $stranger]), 'FUEL_TRIP_VEHICLE', [422]);
    }

    public function test_vouchers_on_the_trip_feed_tolls_maintenance_and_other(): void
    {
        $v = $this->mkVehicle('dry');
        $trip = $this->mkRun($v, null, 100, 2);
        $voucher = fn (string $account, float $amount) => $this->expectOk($this->postAs('disp', '/api/finance/expenses', ['account' => $account, 'amount' => $amount, 'settlement' => 'accrued', 'vehicle' => $v['code'], 'trip' => $trip, 'description' => "سند {$account}"]));
        $toll = $voucher('6130', 50);  // tolls, parking and other fleet costs
        $voucher('6120', 80);          // vehicle maintenance
        $voucher('6210', 20);          // anything else

        // ExpensesService → FleetPosting::rebuildTripCosts → TripCostService::compute: the sheet is already rebuilt
        $cost = TripCost::findOrFail(Trip::where('number', $trip)->value('id'));
        $this->assertEquals([50, 80, 20, 0, 0], [$cost->tolls, $cost->maint, $cost->other, $cost->parking, $cost->third]);
        $this->assertSame(['actual', 1], [$cost->basis['tolls']['source'], $cost->basis['tolls']['detail']['vouchers']]);
        $this->assertSame('actual', $cost->basis['maint']['source'], 'a maintenance voucher on the trip is a fact, even with no rate per km');
        $this->assertEquals([80, 0], [$cost->basis['maint']['detail']['direct'], $cost->basis['maint']['detail']['allocated']]);
        $this->assertSame('tolls', $cost->basis['parking']['detail']['includedIn']);

        // with a rate per km the direct voucher and the share by distance add up
        $this->policy(['fleet.maintCostPerKm' => 0.1], function () use ($trip) {
            $sheet = $this->recompute($trip);
            $this->assertEquals(90, $sheet['maint']);
            $this->assertSame('allocated', $sheet['basis']['maint']['source']);
            $this->assertEquals([80, 10], [$sheet['basis']['maint']['detail']['direct'], $sheet['basis']['maint']['detail']['allocated']]);
            $this->assertEquals(160, $sheet['total']);
        });

        // a voided voucher leaves the sheet
        $this->expectOk($this->postAs('gm', "/api/finance/expenses/{$toll['number']}/void", ['reason' => 'سند مكرر']));
        $this->assertEquals(0, $cost->refresh()->tolls);
        $this->assertEquals(100, $this->recompute($trip)['total']);
    }

    public function test_recompute_needs_the_permission_and_a_real_trip(): void
    {
        $trip = $this->mkRun($this->mkVehicle('dry'), null, 80, 2);
        $this->recompute($trip);
        $stamp = TripCost::findOrFail(Trip::where('number', $trip)->value('id'))->computed_at;
        $audits = AuditLog::where('action', 'TRIP.COST_RECOMPUTE')->count();

        $this->expectRejected($this->postAs('sales', "/api/transport/trips/{$trip}/cost/recompute"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('driver', "/api/transport/trips/{$trip}/cost/recompute"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips/TRP-NOPE/cost/recompute'), 'TRIP_NOT_FOUND', [404]);
        $this->assertEquals($stamp, TripCost::findOrFail(Trip::where('number', $trip)->value('id'))->computed_at, 'a refused call computes nothing');
        $this->assertSame($audits, AuditLog::where('action', 'TRIP.COST_RECOMPUTE')->count());
    }
}
