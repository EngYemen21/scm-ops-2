<?php

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\FulfillmentOrder;
use App\Models\StatusHistory;
use App\Models\Trip;
use App\Models\TripOrder;
use App\Models\TripStop;
use App\Models\Vehicle;
use App\Services\Core\SettingsService;
use App\Services\Transport\TripsService;
use App\Support\AppError;
use Tests\ApiTestCase;

/**
 * Trips (TMS): planning from packed fulfillment orders, vehicle recommendation, assignment rules, stop order,
 * cancel / close and the control tower. Seeded fleet used for the rejections: V-15 maintenance · V-09 out of service ·
 * DRV-11 blocked · DRV-16 off shift · DRV-13 on route (TRP-2026-0029).
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

        // honest integration status: no ETA / delay is invented for a trip nobody tracks yet
        $this->assertNull($t['eta']);
        $this->assertSame(0, $t['delayMin']);

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
        $this->assertSame('مرفوض: وثائق السائق تنتهي قريبًا — الإسناد موقوف', $body['message']);
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
        // once loading started the assignment is locked
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$first['number']}/assign", ['driverCode' => 'DRV-07']), 'TRIP_LOCKED', [422]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$first['number']}/unassign"), 'TRIP_LOCKED', [422]);
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
        $this->assertSame('TRIP_NOT_READY', $code(fn () => $trips->assertCanDispatch($t['number'])));
        Trip::where('id', $t['id'])->update(['status' => 'loading']);
        $this->assertSame('TRIP_NOT_LOADED', $code(fn () => $trips->assertCanDispatch($t['number'])));
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => true]);
        $this->assertSame($t['number'], $trips->assertCanDispatch($t['number'])->number);
        Trip::where('id', $t['id'])->update(['status' => 'planned']);
        TripOrder::where('trip_id', $t['id'])->update(['loaded' => false]);
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/unassign", ['vehicle' => true, 'driver' => true]));
        $this->assertSame('available', Vehicle::where('code', $vehicle['code'])->value('state'));
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
