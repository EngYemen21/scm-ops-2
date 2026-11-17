<?php

namespace Tests\Feature\Transport;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\FulfillmentOrder;
use App\Models\OpsException;
use App\Models\SalesOrder;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\Core\SettingsService;
use App\Services\Transport\TripCostService;
use App\Services\Transport\TripsService;
use App\Support\AppError;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Tests\ApiTestCase;

/**
 * Trips (TMS): planning from packed fulfillment orders, vehicle recommendation, assignment rules (also while loading),
 * stop order and planned arrivals, orders joining / leaving a trip, the dispatch gate, cancel / close and the control
 * tower. Seeded fleet used for the rejections: V-15 maintenance · V-09 out of service · DRV-11 blocked ·
 * DRV-16 off shift · DRV-13 on route (TRP-2026-0029).
 */
class TripsTest extends ApiTestCase
{
    use TransportFixtures;

    public function test_create_trip_from_packed_orders_derives_load_stops_and_temperature(): void
    {
        $fo1 = $this->mkFo(300, 8);
        $fo2 = $this->mkFo(200, 4, 'frozen');
        $res = $this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'شمال الرياض', 'foNumbers' => [$fo1->number, $fo2->number], 'km' => 35, 'notes' => 'اختبار']);
        $this->assertSame(201, $res->getStatusCode());
        $t = $this->expectOk($res);

        $this->assertStringStartsWith('TRP-', $t['number']);
        $this->assertSame('planned', $t['status']);
        $this->assertSame('08:00', $t['plannedStart'], 'schema default');
        $this->assertCount(2, $t['stops']);
        $this->assertCount(2, $t['orders']);
        $this->assertEquals(500, $t['kg']);
        $this->assertSame(2, $t['pallets']);
        $this->assertSame('reefer', $t['tempNeed'], 'frozen goods raise the need even when the planner asked for dry');
        $this->assertSame($fo1->number, $t['stops'][0]['fo']['number']);
        $this->assertSame(2, $t['stops'][1]['seq']);
        $this->assertSame(5, $t['stops'][1]['items']);
        $this->assertSame(['id', 'code', 'nameAr', 'nameEn', 'address', 'contact'], array_keys($t['stops'][0]['customer']));
        $this->assertSame(['code', 'nameAr'], array_keys($t['orders'][0]['fo']['customer']));
        $this->assertSame(['id', 'code', 'nameAr', 'nameEn'], array_keys($t['warehouse']));
        $this->assertNull($t['vehicle']);
        $this->assertNull($t['utilisation']);
        $this->assertTrue($t['canEdit']);
        $this->assertSame(0, $t['loadedCount']);
        $this->assertEquals(0, $t['costTotal']);
        $this->assertNotNull($t['cost'], 'a cost sheet is opened with the trip');
        $this->assertSame(['pods' => 0, 'deliveries' => 0, 'loadingPlans' => 0], $t['_count']);
        $this->assertSame(['pending' => 2], $t['stopSummary']);
        $this->assertStringContainsString('أُنشئت الرحلة', $t['events'][0]['textAr']);
        $this->assertStringContainsString('اختبار', $t['events'][0]['textAr']);

        $this->assertSame($t['id'], FulfillmentOrder::find($fo1->id)->trip_id);
        $this->assertSame(2, FulfillmentOrder::find($fo2->id)->seq);
        $this->assertSame(1, AuditLog::where('action', 'TRIP.CREATE')->where('entity_id', $t['id'])->count());
        $this->assertSame('planned', StatusHistory::where('entity_type', 'Trip')->where('entity_id', $t['id'])->value('to_status'));

        // honest integration status: no LIVE eta / delay is invented for a trip nobody tracks yet …
        $this->assertNull($t['eta']);
        $this->assertSame(0, $t['delayMin']);
        // … but every stop has a PLANNED arrival (an estimate: no maps provider here), and the order has no window to keep
        $this->assertSame(['estimate', 'estimate'], array_column($t['stops'], 'etaSource'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $t['stops'][0]['eta']);
        $this->assertGreaterThan($t['stops'][0]['eta'], $t['stops'][1]['eta'], 'the second stop is reached after the first one and its service time');
        $this->assertSame([null, null], array_column($t['stops'], 'windowOk'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $t['plannedEnd']);
        $this->assertTrue($t['canAssignDriver']);
        $this->assertTrue($t['canRemoveOrders']);
        $this->assertSame(['TRIP_NOT_READY', 'TRIP_NO_VEHICLE', 'TRIP_NO_DRIVER', 'TRIP_NOT_LOADED', 'TRIP_NOTHING_LOADED'], array_column($t['dispatchGate']['blockers'], 'code'));

        // the same order cannot be put on a second active trip
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x', 'foNumbers' => [$fo1->number]]), 'FO_ON_TRIP', [422]);
    }

    public function test_create_trip_rejections(): void
    {
        $body = ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x'];
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', $body + ['foNumbers' => []]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['date' => '20-09-2026', 'foNumbers' => ['FO-1']] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', $body + ['foNumbers' => ['FO-NOPE-1']]), 'FO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'ZZZ', 'foNumbers' => ['FO-NOPE-1']] + $body), 'WH_NOT_FOUND', [404]);

        $notReady = $this->mkFo(50, 1, null, 'alloc');
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', $body + ['foNumbers' => [$notReady->number]]), 'FO_NOT_READY', [422]);
        $jeddah = $this->mkFo(50, 1, null, 'packed', 'JED');
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', $body + ['foNumbers' => [$jeddah->number]]), 'FO_OTHER_WH', [422]);
        $this->assertNull(FulfillmentOrder::find($jeddah->id)->trip_id);

        // picked orders are plannable too; a chilled product raises the need to chill
        $picked = $this->mkFo(40, 2, 'chilled', 'picked');
        $this->assertSame('chill', $this->mkTrip([$picked->number])['tempNeed']);
        // the planner may ask for more than the goods need
        $this->assertSame('reefer', $this->mkTrip([$this->mkFo(40, 2)->number], ['tempNeed' => 'reefer'])['tempNeed']);
    }

    public function test_assignment_rules_for_vehicles(): void
    {
        $t = $this->mkTrip([$this->mkFo(100)->number]);
        $url = "/api/transport/trips/{$t['number']}/assign";
        $this->expectRejected($this->postAs('disp', $url), 'ASSIGN_EMPTY', [400]);

        $body = $this->expectRejected($this->postAs('disp', $url, ['vehicleCode' => 'V-15']), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('حالة المركبة «صيانة» تمنع الإسناد', $body['message']);
        $body = $this->expectRejected($this->postAs('disp', $url, ['vehicleCode' => 'V-09']), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('خارج الخدمة', $body['message']);
        $this->expectRejected($this->postAs('disp', $url, ['vehicleCode' => 'V-NOPE']), 'VEHICLE_NOT_FOUND', [404]);

        // expired documents block the vehicle even when it is "available"
        $expired = $this->mkVehicle('dry');
        Vehicle::where('code', $expired['code'])->update(['insurance_expiry' => now()->subDays(3)]);
        $body = $this->expectRejected($this->postAs('disp', $url, ['vehicleCode' => $expired['code']]), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('وثائق المركبة منتهية', $body['message']);

        // an open emergency maintenance order blocks it too
        $emergency = $this->mkVehicle('dry');
        $this->expectOk($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $emergency['code'], 'kind' => 'emergency', 'desc' => 'تسريب', 'shop' => 'ورشة', 'startDate' => '2026-09-17']));
        $body = $this->expectRejected($this->postAs('disp', $url, ['vehicleCode' => $emergency['code']]), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('تنبيه صيانة حرج مفتوح', $body['message']);
        $this->assertSame('planned', Trip::find($t['id'])->status);
    }

    public function test_capacity_rules(): void
    {
        $small = $this->mkVehicle('dry', 1000, 8, 4);

        $heavy = $this->mkTrip([$this->mkFo(1500)->number]);
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$heavy['number']}/assign", ['vehicleCode' => $small['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('تجاوز الوزن الأقصى (1500 > 1000 كجم)', $body['message']);

        $bulky = $this->mkTrip([$this->mkFo(100, 150)->number]); // 150 cartons × 0.06 = 9 m³ > 8 m³
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$bulky['number']}/assign", ['vehicleCode' => $small['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('تجاوز الحجم الأقصى (9 > 8 م³)', $body['message']);

        $many = $this->mkTrip(array_map(fn () => $this->mkFo(10, 1)->number, range(1, 5))); // 5 orders = 5 pallets > 4
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$many['number']}/assign", ['vehicleCode' => $small['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('تجاوز عدد المنصات (5 > 4)', $body['message']);

        // the same checks guard a vehicle given at creation time
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x', 'foNumbers' => [$this->mkFo(1200)->number], 'vehicleCode' => $small['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertSame('available', Vehicle::where('code', $small['code'])->value('state'));

        $rec = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$heavy['number']}/recommend"));
        $cand = collect($rec)->firstWhere('vehicle.code', $small['code']);
        $this->assertFalse($cand['ok']);
        $this->assertSame('الوزن 1,500 يتجاوز 1,000', $cand['why']);
    }

    public function test_temperature_need_recommendation_assign_and_unassign(): void
    {
        $dry = $this->mkVehicle('dry');
        $reefer = $this->mkVehicle('reefer');
        $frozen = $this->mkFo(200, 8, 'frozen');
        // asked for dry, but the products are frozen → the need is derived from the goods
        $t = $this->mkTrip([$frozen->number], ['tempNeed' => 'dry']);
        $this->assertSame('reefer', $t['tempNeed']);

        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $dry['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('متطلب التبريد/التجميد غير متوافق مع مركبة جافة', $body['message']);
        $chill = $this->mkVehicle('chill');
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $chill['code']]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('الرحلة تحتاج مركبة مجمدة', $body['message']);

        $rec = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/recommend"));
        $this->assertTrue(array_is_list($rec), 'recommend returns a plain array of candidates');
        $this->assertSame(['vehicle', 'ok', 'why', 'score', 'reasons'], array_keys(collect($rec)->firstWhere('vehicle.code', $dry['code'])));
        $this->assertSame('يحتاج مجمد −18°', collect($rec)->firstWhere('vehicle.code', $dry['code'])['why']);
        $this->assertSame(-1, collect($rec)->firstWhere('vehicle.code', $dry['code'])['score']);
        $this->assertFalse(collect($rec)->firstWhere('vehicle.code', 'V-15')['ok']);
        $best = collect($rec)->firstWhere('vehicle.code', $reefer['code']);
        $this->assertTrue($best['ok']);
        $this->assertContains('في نفس المستودع', $best['reasons']);
        $this->assertContains('نوع مطابق تمامًا', $best['reasons']);
        $this->assertContains('6 كم/ل', $best['reasons']);
        $this->assertSame(['id', 'code', 'plateAr', 'kind', 'typeAr', 'typeEn', 'maxKg', 'maxCbm', 'pallets', 'warehouse', 'state', 'avgKmL', 'odometer', 'nextMaintKm'], array_keys($best['vehicle']));
        $this->assertSame('RYD', $best['vehicle']['warehouse']);
        // utilisation 200/3000 → round((1 − |0.0667 − 0.85|) × 40) = 9, +25 same warehouse, +18 (6 km/L × 3), +10 exact kind
        $this->assertSame(62, $best['score']);
        $okFlags = array_column($rec, 'ok');
        $this->assertSame($okFlags, array_merge(array_filter($okFlags), array_filter($okFlags, fn ($ok) => ! $ok)), 'assignable candidates come first');

        $a = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $reefer['code']]));
        $this->assertSame('vassigned', $a['status']);
        $this->assertSame($reefer['code'], $a['vehicle']['code']);
        $this->assertSame(7, $a['utilisation']['kg']);
        $this->assertFalse($a['utilisation']['overKg']);
        $this->assertSame('assigned', Vehicle::where('code', $reefer['code'])->value('state'));
        $this->assertSame(1, AuditLog::where('action', 'TRIP.ASSIGN_VEHICLE')->where('entity_id', $t['id'])->where('new_value', $reefer['code'])->count());

        // the vehicle is now taken for other trips
        $t2 = $this->mkTrip([$this->mkFo(100)->number]);
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t2['number']}/assign", ['vehicleCode' => $reefer['code']]), 'VEHICLE_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('المركبة مشغولة برحلة', $body['message']);

        // swapping the vehicle releases the previous one
        $reefer2 = $this->mkVehicle('reefer');
        $swapped = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $reefer2['code']]));
        $this->assertSame($reefer2['code'], $swapped['vehicle']['code']);
        $this->assertSame('available', Vehicle::where('code', $reefer['code'])->value('state'));
        $this->assertStringContainsString("بدلًا من {$reefer['code']}", collect($swapped['events'])->last()['textAr']);

        // body-less unassign drops both; here only the vehicle is set
        $u = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/unassign"));
        $this->assertSame('planned', $u['status']);
        $this->assertNull($u['vehicle']);
        $this->assertSame('available', Vehicle::where('code', $reefer2['code'])->value('state'));
    }

    public function test_assignment_rules_for_drivers(): void
    {
        $t = $this->mkTrip([$this->mkFo(100)->number]);
        $url = "/api/transport/trips/{$t['number']}/assign";

        $body = $this->expectRejected($this->postAs('disp', $url, ['driverCode' => 'DRV-11']), 'DRIVER_CANNOT_ASSIGN', [422]);
        $this->assertSame('مرفوض: السائق موقوف عن الإسناد — راجع وثائقه أو سبب الإيقاف في بطاقته', $body['message']);
        $body = $this->expectRejected($this->postAs('disp', $url, ['driverCode' => 'DRV-13']), 'DRIVER_BUSY', [422]);
        $this->assertSame('مرفوض: السائق برحلة متزامنة', $body['message']);
        $body = $this->expectRejected($this->postAs('disp', $url, ['driverCode' => 'DRV-16']), 'DRIVER_CANNOT_ASSIGN', [422]);
        $this->assertSame('مرفوض: السائق خارج الوردية', $body['message']);
        $this->expectRejected($this->postAs('disp', $url, ['driverCode' => 'DRV-NOPE']), 'DRIVER_NOT_FOUND', [404]);

        // expired license
        $expired = $this->mkDriver();
        Driver::where('code', $expired['code'])->update(['license_expiry' => now()->subDays(2)]);
        $body = $this->expectRejected($this->postAs('disp', $url, ['driverCode' => $expired['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);
        $this->assertStringContainsString('رخصة منتهية', $body['message']);

        // an expired iqama / ID blocks exactly like an expired licence
        $noIqama = $this->mkDriver();
        Driver::where('code', $noIqama['code'])->update(['iqama_expiry' => now()->subDays(2)]);
        $body = $this->expectRejected($this->postAs('disp', $url, ['driverCode' => $noIqama['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);
        $this->assertSame('مرفوض: الإقامة / الهوية منتهية', $body['message']);
        $this->assertFalse($this->app->make(TripsService::class)->driverCanAssign(Driver::where('code', $noIqama['code'])->first())['ok']);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x', 'foNumbers' => [$this->mkFo(100)->number], 'driverCode' => $noIqama['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);
        // the medical certificate only warns
        $noMedical = $this->mkDriver();
        Driver::where('code', $noMedical['code'])->update(['medical_expiry' => now()->subDays(2)]);
        $this->assertTrue($this->app->make(TripsService::class)->driverCanAssign(Driver::where('code', $noMedical['code'])->first())['ok']);

        $this->assertNull(Trip::find($t['id'])->driver_id);
        $this->assertSame('planned', Trip::find($t['id'])->status);
    }

    public function test_driver_with_license_expiring_inside_the_window_is_rejected(): void
    {
        $soon = now()->addDays(10)->format('Y-m-d');
        $payload = ['code' => self::code('TD-S'), 'nameAr' => 'رخصة قريبة', 'employeeNo' => 'E-S', 'mobile' => '05', 'licenseNo' => 'L-S', 'licenseExpiry' => $soon, 'iqamaExpiry' => self::FAR, 'shift' => 'am'];
        $body = $this->expectRejected($this->postAs('disp', '/api/transport/drivers', $payload), 'DRIVER_LICENSE_SOON', [422]);
        $this->assertSame('رخصة تنتهي خلال أقل من 30 يومًا — لا يمكن تفعيله', $body['message']);
        $this->assertFalse(Driver::where('code', $payload['code'])->exists());

        // the window comes from the fleet.driverLicenseMinDays policy
        $settings = $this->app->make(SettingsService::class);
        $settings->set('fleet.driverLicenseMinDays', 5);
        try {
            $created = $this->expectOk($this->postAs('disp', '/api/transport/drivers', $payload));
            $this->assertTrue($created['canAssign']['ok']);
        } finally {
            $settings->set('fleet.driverLicenseMinDays', 30);
        }

        // back on the 30-day policy the alert run blocks the driver, and a blocked driver cannot be assigned
        $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'));
        $this->assertTrue((bool) Driver::where('code', $payload['code'])->value('blocked'));
        $this->assertSame(1, AuditLog::where('action', 'DRIVER.BLOCK')->where('entity_number', $payload['code'])->count());
        $t = $this->mkTrip([$this->mkFo(100)->number]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['driverCode' => $payload['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);
        $this->expectRejected($this->postAs('disp', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x', 'foNumbers' => [$this->mkFo(100)->number], 'driverCode' => $payload['code']]), 'DRIVER_CANNOT_ASSIGN', [422]);

        // renewing the license lifts the block automatically
        $renewed = $this->expectOk($this->patchAs('disp', "/api/transport/drivers/{$payload['code']}", ['licenseExpiry' => self::FAR]));
        $this->assertFalse($renewed['blocked']);
        $this->assertTrue($renewed['canAssign']['ok']);
    }

    public function test_busy_driver_cannot_take_a_second_concurrent_trip(): void
    {
        // port of the reference negative: any driver of an on-route trip is refused on an editable trip
        $busy = Trip::with('driver')->where('status', 'onroute')->whereNotNull('driver_id')->firstOrFail();
        $t = $this->mkTrip([$this->mkFo(100)->number]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['driverCode' => $busy->driver->code]), 'DRIVER_BUSY', [422]);

        // and with our own data: a driver whose trip is loading is busy for the next one
        $vehicle = $this->mkVehicle('dry');
        $driver = $this->mkDriver();
        $first = $this->mkTrip([$this->mkFo(100)->number], ['vehicleCode' => $vehicle['code'], 'driverCode' => $driver['code']]);
        $this->assertSame('dassigned', $first['status']);
        Trip::where('id', $first['id'])->update(['status' => 'loading']); // loading is done by the fulfillment domain
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['driverCode' => $driver['code']]), 'DRIVER_BUSY', [422]);
        $this->expectRejected($this->postAs('disp', "/api/transport/drivers/{$driver['code']}/state", ['to' => 'off']), 'DRIVER_ON_TRIP', [422]);
        // once loading started the VEHICLE is fixed (the goods are on it); the driver may still change — see the next test
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$first['number']}/unassign"), 'TRIP_LOADED_VEHICLE', [422]);
        $this->assertSame([$first['vehicle']['id'], $first['driver']['id']], [Trip::find($first['id'])->vehicle_id, Trip::find($first['id'])->driver_id]);
    }

    public function test_a_trip_that_started_loading_can_still_get_or_change_its_driver_but_not_its_vehicle(): void
    {
        // the dead end found by the audit: loading needs only a vehicle, so this trip had no way to ever get a driver,
        // be dispatched (no driver) or be cancelled (loaded)
        $vehicle = $this->mkVehicle('dry');
        $other = $this->mkVehicle('dry');
        $driver = $this->mkDriver();
        $second = $this->mkDriver();
        $t = $this->mkTrip([$this->mkFo(100)->number], ['vehicleCode' => $vehicle['code']]);
        $this->assertSame('vassigned', $t['status']);
        Trip::where('id', $t['id'])->update(['status' => 'loading']); // loading is done by the fulfillment domain
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => true]);
        $url = "/api/transport/trips/{$t['number']}";

        $room = $this->expectOk($this->getAs('disp', $url));
        $this->assertSame([false, true], [$room['canEdit'], $room['canAssignDriver']]);
        $this->assertContains('TRIP_NO_DRIVER', array_column($room['dispatchGate']['blockers'], 'code'));

        $a = $this->expectOk($this->postAs('disp', "{$url}/assign", ['driverCode' => $driver['code']]));
        $this->assertSame(['loading', $driver['code'], $vehicle['code']], [$a['status'], $a['driver']['code'], $a['vehicle']['code']], 'the driver is named; the trip stays in loading');
        $this->assertTrue($a['dispatchGate']['ok'], 'vehicle + driver + everything loaded → it can leave');
        $this->assertSame(1, AuditLog::where('action', 'TRIP.ASSIGN_DRIVER')->where('entity_id', $t['id'])->where('new_value', $driver['code'])->count());
        // replacing him is possible too; the same rules as ever apply to the new one
        $this->expectRejected($this->postAs('disp', "{$url}/assign", ['driverCode' => 'DRV-11']), 'DRIVER_CANNOT_ASSIGN', [422]);
        $swapped = $this->expectOk($this->postAs('disp', "{$url}/assign", ['driverCode' => $second['code'], 'vehicleCode' => $vehicle['code']]));
        $this->assertSame($second['code'], $swapped['driver']['code']);
        $this->assertStringContainsString('بدلًا من', collect($swapped['events'])->last()['textAr']);

        // the vehicle: not while it is loaded
        $body = $this->expectRejected($this->postAs('disp', "{$url}/assign", ['vehicleCode' => $other['code']]), 'TRIP_LOADED_VEHICLE', [422]);
        $this->assertStringContainsString('أنزل الحمولة أولًا', $body['message']);
        $this->expectRejected($this->postAs('disp', "{$url}/unassign", ['vehicle' => true]), 'TRIP_LOADED_VEHICLE', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/unassign"), 'TRIP_LOADED_VEHICLE', [422]);
        $this->assertSame(['available', 'assigned'], [Vehicle::where('code', $other['code'])->value('state'), Vehicle::where('code', $vehicle['code'])->value('state')]);
        // dropping only the driver is allowed (one flag leaves the other side alone)
        $dropped = $this->expectOk($this->postAs('disp', "{$url}/unassign", ['driver' => true]));
        $this->assertSame(['loading', null, $vehicle['code']], [$dropped['status'], $dropped['driver'], $dropped['vehicle']['code']]);

        // same in `ready`; once it left, nothing changes any more
        Trip::where('id', $t['id'])->update(['status' => 'ready']);
        $this->assertSame('ready', $this->expectOk($this->postAs('disp', "{$url}/assign", ['driverCode' => $driver['code']]))['status']);
        Trip::where('id', $t['id'])->update(['status' => 'onroute']);
        $this->expectRejected($this->postAs('disp', "{$url}/assign", ['driverCode' => $second['code']]), 'TRIP_LOCKED', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/unassign", ['driver' => true]), 'TRIP_LOCKED', [422]);
        $this->assertSame($driver['id'], Trip::find($t['id'])->driver_id);
        Trip::where('id', $t['id'])->update(['status' => 'closed']);
        Vehicle::where('code', $vehicle['code'])->update(['state' => 'available']);

        // planning stage: "unassign the vehicle" releases the vehicle only
        $p = $this->mkTrip([$this->mkFo(100)->number], ['vehicleCode' => $other['code'], 'driverCode' => $second['code']]);
        $this->assertSame('dassigned', $p['status']);
        $u = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$p['number']}/unassign", ['vehicle' => true]));
        $this->assertSame([null, $second['code'], 'dassigned'], [$u['vehicle'], $u['driver']['code'], $u['status']]);
        $this->assertSame('available', Vehicle::where('code', $other['code'])->value('state'));
    }

    public function test_full_lifecycle_assign_reorder_close(): void
    {
        $vehicle = $this->mkVehicle('dry');
        $driver = $this->mkDriver();
        $fo1 = $this->mkFo(200);
        $fo2 = $this->mkFo(100);
        $t = $this->mkTrip([$fo1->number, $fo2->number], ['km' => 42]);

        $rec = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/recommend"));
        $this->assertTrue(collect($rec)->firstWhere('vehicle.code', $vehicle['code'])['ok']);

        $a = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $vehicle['code']]));
        $this->assertSame('vassigned', $a['status']);
        $a = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['driverCode' => $driver['code']]));
        $this->assertSame('dassigned', $a['status']);
        $this->assertSame($driver['code'], $a['driver']['code']);
        $this->assertSame(['planned', 'vassigned', 'dassigned'], StatusHistory::where('entity_type', 'Trip')->where('entity_id', $t['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());

        // stop order
        $ids = array_column($t['stops'], 'id');
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => [$ids[0]]]), 'STOPS_MISMATCH', [400]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => [$ids[0], $ids[0]]]), 'STOPS_MISMATCH', [400]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder"), 'INVALID_INPUT', [400]);
        $re = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => [$ids[1], $ids[0]]]));
        $this->assertSame($fo2->number, $re['stops'][0]['fo']['number']);
        $this->assertSame(1, FulfillmentOrder::find($fo2->id)->seq);

        // manual event on the timeline
        $ev = $this->postAs('disp', "/api/transport/trips/{$t['number']}/events", ['textAr' => 'اتصال بالعميل']);
        $this->assertSame(201, $ev->getStatusCode());
        $this->assertStringStartsWith('اتصال بالعميل · ', $ev->json('textAr'));
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $ev->json('label'));
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/events"), 'INVALID_INPUT', [400]);
        $events = $this->expectOk($this->getAs('sales', "/api/transport/trips/{$t['number']}/events"));
        $this->assertGreaterThanOrEqual(5, count($events));
        $this->assertStringContainsString('أُنشئت الرحلة', $events[0]['textAr']);

        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"), 'TRIP_NOT_CLOSABLE', [422]);

        // loading → dispatch belong to the fulfillment domain: simulate their outcome
        Trip::where('id', $t['id'])->update(['status' => 'onroute']);
        Vehicle::where('code', $vehicle['code'])->update(['state' => 'onroute']);
        Driver::where('code', $driver['code'])->update(['state' => 'onroute']);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => $ids]), 'TRIP_LOCKED', [422]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'x']), 'TRIP_TRANSITION', [422]);
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"), 'TRIP_OPEN_STOPS', [422]);
        $this->assertSame('لا يمكن الإقفال — 2 محطة غير معالجة', $body['message']);

        TripStop::where('id', $ids[0])->update(['status' => 'delivered']);
        TripStop::where('id', $ids[1])->update(['status' => 'failed', 'fail_reason' => 'closed']);
        $res = $this->postAs('disp', "/api/transport/trips/{$t['number']}/close"); // action button: no body
        $this->assertSame(201, $res->getStatusCode());
        $closed = $this->expectOk($res);
        $this->assertSame('closed', $closed['status']);
        $this->assertNotNull($closed['actualEnd']);
        $this->assertNotNull($closed['closedAt']);
        $this->assertFalse($closed['canEdit']);
        $this->assertEquals(['delivered' => 1, 'failed' => 1], $closed['stopSummary']);

        $v = Vehicle::where('code', $vehicle['code'])->first();
        $this->assertSame('available', $v->state, 'onroute → returning → atwh → available through the state machine');
        $this->assertSame(1042, $v->odometer);
        $this->assertSame(['returning', 'atwh', 'available'], StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v->id)->orderByDesc('at')->orderByDesc('id')->limit(3)->pluck('to_status')->reverse()->values()->all());
        $d = Driver::where('code', $driver['code'])->first();
        $this->assertSame([1, 1, 1, 42, 'available'], [$d->trips, $d->deliveries, $d->fails, $d->km, $d->state]);
        $this->assertEquals(50.0, $d->ok_pct);
        // onroute → partial (one stop failed) → closed
        $this->assertSame(['partial', 'closed'], StatusHistory::where('entity_type', 'Trip')->where('entity_id', $t['id'])->orderByDesc('at')->orderByDesc('id')->limit(2)->pluck('to_status')->reverse()->values()->all());
        $this->assertSame(1, AuditLog::where('action', 'TRIP.CLOSE')->where('entity_id', $t['id'])->count());

        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"), 'TRIP_CLOSED', [422]);
        $this->assertSame('الرحلة مقفلة مسبقًا', $body['message']);

        // a closed trip frees its orders for re-planning rules (ended trips do not hold an order)
        FulfillmentOrder::where('id', $fo2->id)->update(['status' => 'packed']);
        $again = $this->mkTrip([$fo2->number]);
        $this->assertSame('planned', $again['status']);
    }

    public function test_cancel_before_loading_releases_orders_and_vehicle(): void
    {
        $vehicle = $this->mkVehicle('dry');
        $fo = $this->mkFo(100);
        $t = $this->mkTrip([$fo->number], ['vehicleCode' => $vehicle['code']]);
        $this->assertSame('vassigned', $t['status']);
        $this->assertSame('assigned', Vehicle::where('code', $vehicle['code'])->value('state'));

        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel"), 'INVALID_INPUT', [400]); // a reason is mandatory

        // loaded orders must be unloaded first
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => true]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'اختبار']), 'TRIP_LOADED', [422]);
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => false]);

        $c = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'اختبار']));
        $this->assertSame('cancelled', $c['status']);
        $this->assertNull(FulfillmentOrder::find($fo->id)->trip_id);
        $this->assertSame('available', Vehicle::where('code', $vehicle['code'])->value('state'));
        $this->assertStringContainsString('أُلغيت الرحلة — اختبار', collect($c['events'])->last()['textAr']);

        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'مرة أخرى']), 'TRIP_TRANSITION', [422]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => $vehicle['code']]), 'TRIP_LOCKED', [422]);
    }

    public function test_dispatch_gate_for_the_fulfillment_domain(): void
    {
        $trips = $this->app->make(TripsService::class);
        $vehicle = $this->mkVehicle('dry');
        $driver = $this->mkDriver();
        $t = $this->mkTrip([$this->mkFo(100)->number], ['vehicleCode' => $vehicle['code'], 'driverCode' => $driver['code']]);
        $code = function (callable $fn): ?string {
            try {
                $fn();
            } catch (AppError $e) {
                return $e->errorCode;
            }

            return null;
        };
        // one rule, the one the dispatch endpoint enforces: the gate (data for the Trip Room) and assertCanDispatch (its first blocker)
        $gate = fn () => $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}"))['dispatchGate'];
        $this->assertSame('TRIP_NOT_READY', $code(fn () => $trips->assertCanDispatch($t['number'])));
        Trip::where('id', $t['id'])->update(['status' => 'loading']);
        $this->assertSame('TRIP_NOT_LOADED', $code(fn () => $trips->assertCanDispatch($t['number'])), 'a packed order that is not loaded blocks');
        $this->assertSame(['TRIP_NOT_LOADED', 'TRIP_NOTHING_LOADED'], array_column($gate()['blockers'], 'code'));
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => true]);
        $this->assertSame($t['number'], $trips->assertCanDispatch($t['number'])->number);

        // an order that is not packed yet does not block: it leaves the trip at dispatch
        $picked = $this->mkFo(50, 2, null, 'picked');
        $stop = TripStop::create(['trip_id' => $t['id'], 'seq' => 2, 'customer_ar' => 'عميل لاحق', 'fo_id' => $picked->id]);
        TripOrder::create(['trip_id' => $t['id'], 'fo_id' => $picked->id, 'stop_id' => $stop->id]);
        $g = $gate();
        $this->assertSame([true, [], [$picked->number], 1], [$g['ok'], $g['packedNotLoaded'], $g['leaving'], count($g['loaded'])]);
        FulfillmentOrder::whereKey($picked->id)->update(['status' => 'packed']);
        $this->assertSame([false, [$picked->number]], [$gate()['ok'], $gate()['packedNotLoaded']]);
        TripOrder::where('trip_id', $t['id'])->where('fo_id', $picked->id)->update(['loaded' => true]);

        // the driver must be able to drive: blocked / off shift / licence or iqama expired
        foreach ([['blocked' => true], ['state' => 'off'], ['license_expiry' => now()->subDays(2)], ['iqama_expiry' => now()->subDays(2)]] as $bad) {
            $before = Driver::where('code', $driver['code'])->first()->only(array_keys($bad));
            Driver::where('code', $driver['code'])->update($bad);
            $this->assertSame('DRIVER_BLOCKED', $code(fn () => $trips->assertCanDispatch($t['number'])), json_encode(array_keys($bad)));
            Driver::where('code', $driver['code'])->update($before);
        }
        // a vehicle that is out of service does not leave
        Vehicle::where('code', $vehicle['code'])->update(['state' => 'breakdown']);
        $this->assertSame('VEHICLE_STATE', $code(fn () => $trips->assertCanDispatch($t['number'])));
        Vehicle::where('code', $vehicle['code'])->update(['state' => 'assigned']);
        // an open critical loading exception on the trip blocks until it is resolved
        $exception = OpsException::create(['number' => self::code('EXC-T'), 'kind' => 'wrongveh', 'severity' => 'c', 'owner_role' => 'disp', 'entity_type' => 'Trip', 'entity_id' => $t['id'], 'entity_number' => $t['number'], 'text_ar' => 'مسح شاحنة خاطئة']);
        $this->assertSame('TRIP_EXCEPTIONS', $code(fn () => $trips->assertCanDispatch($t['number'])));
        $exception->update(['status' => 'resolved']);
        // without a driver, without a vehicle
        Trip::where('id', $t['id'])->update(['driver_id' => null]);
        $this->assertSame('TRIP_NO_DRIVER', $code(fn () => $trips->assertCanDispatch($t['number'])));
        Trip::where('id', $t['id'])->update(['driver_id' => $t['driver']['id'], 'vehicle_id' => null]);
        $this->assertSame('TRIP_NO_VEHICLE', $code(fn () => $trips->assertCanDispatch($t['number'])));
        Trip::where('id', $t['id'])->update(['vehicle_id' => $t['vehicle']['id']]);
        // nothing loaded and nothing packed either
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => false]);
        FulfillmentOrder::whereIn('id', TripOrder::where('trip_id', $t['id'])->pluck('fo_id'))->update(['status' => 'picked']);
        $this->assertSame('TRIP_NOTHING_LOADED', $code(fn () => $trips->assertCanDispatch($t['number'])));
        // a trip on the road has no gate any more
        Trip::where('id', $t['id'])->update(['status' => 'onroute']);
        $this->assertNull($gate());
        $this->assertSame('TRIP_NOT_READY', $code(fn () => $trips->assertCanDispatch($t['number'])));

        Trip::where('id', $t['id'])->update(['status' => 'dassigned']);
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/unassign", ['vehicle' => true, 'driver' => true]));
        $this->assertSame('available', Vehicle::where('code', $vehicle['code'])->value('state'));
    }

    public function test_orders_join_a_planned_trip_and_leave_it_while_they_are_not_loaded(): void
    {
        $small = $this->mkVehicle('dry', 1000, 16, 8);
        $fo1 = $this->mkFo(300, 8);
        $fo2 = $this->mkFo(200, 8);
        $t = $this->mkTrip([$fo1->number, $fo2->number], ['vehicleCode' => $small['code']]);
        $url = "/api/transport/trips/{$t['number']}/orders";

        // ── add: same checks as creation, and the assigned vehicle must still fit
        $this->expectRejected($this->postAs('disp', "{$url}/add"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => 'FO-NOPE-9']), 'FO_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $fo1->number]), 'FO_ON_TRIP', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $this->mkFo(50, 1, null, 'alloc')->number]), 'FO_NOT_READY', [422]);
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $this->mkFo(50, 1, null, 'packed', 'JED')->number]), 'FO_OTHER_WH', [422]);
        $heavy = $this->mkFo(600, 8);
        $body = $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $heavy->number]), 'VEHICLE_UNFIT', [422]);
        $this->assertStringContainsString('تجاوز الوزن الأقصى (1100 > 1000 كجم)', $body['message']);
        $frozen = $this->mkFo(50, 1, 'frozen');
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $frozen->number]), 'VEHICLE_UNFIT', [422]);
        $this->assertSame([null, null], [FulfillmentOrder::find($heavy->id)->trip_id, FulfillmentOrder::find($frozen->id)->trip_id]);
        $this->expectRejected($this->postAs('sales', "{$url}/add", ['foNumber' => $heavy->number]), 'FORBIDDEN', [403]);

        $fo3 = $this->mkFo(150, 4, null, 'picked');
        $res = $this->postAs('disp', "{$url}/add", ['foNumber' => $fo3->number]);
        $this->assertSame(201, $res->getStatusCode());
        $added = $this->expectOk($res);
        $this->assertSame([3, 650.0, 3, 'dry'], [count($added['stops']), (float) $added['kg'], $added['pallets'], $added['tempNeed']]);
        $this->assertSame([$fo3->number, 3], [$added['stops'][2]['fo']['number'], $added['stops'][2]['seq']]);
        $this->assertNotNull($added['stops'][2]['eta'], 'the new stop is planned like the others');
        $this->assertSame([$t['id'], 3], [FulfillmentOrder::find($fo3->id)->trip_id, FulfillmentOrder::find($fo3->id)->seq]);
        $this->assertSame(1, AuditLog::where('action', 'TRIP.ADD_ORDER')->where('entity_id', $t['id'])->count());
        $this->assertStringContainsString("أُضيف الطلب {$fo3->number}", collect($added['events'])->last()['textAr']);
        // without a vehicle, frozen goods raise the trip's temperature need
        $free = $this->mkTrip([$this->mkFo(100)->number]);
        $this->assertSame('reefer', $this->expectOk($this->postAs('disp', "/api/transport/trips/{$free['number']}/orders/add", ['foNumber' => $frozen->number]))['tempNeed']);

        // ── remove: the stop goes, the sequence closes up, the order stays packed and free for another trip
        $this->expectRejected($this->postAs('sales', "{$url}/{$fo1->number}/remove"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('disp', "{$url}/{$heavy->number}/remove"), 'FO_NOT_ON_TRIP', [422]);
        $res = $this->postAs('disp', "{$url}/{$fo1->number}/remove", ['reason' => 'العميل أجّل الاستلام']); // the body is optional
        $this->assertSame(201, $res->getStatusCode());
        $removed = $this->expectOk($res);
        $this->assertSame([[$fo2->number, 1], [$fo3->number, 2]], array_map(fn ($s) => [$s['fo']['number'], $s['seq']], $removed['stops']));
        $this->assertSame([350.0, 2, 2], [(float) $removed['kg'], $removed['pallets'], count($removed['orders'])]);
        $freed = FulfillmentOrder::find($fo1->id);
        $this->assertSame([null, 'packed'], [$freed->trip_id, $freed->status]);
        $this->assertSame([1, 2], [FulfillmentOrder::find($fo2->id)->seq, FulfillmentOrder::find($fo3->id)->seq]);
        $this->assertSame(0, TripStop::where('trip_id', $t['id'])->where('fo_id', $fo1->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'TRIP.REMOVE_ORDER')->where('entity_id', $t['id'])->count());
        $this->assertStringContainsString('العميل أجّل الاستلام', collect($removed['events'])->last()['textAr']);
        $this->assertSame('planned', $this->mkTrip([$fo1->number])['status'], 'the removed order is plannable again');

        // ── while loading: only what is NOT on the vehicle may leave; adding is over
        Trip::where('id', $t['id'])->update(['status' => 'loading']);
        TripOrder::where('trip_id', $t['id'])->where('fo_id', $fo2->id)->update(['loaded' => true]);
        FulfillmentOrder::whereKey($fo2->id)->update(['status' => 'loaded', 'loaded' => true]);
        $this->assertTrue($this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}"))['canRemoveOrders']);
        $body = $this->expectRejected($this->postAs('disp', "{$url}/{$fo2->number}/remove"), 'ORDER_LOADED', [422]);
        $this->assertStringContainsString('أنزله أولًا', $body['message']);
        $this->expectRejected($this->postAs('disp', "{$url}/add", ['foNumber' => $heavy->number]), 'TRIP_LOCKED', [422]);
        $left = $this->expectOk($this->postAs('disp', "{$url}/{$fo3->number}/remove"));
        $this->assertSame(['loading', [$fo2->number], 200.0], [$left['status'], array_map(fn ($s) => $s['fo']['number'], $left['stops']), (float) $left['kg']]);
        // the last order never leaves — the trip is cancelled (or unloaded) instead
        $this->assertFalse($left['canRemoveOrders']);
        $this->expectRejected($this->postAs('disp', "{$url}/{$fo2->number}/remove"), 'ORDER_LOADED', [422]);
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => false]);
        FulfillmentOrder::whereKey($fo2->id)->update(['status' => 'packed', 'loaded' => false]);
        $this->expectRejected($this->postAs('disp', "{$url}/{$fo2->number}/remove"), 'TRIP_LAST_ORDER', [422]);
        Trip::where('id', $t['id'])->update(['status' => 'onroute']);
        $this->expectRejected($this->postAs('disp', "{$url}/{$fo2->number}/remove"), 'TRIP_LOCKED', [422]);
        $this->assertSame($t['id'], FulfillmentOrder::find($fo2->id)->trip_id);
        Trip::where('id', $t['id'])->update(['status' => 'closed']);
        Vehicle::where('code', $small['code'])->update(['state' => 'available']);
    }

    public function test_planned_arrival_follows_the_stop_order_and_never_touches_the_customer_window(): void
    {
        $trips = $this->app->make(TripsService::class);
        $settings = $this->app->make(SettingsService::class);
        $ryd = Warehouse::where('code', 'RYD')->firstOrFail();
        [$lat, $lng] = [$ryd->lat, $ryd->lng];
        // the first order was promised between 08:00 and 08:30 — the window comes from the sales order
        $so = SalesOrder::create(['number' => self::code('SO-T'), 'customer_id' => Customer::orderBy('code')->firstOrFail()->id, 'warehouse_id' => $ryd->id, 'status' => 'packed', 'window' => '08:00–08:30']);
        $fo1 = $this->mkFo(100);
        $fo1->update(['so_id' => $so->id]);
        $t = $this->mkTrip([$fo1->number, $this->mkFo(100)->number, $this->mkFo(100)->number], ['plannedStart' => '07:30']);
        [$s1, $s2, $s3] = array_column($t['stops'], 'id');
        $this->assertSame(['08:00–08:30', null, null], array_column($t['stops'], 'window'));
        // three stops on a line north of the warehouse, 0.09° (≈ 10 km) apart: 10 km × 1.3 ÷ 40 km/h = 20 min per leg, 59 min back
        foreach ([$s1, $s2, $s3] as $i => $id) {
            TripStop::whereKey($id)->update(['lat' => $lat + 0.09 * ($i + 1), 'lng' => $lng]);
        }
        TripStop::whereKey($s2)->update(['window' => '06:00–23:00']);
        $plan = fn () => TripStop::where('trip_id', $t['id'])->orderBy('seq')->get()->map(fn ($s) => [$s->id, $s->eta, $s->eta_source, $s->window_ok])->all();

        try {
            // start 07:30 → 07:50 · +15 min service +20 → 08:25 · +15 +20 → 09:00 · +15 +59 → back at 10:14
            $this->assertSame(['source' => 'estimate', 'planned' => 3, 'unlocated' => 0, 'late' => 1, 'plannedEnd' => '10:14'], $trips->recomputeEtas($t['number']));
            $this->assertSame([[$s1, '07:50', 'estimate', false], [$s2, '08:25', 'estimate', true], [$s3, '09:00', 'estimate', null]], $plan(), 'before the window opens is outside the window too');

            // reorder (the endpoint that had no screen): arrivals move, the promise to the customer does not
            $re = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => [$s3, $s2, $s1]]));
            $this->assertSame([[$s3, '08:29', 'estimate', null], [$s2, '09:04', 'estimate', true], [$s1, '09:39', 'estimate', false]], $plan());
            $this->assertSame(['08:29', '09:04', '09:39'], array_column($re['stops'], 'eta'));
            $this->assertSame([[null, null], ['06:00–23:00', null], ['08:00–08:30', '08:00–08:30']], array_map(fn ($s) => [$s['window'], $s['plannedTime']], $re['stops']));
            $this->assertSame('10:14', $re['plannedEnd']);
            $row = $this->expectOk($this->getAs('disp', "/api/transport/trips?q={$t['number']}"))['items'][0];
            $this->assertSame([['next' => '08:29', 'source' => 'estimate', 'late' => 1], ['done' => 0, 'total' => 3]], [$row['plannedEta'], $row['progress']]);
            $this->assertArrayNotHasKey('stops', $row);

            // the two policies behind the estimate
            $settings->set('fleet.avgSpeedKph', 80);
            $settings->set('delivery.serviceMinutes', 5);
            $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/stops/reorder", ['stopIds' => [$s1, $s2, $s3]]));
            $this->assertSame(['07:40', '07:55', '08:10'], array_column($plan(), 1));
            $settings->set('fleet.avgSpeedKph', 40);
            $settings->set('delivery.serviceMinutes', 15);

            // a stop the map does not know: no arrival is invented for it, and the chain goes on from the last known point
            TripStop::whereKey($s2)->update(['lat' => null, 'lng' => null, 'customer_id' => null]);
            $this->assertSame(['source' => 'estimate', 'planned' => 2, 'unlocated' => 1, 'late' => 1, 'plannedEnd' => '10:13'], $trips->recomputeEtas($t['number']));
            $this->assertSame([[$s1, '07:50', 'estimate', false], [$s2, null, null, null], [$s3, '08:59', 'estimate', null]], $plan());

            // a warehouse without a pin: nothing can be planned, nothing is guessed
            $ryd->update(['lat' => null, 'lng' => null]);
            $this->assertSame(['source' => null, 'planned' => 0, 'unlocated' => 1, 'late' => 0, 'plannedEnd' => null], $trips->recomputeEtas($t['number']));
            $this->assertSame([null, null, null], array_column($plan(), 1));
        } finally {
            $ryd->update(['lat' => $lat, 'lng' => $lng]);
            $settings->set('fleet.avgSpeedKph', 40);
            $settings->set('delivery.serviceMinutes', 15);
        }
        // an arrival after midnight says so, and a window is then missed
        Trip::where('id', $t['id'])->update(['planned_start' => '23:50']);
        TripStop::whereKey($s1)->update(['window' => '23:00–23:59']);
        $trips->recomputeEtas($t['number']);
        $this->assertSame([$s1, '00:10 +1', 'estimate', false], $plan()[0]);
    }

    public function test_close_takes_an_odometer_reading_and_never_depends_on_costing(): void
    {
        $onRoute = function (int $km): array {
            $vehicle = $this->mkVehicle('dry');
            $driver = $this->mkDriver();
            $t = $this->mkTrip([$this->mkFo(100)->number], ['km' => $km, 'vehicleCode' => $vehicle['code'], 'driverCode' => $driver['code']]);
            Trip::where('id', $t['id'])->update(['status' => 'onroute', 'actual_start' => now()->subHours(3)]);
            Vehicle::where('code', $vehicle['code'])->update(['state' => 'onroute']);
            Driver::where('code', $driver['code'])->update(['state' => 'onroute']);
            TripStop::where('trip_id', $t['id'])->update(['status' => 'delivered']);

            return [$t, $vehicle['code']];
        };
        $costing = fn (callable $compute) => $this->app->bind(TripCostService::class, fn () => new class($compute)
        {
            public function __construct(private $compute) {}

            public function compute(Trip|string $trip): array
            {
                return ($this->compute)($trip);
            }
        });
        $odometer = fn (string $code) => Vehicle::where('code', $code)->value('odometer');

        // the reading at the return: never below what the vehicle already shows; preferred over the planned distance
        [$t, $code] = $onRoute(30);
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/close", ['odometer' => 900]), 'ODOMETER_BACKWARDS', [422]);
        $this->assertStringContainsString('أقل من عداد المركبة الحالي 1000', $body['message']);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/close", ['odometer' => 'far']), 'INVALID_INPUT', [400]);
        $this->assertSame(['onroute', 1000], [Trip::find($t['id'])->status, $odometer($code)]);

        // costing breaks: the trip closes all the same, the message does not claim a cost, and an activity line reports it
        $costing(fn () => throw new RuntimeException('no rate table'));
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/close", ['odometer' => 1300]));
        $this->assertSame(['closed', false, 1300], [$closed['status'], $closed['costComputed'], $odometer($code)]);
        $this->assertStringContainsString('تعذّر حساب التكلفة', $closed['message']);
        $this->assertStringContainsString('قراءة فعلية', $closed['message']);
        $this->assertStringNotContainsString('KPIs', $closed['message']);
        $this->assertSame(1, ActivityLog::where('entity_number', $t['number'])->where('text_ar', 'like', '%تعذّر حساب تكلفة الرحلة%')->where('text_ar', 'like', '%no rate table%')->count());
        $this->assertSame('available', Vehicle::where('code', $code)->value('state'));
        $this->assertStringContainsString('"odometerReading":1300', AuditLog::where('action', 'TRIP.CLOSE')->where('entity_id', $t['id'])->value('new_value'));

        // costing answers with a computed sheet: the message says what it is — and only then
        [$t, $code] = $onRoute(42);
        $costing(function (Trip|string $trip) {
            Assert::assertNotNull($trip->actual_end, 'the cost is computed on the closed trip (driver hours need the end time)');

            return ['fuel' => '120.50', 'driver' => 80, 'tolls' => 0, 'computedAt' => now()->toJSON()];
        });
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"));
        $this->assertTrue($closed['costComputed']);
        $this->assertStringContainsString('حُسبت التكلفة: 200.50 ر.س', $closed['message']);
        $this->assertStringContainsString('cost computed: 200.50 SAR', $closed['messageEn']);
        $this->assertSame(1042, $odometer($code), 'no reading → the planned distance is added, as before');

        // a sheet that carries no computation stamp is not announced as a computed cost
        [$t] = $onRoute(10);
        $costing(fn () => ['fuel' => 0, 'computedAt' => null]);
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"));
        $this->assertSame(['closed', false], [$closed['status'], $closed['costComputed']]);
        $this->assertStringContainsString('لم تُحسب التكلفة بعد', $closed['message']);

        // … and neither is a computed sheet of zeros: nothing was recorded to compute from, and the message says that
        [$t] = $onRoute(10);
        $costing(fn () => ['fuel' => 0, 'driver' => 0, 'computedAt' => now()->toJSON(), 'basis' => ['fuel' => ['source' => 'not_configured'], 'driver' => ['source' => 'not_configured']]]);
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"));
        $this->assertStringContainsString('لا تكلفة محسوبة', $closed['message']);
        $this->assertStringNotContainsString('حُسبت التكلفة', $closed['message']);
        // a real figure with components that had no source says both
        [$t] = $onRoute(10);
        $costing(fn () => ['fuel' => 90, 'computedAt' => now()->toJSON(), 'basis' => ['fuel' => ['source' => 'actual'], 'driver' => ['source' => 'not_configured'], 'dep' => ['source' => 'not_configured']]]);
        $this->assertStringContainsString('حُسبت التكلفة: 90.00 ر.س (2 بنود بلا مصدر', $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/close"))['message']);
    }

    public function test_list_filters_detail_and_tower(): void
    {
        $page = $this->expectOk($this->getAs('sales', '/api/transport/trips?pageSize=3&status=planned,closed'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertLessThanOrEqual(3, count($page['items']));
        foreach ($page['items'] as $row) {
            $this->assertContains($row['status'], ['planned', 'closed']);
            $this->assertSame(['stops', 'orders', 'pods'], array_keys($row['_count']));
            $this->assertSame(['code', 'nameAr'], array_keys($row['warehouse']));
        }
        $byVehicle = $this->expectOk($this->getAs('disp', '/api/transport/trips?vehicle=V-21&driver=DRV-13'));
        $this->assertSame(['TRP-2026-0029'], array_column($byVehicle['items'], 'number'));
        $this->assertSame(['code', 'plateAr', 'kind', 'typeAr', 'maxKg', 'state'], array_keys($byVehicle['items'][0]['vehicle']));
        $this->assertSame(['code', 'nameAr', 'nameEn', 'state'], array_keys($byVehicle['items'][0]['driver']));
        $this->assertSame(['TRP-2026-0032'], array_column($this->expectOk($this->getAs('disp', '/api/transport/trips?date=2026-09-10&warehouse=RYD'))['items'], 'number'));
        $this->assertSame(1, $this->expectOk($this->getAs('disp', '/api/transport/trips?q=TRP-2026-0028'))['total']);

        $byId = $this->expectOk($this->getAs('disp', '/api/transport/trips/'.Trip::where('number', 'TRP-2026-0029')->value('id')));
        $this->assertSame('TRP-2026-0029', $byId['number']);
        $this->expectRejected($this->getAs('disp', '/api/transport/trips/TRP-0000-NOPE'), 'TRIP_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('disp', '/api/transport/trips/TRP-0000-NOPE/recommend'), 'TRIP_NOT_FOUND', [404]);

        $tower = $this->expectOk($this->getAs('disp', '/api/transport/tower'));
        foreach (['date', 'tripsToday', 'tripsTodayTotal', 'vehicles', 'drivers', 'openAlerts', 'lateTrips', 'lateCount', 'openOpsRequests', 'capacityUtilisationPct', 'activeTrips'] as $key) {
            $this->assertArrayHasKey($key, $tower);
        }
        $this->assertSame(['available', 'onroute', 'off', 'blocked'], array_keys($tower['drivers']));
        $this->assertIsInt($tower['drivers']['available']);
        $this->assertTrue(array_is_list($tower['lateTrips']));
        $this->assertGreaterThanOrEqual(1, $tower['vehicles']['onroute']);
        // no telematics provider is connected: the tower carries counters only, never a fabricated position
        $this->assertStringNotContainsString('"lat"', json_encode($tower));
        $this->assertArrayHasKey('activeTrips', $this->expectOk($this->getAs('disp', '/api/transport/tower?warehouse=JED')));
    }

    public function test_permission_denied_changes_nothing(): void
    {
        $fo = $this->mkFo(100);
        $before = Trip::count();
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->postAs('worker', '/api/transport/trips', ['warehouseCode' => 'RYD', 'date' => '2026-09-20', 'routeAr' => 'x', 'foNumbers' => [$fo->number]]), 'FORBIDDEN', [403]);
        $this->assertSame($before, Trip::count());
        $this->assertNull(FulfillmentOrder::find($fo->id)->trip_id);

        $t = $this->mkTrip([$fo->number]);
        $this->expectRejected($this->postAs('sales', "/api/transport/trips/{$t['number']}/assign", ['vehicleCode' => 'V-08']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('driver', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('wm', "/api/transport/trips/{$t['number']}/close"), 'FORBIDDEN', [403]);
        $fresh = Trip::find($t['id']);
        $this->assertSame(['planned', null], [$fresh->status, $fresh->vehicle_id]);
        $this->assertSame('available', Vehicle::where('code', 'V-08')->value('state'));
        $this->assertSame($denied + 4, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
        // reading stays open to every signed-in user
        $this->expectOk($this->getAs('worker', "/api/transport/trips/{$t['number']}"));
    }
}
