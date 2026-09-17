<?php

namespace Tests\Feature\Transport;

use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\FleetAlert;
use App\Models\FuelRecord;
use App\Models\MaintenanceOrder;
use App\Models\Notification;
use App\Models\OpsRequest;
use App\Models\Route;
use App\Models\StatusHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\ApiTestCase;

/** Fleet: vehicles (+ state machine, breakdown), drivers (+ login creation), maintenance, fuel, alerts, ops requests, routes, KPIs. */
class FleetTest extends ApiTestCase
{
    use TransportFixtures;

    private const OPEN = ['open', 'assigned', 'snoozed'];

    // ───────── vehicles ─────────
    public function test_vehicle_crud(): void
    {
        $code = self::code('tv-x'); // stored upper-case
        $payload = ['code' => $code, 'plateAr' => 'ق ب ل 1234', 'plateEn' => 'QBL 1234', 'vin' => "VIN{$code}", 'brand' => 'Isuzu', 'model' => 'NPR', 'maxKg' => '3000', 'maxCbm' => 16,
            'warehouseCode' => 'RYD', 'regExpiry' => '2028-01-01', 'insuranceExpiry' => '2028-01-01', 'inspectionExpiry' => '2028-02-01'];
        $res = $this->postAs('disp', '/api/transport/vehicles', $payload);
        $this->assertSame(201, $res->getStatusCode());
        $v = $this->expectOk($res);
        $code = mb_strtoupper($code);
        $this->assertSame($code, $v['code']);
        $this->assertSame(['dry', 'owned', 8, 0, 'available', 'جافة', 'Dry box', '—'], [$v['kind'], $v['ownership'], $v['pallets'], $v['odometer'], $v['state'], $v['typeAr'], $v['typeEn'], $v['tempRange']], 'schema defaults');
        $this->assertEquals(3000, $v['maxKg'], 'numeric strings are coerced');
        $this->assertSame(10000, $v['nextMaintKm']);
        $this->assertSame(10000, $v['maintenanceDueKm']);
        $this->assertStringStartsWith('2028-01-01', $v['opCardExpiry'], 'operating card defaults to the registration date');
        $this->assertSame(['regExpiry', 'insuranceExpiry', 'inspectionExpiry', 'opCardExpiry'], array_column($v['docs'], 'key'));
        $this->assertSame(['key', 'labelAr', 'labelEn', 'date', 'days', 'expired'], array_keys($v['docs'][0]));
        $this->assertSame(min(array_column($v['docs'], 'days')), $v['docDays']);
        $this->assertSame(['ok' => true], $v['canAssign']);
        $this->assertSame(['reserved', 'assigned', 'maintenance', 'oos', 'inactive'], $v['allowedTransitions']);
        $this->assertSame(['trips' => 0, 'pods' => 0], $v['_count']);
        $this->assertSame([], $v['alerts']);
        $this->assertNull($v['activeTrip']);
        $this->assertSame('RYD', $v['warehouse']['code']);
        // telematics are not connected: a new vehicle is offline and has no position
        $this->assertFalse($v['gpsOnline']);
        $this->assertNull($v['lat']);
        $this->assertNull($v['lng']);
        $this->assertNull($v['lastSyncAt']);
        $this->assertSame(1, AuditLog::where('action', 'VEHICLE.CREATE')->where('entity_number', $code)->count());
        $this->assertSame('available', StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->value('to_status'));
        $this->assertSame(1, ActivityLog::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->count());

        // rejections
        $this->expectRejected($this->postAs('disp', '/api/transport/vehicles', $payload), 'VEHICLE_CODE_TAKEN', [409]);
        $this->expectRejected($this->postAs('disp', '/api/transport/vehicles', ['code' => self::code('TV-Y'), 'warehouseCode' => 'ZZZ'] + $payload), 'WH_NOT_FOUND', [404]);
        $invalid = $this->expectRejected($this->postAs('disp', '/api/transport/vehicles', ['code' => self::code('TV-Y'), 'maxKg' => 0, 'kind' => 'boat', 'regExpiry' => '01/01/2028'] + $payload), 'INVALID_INPUT', [400]);
        $this->assertEqualsCanonicalizing(['maxKg', 'kind', 'regExpiry'], array_column($invalid['details'], 'path'));

        // read: by code, by id, list with search + filters
        $this->assertSame($code, $this->expectOk($this->getAs('sales', "/api/transport/vehicles/{$v['id']}"))['code']);
        $this->expectRejected($this->getAs('sales', '/api/transport/vehicles/V-NOPE'), 'VEHICLE_NOT_FOUND', [404]);
        $list = $this->expectOk($this->getAs('sales', "/api/transport/vehicles?q={$code}&kind=dry&warehouse=RYD&state=available,assigned"));
        $this->assertSame(1, $list['total']);
        $row = $list['items'][0];
        $this->assertSame(['code', 'nameAr'], array_keys($row['warehouse']));
        $this->assertSame(['trips' => 0], $row['_count']);
        $this->assertSame([], $row['maintenance']);
        $this->assertSame(0, $row['openAlerts']);
        $this->assertTrue($row['canAssign']['ok']);
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/transport/vehicles?q={$code}&kind=reefer"))['total']);
        $seeded = collect($this->expectOk($this->getAs('sales', '/api/transport/vehicles?pageSize=100'))['items'])->keyBy('code');
        $this->assertFalse($seeded['V-15']['canAssign']['ok']);
        $this->assertStringContainsString('صيانة', $seeded['V-15']['canAssign']['why']);
        $this->assertStringContainsString('خارج الخدمة', $seeded['V-09']['canAssign']['why']);

        // update: each changed field is audited; the kind drives the type labels
        $audits = AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count();
        $u = $this->expectOk($this->patchAs('disp', "/api/transport/vehicles/{$code}", ['maxKg' => 3500, 'kind' => 'chill', 'plateAr' => 'ق ب ل 1234', 'nextMaintDate' => '2027-01-01', 'warehouseCode' => 'JED']));
        $this->assertEquals(3500, $u['maxKg']);
        $this->assertSame(['chill', 'مبردة +4°', 'JED'], [$u['kind'], $u['typeAr'], $u['warehouse']['code']]);
        $this->assertSame($audits + 4, AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count(), 'maxKg, kind, nextMaintDate, warehouse — the unchanged plate is not audited');
        $this->expectOk($this->patchAs('disp', "/api/transport/vehicles/{$code}", ['maxKg' => 3500]));
        $this->assertSame($audits + 4, AuditLog::where('action', 'VEHICLE.UPDATE')->where('entity_id', $v['id'])->count(), 'no change, no audit');
        $this->expectRejected($this->patchAs('disp', "/api/transport/vehicles/{$code}", ['maxKg' => -1]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('disp', "/api/transport/vehicles/{$code}", ['warehouseCode' => 'ZZZ']), 'WH_NOT_FOUND', [404]);
        $off = $this->expectOk($this->patchAs('disp', "/api/transport/vehicles/{$code}", ['active' => false]));
        $this->assertFalse($off['active']);
        $this->assertFalse($off['canAssign']['ok']);
    }

    public function test_vehicle_state_machine_every_transition(): void
    {
        $v = $this->mkVehicle('dry');
        $table = config('scm.VEHICLE_TRANSITIONS');
        $states = config('scm.VEHICLE_STATES');
        $this->assertCount(12, $states);
        $allowed = 0;
        foreach ($states as $from) {
            foreach ($states as $to) {
                if ($from === $to) {
                    continue;
                }
                Vehicle::where('code', $v['code'])->update(['state' => $from]);
                $res = $this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => $to]);
                if (in_array($to, $table[$from], true)) {
                    $allowed++;
                    $this->assertSame($to, $this->expectOk($res)['vehicle']['state'], "{$from} → {$to} must be allowed");
                } else {
                    $this->expectRejected($res, 'VEHICLE_TRANSITION', [422]);
                    $this->assertSame($from, Vehicle::where('code', $v['code'])->value('state'), "{$from} → {$to} must not change the vehicle");
                }
            }
        }
        $this->assertSame(array_sum(array_map('count', $table)), $allowed);
        Vehicle::where('code', $v['code'])->update(['state' => 'available']);
        FleetAlert::where('entity_code', $v['code'])->update(['status' => 'resolved']);

        // messages, audit trail and the same-state no-op
        $body = $this->expectRejected($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'onroute']), 'VEHICLE_TRANSITION', [422]);
        $this->assertSame('انتقال غير مسموح: متاحة ← في الطريق', $body['message']);
        $history = StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->count();
        $r = $this->expectOk($this->postAs('wm', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'oos', 'reason' => 'اختبار'])); // wm holds vehicle.state
        $this->assertSame('oos', $r['vehicle']['state']);
        $this->assertSame(['vehicle', 'alert', 'alternate', 'message'], array_keys($r));
        $this->assertNull($r['alert']);
        $this->assertStringContainsString('خارج الخدمة — اختبار', $r['message']);
        $this->assertSame($history + 1, StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->count());
        $this->assertSame('اختبار', StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->orderByDesc('at')->orderByDesc('id')->value('note'));
        $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'oos']));
        $this->assertSame($history + 1, StatusHistory::where('entity_type', 'Vehicle')->where('entity_id', $v['id'])->count(), 'same state = no-op');
        $this->expectRejected($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'available']), 'VEHICLE_TRANSITION', [422]);
        $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'maintenance']));
        $this->assertSame('available', $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'available']))['vehicle']['state']);
        $this->expectRejected($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state", ['to' => 'flying']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', "/api/transport/vehicles/{$v['code']}/state"), 'INVALID_INPUT', [400]);
    }

    public function test_breakdown_raises_a_critical_alert_with_an_alternate_of_the_same_kind(): void
    {
        $reefer = $this->mkVehicle('reefer');
        FleetAlert::where('category', 'breakdown')->whereIn('status', self::OPEN)->update(['status' => 'resolved']);
        // make the suggestion deterministic: the only other assignable reefer is ours
        $others = Vehicle::where('kind', 'reefer')->where('state', 'available')->where('code', '!=', $reefer['code'])->pluck('id');
        Vehicle::whereIn('id', $others)->update(['state' => 'reserved']);
        $reefer2 = $this->mkVehicle('reefer');
        try {
            $this->expectRejected($this->postAs('disp', "/api/transport/vehicles/{$reefer['code']}/breakdown", ['type' => 'engine']), 'INVALID_INPUT', [400]);
            $r = $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$reefer['code']}/breakdown", ['vehicleCode' => $reefer['code'], 'type' => 'engine', 'location' => 'طريق الملك فهد', 'desc' => 'عطل محرك', 'canMove' => false]));
        } finally {
            Vehicle::whereIn('id', $others)->update(['state' => 'available']);
        }
        $this->assertSame('breakdown', $r['vehicle']['state'], 'a breakdown is a fact: recorded from any state');
        $this->assertSame(['breakdown', 'c', 'open', 'Dispatcher'], [$r['alert']['category'], $r['alert']['severity'], $r['alert']['status'], $r['alert']['owner']]);
        $this->assertStringStartsWith('AL-', $r['alert']['code']);
        $this->assertSame(['code' => $reefer2['code'], 'typeAr' => $reefer2['typeAr'], 'kind' => 'reefer'], $r['alternate']);
        $this->assertStringContainsString("البديل المقترح {$reefer2['code']}", $r['alert']['textAr']);
        $this->assertStringContainsString('تحتاج سطحة', $r['alert']['textAr']);
        $this->assertSame(1, Notification::where('entity_type', 'FleetAlert')->where('entity_number', $r['alert']['code'])->where('role_key', 'disp')->count());

        // reporting again updates the same alert: one open alert per vehicle + category
        $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$reefer['code']}/breakdown", ['location' => 'الدائري', 'desc' => 'ما زال معطلًا', 'canMove' => true]));
        $this->assertSame(1, FleetAlert::where('entity_code', $reefer['code'])->where('category', 'breakdown')->whereIn('status', self::OPEN)->count());
        $detail = $this->expectOk($this->getAs('disp', "/api/transport/vehicles/{$reefer['code']}"));
        $this->assertCount(1, $detail['alerts']);
        $this->assertFalse($detail['canAssign']['ok']);

        // alert actions: assign → snooze → resolve, each audited; a resolved alert is final
        $al = $r['alert']['code'];
        $assigned = $this->expectOk($this->postAs('disp', "/api/transport/alerts/{$al}/assign", ['owner' => 'مشرف الورشة']));
        $this->assertSame(['assigned', 'مشرف الورشة'], [$assigned['status'], $assigned['owner']]);
        $snoozed = $this->expectOk($this->postAs('disp', "/api/transport/alerts/{$al}/snooze", ['hours' => 2]));
        $this->assertSame('snoozed', $snoozed['status']);
        $this->assertSame('أُجّل التنبيه 2 ساعة', $snoozed['message']);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), strtotime($snoozed['snoozedUntil']), 120);
        $resolved = $this->expectOk($this->postAs('disp', "/api/transport/alerts/{$al}/resolve")); // action button: no body
        $this->assertSame('resolved', $resolved['status']);
        $this->assertNotNull($resolved['resolvedAt']);
        $this->assertNull($resolved['snoozedUntil']);
        $this->assertSame(['assigned', 'snoozed', 'resolved'], StatusHistory::where('entity_type', 'FleetAlert')->where('entity_id', $r['alert']['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->expectRejected($this->postAs('disp', "/api/transport/alerts/{$al}/resolve"), 'ALERT_RESOLVED', [422]);
        $this->expectRejected($this->postAs('disp', '/api/transport/alerts/AL-NOPE/resolve'), 'ALERT_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('disp', "/api/transport/alerts/{$al}/snooze", ['hours' => 0]), 'INVALID_INPUT', [400]);

        // breakdown through the state machine is only possible on the road, and notifies the dispatcher as well
        $onroute = $this->mkVehicle('dry');
        Vehicle::where('code', $onroute['code'])->update(['state' => 'onroute']);
        $viaState = $this->expectOk($this->postAs('disp', "/api/transport/vehicles/{$onroute['code']}/state", ['to' => 'breakdown', 'reason' => 'إطار']));
        $this->assertSame('breakdown', $viaState['alert']['category']);
        $this->assertArrayHasKey('plateAr', $viaState['alternate'] ?? ['plateAr' => null]);
        $this->assertStringContainsString('سُجل العطل', $viaState['message']);
    }

    // ───────── drivers ─────────
    public function test_driver_crud_and_login_creation(): void
    {
        $code = self::code('TD-L');
        $username = 'Drv-'.self::uid(); // stored lower-case
        $payload = ['code' => $code, 'nameAr' => 'سائق القبول', 'nameEn' => 'Acceptance driver', 'employeeNo' => "EMP-{$code}", 'mobile' => '0555000111', 'licenseNo' => "L-{$code}", 'licenseType' => 'ثقيل',
            'licenseExpiry' => '2028-06-01', 'iqamaExpiry' => '2028-06-01', 'shift' => 'pm', 'defaultVehicleCode' => 'V-08'];

        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', $payload + ['username' => $username]), 'DRIVER_USER', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', $payload + ['username' => $username, 'password' => 'short']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', $payload + ['username' => 'admin', 'password' => 'Scm@2026x']), 'USERNAME_TAKEN', [409]);
        $this->assertFalse(Driver::where('code', $code)->exists(), 'a rejected login leaves no driver behind');
        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', ['defaultVehicleCode' => 'V-NOPE'] + $payload), 'VEHICLE_NOT_FOUND', [404]);

        $res = $this->postAs('disp', '/api/transport/drivers', $payload + ['username' => $username, 'password' => 'Scm@2026x']);
        $this->assertSame(201, $res->getStatusCode());
        $d = $this->expectOk($res);
        $this->assertStringNotContainsStringIgnoringCase('password', $res->getContent(), 'no password material in the response');
        $this->assertSame([$code, 'available', 'مسائية 14–22', 'PM', false, true], [$d['code'], $d['state'], $d['shift'], $d['shiftEn'], $d['blocked'], $d['active']]);
        $this->assertSame(['id', 'username', 'active', 'lastLoginAt'], array_keys($d['user']));
        $this->assertSame(mb_strtolower($username), $d['user']['username']);
        $this->assertSame(['code' => 'V-08', 'plateAr' => Vehicle::where('code', 'V-08')->value('plate_ar'), 'typeAr' => Vehicle::where('code', 'V-08')->value('type_ar')], $d['defaultVehicle']);
        $this->assertSame(['licenseExpiry', 'iqamaExpiry', 'medicalExpiry'], array_column($d['docs'], 'key'));
        $this->assertSame(['ok' => true], $d['canAssign']);
        $this->assertSame('متاح', $d['stateLabel']);
        $this->assertSame([], $d['tripsList']);
        $this->assertSame(0, $d['performance']['trips']);

        // the login user: hashed password, driver role through user_roles, forced password change
        $user = User::where('username', mb_strtolower($username))->firstOrFail();
        $this->assertNotSame('Scm@2026x', $user->password_hash);
        $this->assertTrue(password_verify('Scm@2026x', $user->password_hash));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertSame(['driver'], DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_id', $user->id)->pluck('roles.key')->all());
        $this->assertSame(1, AuditLog::where('action', 'USER.CREATE')->where('entity_id', $user->id)->count());
        $this->assertStringNotContainsString('Scm@2026x', (string) AuditLog::where('action', 'DRIVER.CREATE')->where('entity_number', $code)->value('new_value'));

        // and it really signs in, as a driver bound to this driver record
        $login = $this->postJson('/api/auth/login', ['username' => $username, 'password' => 'Scm@2026x']);
        $this->assertTrue($login->isSuccessful(), $login->getContent());
        $this->assertSame(['driver'], $login->json('user.roles'));
        $this->assertSame($d['id'], $login->json('user.driverId'));
        $this->assertTrue($login->json('user.mustChangePassword'));
        $this->assertContains('opreq.create', $login->json('user.permissions'));
        $token = $login->json('accessToken');
        $this->assertSame(403, $this->postJson('/api/transport/vehicles', [], ['Authorization' => "Bearer {$token}"])->getStatusCode());
        $this->postJson('/api/auth/login', ['username' => $username, 'password' => 'wrong-password'])->assertStatus(401);

        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', $payload), 'DRIVER_CODE_TAKEN', [409]);
        $this->expectRejected($this->postAs('disp', '/api/transport/drivers', ['code' => self::code('TD-V'), 'shift' => 'night'] + $payload), 'INVALID_INPUT', [400]);

        // list
        $list = $this->expectOk($this->getAs('sales', "/api/transport/drivers?q={$code}"));
        $this->assertSame(1, $list['total']);
        $this->assertSame(['username', 'active'], array_keys($list['items'][0]['user']));
        $this->assertSame(['code', 'plateAr'], array_keys($list['items'][0]['defaultVehicle']));
        $this->assertStringNotContainsStringIgnoringCase('password', json_encode($list));
        $blocked = $this->expectOk($this->getAs('sales', '/api/transport/drivers?blocked=true'));
        $this->assertContains('DRV-11', array_column($blocked['items'], 'code'));
        $this->assertNotContains($code, array_column($blocked['items'], 'code'));
        $this->assertSame(['DRV-16'], array_column($this->expectOk($this->getAs('sales', '/api/transport/drivers?state=off'))['items'], 'code'));
        $this->expectRejected($this->getAs('sales', '/api/transport/drivers/DRV-NOPE'), 'DRIVER_NOT_FOUND', [404]);

        // update: audited per field; manual block
        $u = $this->expectOk($this->patchAs('disp', "/api/transport/drivers/{$code}", ['mobile' => '0555999888', 'shift' => 'flex', 'blocked' => true, 'defaultVehicleCode' => null]));
        $this->assertSame(['0555999888', 'مرنة', 'Flex', true, null], [$u['mobile'], $u['shift'], $u['shiftEn'], $u['blocked'], $u['defaultVehicle']]);
        $this->assertFalse($u['canAssign']['ok']);
        $this->assertSame(4, AuditLog::where('action', 'DRIVER.UPDATE')->where('entity_id', $d['id'])->count());
        $this->expectOk($this->patchAs('disp', "/api/transport/drivers/{$code}", ['blocked' => false]));

        // state: off → available → inactive (deactivates) ; a driver on the road cannot be switched
        $off = $this->expectOk($this->postAs('disp', "/api/transport/drivers/{$code}/state", ['to' => 'off', 'reason' => 'إجازة']));
        $this->assertSame(['off', 'خارج الوردية', true], [$off['state'], $off['stateLabel'], $off['active']]);
        $this->assertSame('السائق خارج الوردية', $off['canAssign']['why']);
        $inactive = $this->expectOk($this->postAs('disp', "/api/transport/drivers/{$code}/state", ['to' => 'inactive']));
        $this->assertSame(['inactive', false], [$inactive['state'], $inactive['active']]);
        $back = $this->expectOk($this->postAs('disp', "/api/transport/drivers/{$code}/state", ['to' => 'available']));
        $this->assertSame(['available', true], [$back['state'], $back['active']]);
        $this->assertSame(['available', 'off', 'inactive', 'available'], StatusHistory::where('entity_type', 'Driver')->where('entity_id', $d['id'])->orderBy('at')->orderBy('id')->pluck('to_status')->all());
        $this->expectRejected($this->postAs('disp', "/api/transport/drivers/{$code}/state", ['to' => 'onroute']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/drivers/DRV-13/state', ['to' => 'off']), 'DRIVER_ON_TRIP', [422]);

        // incidents lower the safety score (−8 / −4 / −2) and count complaints
        $inc = $this->expectOk($this->postAs('disp', "/api/transport/drivers/{$code}/incidents", ['type' => 'complaint', 'desc' => 'شكوى عميل', 'severity' => 'high']));
        $this->assertEquals(92, $inc['safety']);
        $this->assertSame('complaint', $inc['incident']['type']);
        $low = $this->expectOk($this->postAs('disp', "/api/transport/drivers/{$code}/incidents", ['type' => 'late', 'desc' => 'تأخر', 'date' => '2026-09-01']));
        $this->assertEquals(90, $low['safety'], 'severity defaults to low');
        $fresh = Driver::where('code', $code)->first();
        $this->assertSame([2, 1], [$fresh->incidents, $fresh->complaints]);
        $this->assertCount(2, $this->expectOk($this->getAs('disp', "/api/transport/drivers/{$code}"))['incidentsList']);
        $this->expectRejected($this->postAs('disp', "/api/transport/drivers/{$code}/incidents", ['type' => 'ufo', 'desc' => 'x']), 'INVALID_INPUT', [400]);
    }

    // ───────── maintenance / fuel ─────────
    public function test_maintenance_blocks_and_releases_the_vehicle(): void
    {
        $v = $this->mkVehicle('dry');
        $this->expectRejected($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'desc' => 'زيت']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => 'V-NOPE', 'desc' => 'زيت', 'shop' => 'ورشة', 'startDate' => '2026-09-15']), 'VEHICLE_NOT_FOUND', [404]);

        $res = $this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'kind' => 'oil', 'desc' => 'زيت', 'shop' => 'ورشة', 'startDate' => '2026-09-15', 'odometer' => 1500, 'cost' => 100, 'block' => true]);
        $this->assertSame(201, $res->getStatusCode());
        $mo = $this->expectOk($res);
        $this->assertStringStartsWith('MNT-', $mo['number']);
        $this->assertSame(['open', 'oil', 'زيت', 'Oil', 1, '1 يوم', 'maintenance'], [$mo['status'], $mo['kind'], $mo['typeAr'], $mo['typeEn'], $mo['downDays'], $mo['downLabel'], $mo['vehicleState']]);
        $this->assertStringContainsString('غير قابلة للإسناد', $mo['message']);
        $detail = $this->expectOk($this->getAs('disp', "/api/transport/vehicles/{$v['code']}"));
        $this->assertSame('maintenance', $detail['state']);
        $this->assertFalse($detail['canAssign']['ok']);
        $this->assertCount(1, $detail['openMaintenance']);
        $this->assertEquals(100, $detail['openMaintenanceCost']);

        // a second, non-blocking order keeps the vehicle in maintenance until both are closed
        $second = $this->expectOk($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'desc' => 'فرامل', 'shop' => 'ورشة', 'startDate' => '2026-09-15']));
        $this->assertSame(['corrective', 'maintenance'], [$second['kind'], $second['vehicleState']], 'kind defaults to corrective; block defaults to false');
        $list = $this->expectOk($this->getAs('disp', "/api/transport/maintenance?vehicle={$v['code']}&status=open"));
        $this->assertSame(2, $list['total']);
        $this->assertSame(['code', 'plateAr', 'state'], array_keys($list['items'][0]['vehicle']));

        $this->expectRejected($this->postAs('disp', "/api/transport/maintenance/{$mo['number']}/close"), 'INVALID_INPUT', [400]);
        $first = $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$mo['number']}/close", ['endDate' => '2026-09-17', 'parts' => 60, 'labor' => 40, 'nextKm' => 12000, 'note' => 'تم']));
        $this->assertSame(['closed', 2, false], [$first['status'], $first['downDays'], $first['vehicleReleased']]);
        $this->assertEquals(100, $first['cost'], 'parts + labor');
        $this->assertSame('maintenance', Vehicle::where('code', $v['code'])->value('state'));
        $closed = $this->expectOk($this->postAs('disp', "/api/transport/maintenance/{$second['number']}/close", ['endDate' => '2026-09-17', 'cost' => 250]));
        $this->assertTrue($closed['vehicleReleased']);
        $this->assertEquals(250, $closed['cost']);
        $fresh = Vehicle::where('code', $v['code'])->first();
        $this->assertSame(['available', 12000, 1500], [$fresh->state, $fresh->next_maint_km, $fresh->odometer]);
        $this->assertSame('2026-09-17', $fresh->last_maint_date->format('Y-m-d'));
        $this->assertSame('closed', StatusHistory::where('entity_type', 'MaintenanceOrder')->where('entity_id', $mo['id'])->value('to_status'));

        $this->expectRejected($this->postAs('disp', "/api/transport/maintenance/{$mo['number']}/close", ['endDate' => '2026-09-17']), 'MAINT_CLOSED', [422]);
        $this->expectRejected($this->postAs('disp', '/api/transport/maintenance/MNT-NOPE/close', ['endDate' => '2026-09-17']), 'MAINT_NOT_FOUND', [404]);

        // blocking needs a legal state change: an assigned vehicle cannot silently go to maintenance, and nothing is saved
        Vehicle::where('code', $v['code'])->update(['state' => 'assigned']);
        $count = MaintenanceOrder::count();
        $this->expectRejected($this->postAs('disp', '/api/transport/maintenance', ['vehicleCode' => $v['code'], 'desc' => 'x', 'shop' => 'y', 'startDate' => '2026-09-15', 'block' => true]), 'VEHICLE_TRANSITION', [422]);
        $this->assertSame($count, MaintenanceOrder::count());
    }

    public function test_fuel_price_ceiling_odometer_and_anomaly(): void
    {
        $v = $this->mkVehicle('dry'); // odometer 1000, average 6 km/L
        $driver = $this->mkDriver();
        $base = ['vehicleCode' => $v['code'], 'date' => '2026-09-15'];

        $body = $this->expectRejected($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 990, 'liters' => 50, 'cost' => 100]), 'FUEL_ODOMETER', [422]);
        $this->assertSame('قراءة العداد أقل من آخر قراءة مسجلة (1,000)', $body['message']);
        $body = $this->expectRejected($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1100, 'liters' => 10, 'cost' => 100]), 'FUEL_PRICE', [422]);
        $this->assertSame('سعر اللتر غير منطقي (> 4 ر.س) — راجع المبلغ', $body['message']);
        $this->expectRejected($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1100, 'liters' => 0, 'cost' => 100]), 'INVALID_INPUT', [400]);
        $this->assertSame(0, FuelRecord::where('vehicle_id', $v['id'])->count());

        // the ceiling is the fleet.fuelMaxPricePerLiter policy
        $settings = $this->app->make(SettingsService::class);
        $settings->set('fleet.fuelMaxPricePerLiter', 12);
        try {
            $pricey = $this->expectOk($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1060, 'liters' => 10, 'cost' => 100]));
            $this->assertFalse($pricey['anomaly'], '6 km/L equals the average');
        } finally {
            $settings->set('fleet.fuelMaxPricePerLiter', 4);
        }

        // 100 km on 50 L = 2 km/L < 6 × 0.75 → anomaly + alert
        $res = $this->postAs('disp', '/api/transport/fuel', $base + ['driverCode' => $driver['code'], 'odometer' => 1160, 'liters' => 50, 'cost' => 100, 'station' => 'أرامكو']);
        $this->assertSame(201, $res->getStatusCode());
        $f = $this->expectOk($res);
        $this->assertStringStartsWith('FL-', $f['number']);
        $this->assertEquals(2, $f['kmPerL']);
        $this->assertEquals(1, $f['costPerKm']);
        $this->assertTrue($f['anomaly']);
        $this->assertTrue($f['full'], 'schema default');
        $this->assertSame(['fuel', 'w', $v['code']], [$f['alert']['category'], $f['alert']['severity'], $f['alert']['entityCode']]);
        $this->assertStringContainsString('2 كم/ل مقابل متوسط 6', $f['alert']['textAr']);
        $this->assertStringContainsString('انحراف', $f['message']);
        $this->assertSame(1160, Vehicle::where('code', $v['code'])->value('odometer'));
        $this->assertSame(2, AuditLog::where('action', 'VEHICLE.ODOMETER')->where('entity_id', $v['id'])->count());

        // the anomaly threshold is the fleet.fuelAnomalyRatio policy: 5 km/L is fine at 0.75 (limit 4.5) …
        $ok = $this->expectOk($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1260, 'liters' => 20, 'cost' => 50, 'full' => false]));
        $this->assertSame([false, false, null], [$ok['anomaly'], $ok['full'], $ok['alert']]);
        // … and flagged at 0.9 (limit 5.4)
        $settings->set('fleet.fuelAnomalyRatio', 0.9);
        try {
            $this->assertTrue($this->expectOk($this->postAs('disp', '/api/transport/fuel', $base + ['odometer' => 1360, 'liters' => 20, 'cost' => 50]))['anomaly']);
        } finally {
            $settings->set('fleet.fuelAnomalyRatio', 0.75);
        }
        $this->assertSame(1, FleetAlert::where('entity_code', $v['code'])->where('category', 'fuel')->whereIn('status', self::OPEN)->count(), 'one open alert per vehicle + category');

        $list = $this->expectOk($this->getAs('sales', "/api/transport/fuel?vehicle={$v['code']}&anomaly=true"));
        $this->assertSame(2, $list['total']);
        $this->assertSame(['code', 'plateAr', 'avgKmL'], array_keys($list['items'][0]['vehicle']));
        $this->assertSame(1, $this->expectOk($this->getAs('sales', "/api/transport/fuel?driver={$driver['code']}"))['total']);
        $this->assertCount(4, $this->expectOk($this->getAs('sales', "/api/transport/vehicles/{$v['code']}"))['fuelHistory']);
    }

    // ───────── alerts ─────────
    public function test_document_alerts_are_idempotent(): void
    {
        $quiet = $this->mkVehicle('dry'); // far-future documents → never alerted
        $stats = $this->postAs('disp', '/api/transport/alerts/generate'); // action button: no body
        $this->assertSame(201, $stats->getStatusCode());
        $this->assertSame(['created', 'updated', 'resolved'], array_keys($stats->json()));

        $v09 = FleetAlert::where('entity_code', 'V-09')->where('category', 'vehdoc')->whereIn('status', self::OPEN)->get();
        $this->assertGreaterThan(0, $v09->count());
        $this->assertSame('c', $v09->first()->severity, 'inspection expired on 2026-09-12');
        $this->assertStringContainsString('منتهية منذ', $v09->first()->text_ar);
        $total = FleetAlert::whereIn('status', self::OPEN)->count();
        $again = $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'));
        $this->assertSame(0, $again['created'], 'second run: nothing new');
        $this->assertSame($total, FleetAlert::whereIn('status', self::OPEN)->count());
        $this->assertSame(0, FleetAlert::where('entity_code', $quiet['code'])->whereIn('category', ['vehdoc', 'maint'])->count());

        // a condition that clears resolves its alert automatically
        Vehicle::where('code', $quiet['code'])->update(['reg_expiry' => now()->addDays(5)]);
        $this->assertSame(1, $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'))['created']);
        $alert = FleetAlert::where('entity_code', $quiet['code'])->where('category', 'vehdoc')->firstOrFail();
        $this->assertSame(['c', 'open'], [$alert->severity, $alert->status]);
        $this->expectOk($this->patchAs('disp', "/api/transport/vehicles/{$quiet['code']}", ['regExpiry' => self::FAR]));
        $this->expectOk($this->postAs('disp', '/api/transport/alerts/generate'));
        $this->assertSame('resolved', $alert->refresh()->status);
        $this->assertSame('auto: condition cleared', StatusHistory::where('entity_type', 'FleetAlert')->where('entity_id', $alert->id)->value('note'));

        // list: open ones by default, paging envelope + the severity summary
        $list = $this->expectOk($this->getAs('sales', '/api/transport/alerts?pageSize=5'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages', 'openBySeverity'], array_keys($list));
        $this->assertSame($list['total'], array_sum($list['openBySeverity']));
        $this->assertSame('c', $list['items'][0]['severity'], 'critical first');
        $resolved = $this->expectOk($this->getAs('sales', "/api/transport/alerts?status=resolved&entity={$quiet['code']}"));
        $this->assertSame([$alert->code], array_column($resolved['items'], 'code'));
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/transport/alerts?entity={$quiet['code']}"))['total']);
        $this->assertGreaterThan(0, $this->expectOk($this->getAs('sales', '/api/transport/alerts?category=vehdoc,driverdoc&severity=c'))['total']);
    }

    // ───────── driver ops requests ─────────
    public function test_ops_requests_flow(): void
    {
        $v = $this->mkVehicle('dry');
        $d = $this->mkDriver();

        // schema refinements are input errors with their field path
        $invalid = $this->expectRejected($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'fuel', 'amount' => 0, 'desc' => 'x', 'driverCode' => $d['code']]), 'INVALID_INPUT', [400]);
        $this->assertSame([['path' => 'amount', 'message' => 'هذا النوع يحتاج مبلغًا']], $invalid['details']);
        $invalid = $this->expectRejected($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'fuel', 'amount' => 233, 'desc' => 'x', 'driverCode' => $d['code']]), 'INVALID_INPUT', [400]);
        $this->assertSame([['path' => 'attachment', 'message' => 'المبلغ يحتاج إيصالًا مرفقًا']], $invalid['details']);
        $this->expectRejected($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'snacks', 'desc' => 'x']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/ops-requests', ['type' => 'other', 'desc' => 'x']), 'FORBIDDEN', [403]); // the dispatcher manages, drivers create

        $res = $this->postAs('admin', '/api/transport/ops-requests', ['type' => 'fuel', 'amount' => 233, 'desc' => 'تعبئة', 'attachment' => 'r.jpg', 'driverCode' => $d['code'], 'vehicleCode' => $v['code'], 'location' => 'أرامكو']);
        $this->assertSame(201, $res->getStatusCode());
        $r = $this->expectOk($res);
        $this->assertStringStartsWith('OPR-', $r['number']);
        $this->assertSame(['submitted', 'وقود', 'مُرسل', $v['code'], $d['nameAr'], null], [$r['status'], $r['typeLabel'], $r['statusLabel'], $r['vehicleCode'], $r['driverName'], $r['alert']]);
        $this->assertSame(1, Notification::where('entity_type', 'OpsRequest')->where('entity_id', $r['id'])->where('role_key', 'disp')->count());

        $url = "/api/transport/ops-requests/{$r['number']}/status";
        $body = $this->expectRejected($this->postAs('disp', $url, ['to' => 'closed']), 'OPREQ_TRANSITION', [422]);
        $this->assertSame('انتقال غير مسموح: مُرسل ← مغلق', $body['message']);
        $this->expectRejected($this->postAs('disp', $url, ['to' => 'rejected']), 'OPREQ_REJECT_NOTE', [400]);
        $this->expectRejected($this->postAs('disp', $url), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, ['to' => 'approved']), 'FORBIDDEN', [403]);
        $this->assertSame('submitted', OpsRequest::find($r['id'])->status);

        $this->assertSame('approved', $this->expectOk($this->postAs('disp', $url, ['to' => 'approved']))['status']);
        $p = $this->expectOk($this->postAs('disp', $url, ['to' => 'processed']));
        $this->assertSame(['processed', 'تمت المعالجة'], [$p['status'], $p['statusLabel']]);
        $this->assertEquals(100, $p['fuelRecord']['liters'], '233 SAR ÷ 2.33');
        $this->assertEquals(233, $p['fuelRecord']['cost']);
        $this->assertNull($p['maintenanceOrder']);
        $this->assertStringContainsString('سُجل في سجل الوقود', $p['message']);
        $this->expectOk($this->postAs('disp', $url, ['to' => 'closed']));

        $detail = $this->expectOk($this->getAs('disp', "/api/transport/ops-requests/{$r['number']}"));
        $this->assertSame(['submitted', 'approved', 'processed', 'closed'], array_column($detail['history'], 'toStatus'));
        $this->assertSame([], $detail['allowed']);
        $this->assertSame(['code', 'nameAr', 'mobile'], array_keys($detail['driver']));
        $this->expectRejected($this->getAs('disp', '/api/transport/ops-requests/OPR-NOPE'), 'OPREQ_NOT_FOUND', [404]);

        // a tire request becomes a maintenance order when processed; rejecting needs a reason
        $tire = $this->expectOk($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'tire', 'desc' => 'إطار تالف', 'driverCode' => $d['code'], 'vehicleCode' => $v['code']]));
        $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$tire['number']}/status", ['to' => 'approved']));
        $done = $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$tire['number']}/status", ['to' => 'processed']));
        $this->assertSame(['tire', 'open'], [$done['maintenanceOrder']['kind'], $done['maintenanceOrder']['status']]);
        $other = $this->expectOk($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'other', 'desc' => 'استفسار']));
        $rejected = $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$other['number']}/status", ['to' => 'rejected', 'note' => 'غير مبرر']));
        $this->assertSame('rejected', $rejected['status']);
        $noVehicle = $this->expectOk($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'maint', 'desc' => 'بلا مركبة']));
        $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$noVehicle['number']}/status", ['to' => 'approved']));
        $this->expectRejected($this->postAs('disp', "/api/transport/ops-requests/{$noVehicle['number']}/status", ['to' => 'processed']), 'OPREQ_NO_VEHICLE', [422]);
        $this->assertSame('approved', OpsRequest::find($noVehicle['id'])->status, 'the failed processing rolled back');

        // an emergency starts in review and raises a critical alert on the vehicle
        $emergency = $this->expectOk($this->postAs('admin', '/api/transport/ops-requests', ['type' => 'emergency', 'desc' => 'حادث بسيط', 'vehicleCode' => $v['code'], 'location' => 'الدائري الشرقي']));
        $this->assertSame(['review', 'c', 'breakdown'], [$emergency['status'], $emergency['alert']['severity'], $emergency['alert']['category']]);

        // a driver creates for himself and sees only his own requests; the dispatcher sees them all
        $mine = $this->expectOk($this->postAs('driver', '/api/transport/ops-requests', ['type' => 'other', 'desc' => 'طلب من تطبيق السائق', 'driverCode' => $d['code']]));
        $own = Driver::where('user_id', User::where('username', 'driver')->value('id'))->firstOrFail();
        $this->assertSame($own->name_ar, $mine['driverName'], 'the signed-in driver wins over a driverCode in the body');
        $seen = $this->expectOk($this->getAs('driver', '/api/transport/ops-requests?pageSize=100'));
        $this->assertSame([$own->code], array_values(array_unique(array_column(array_column($seen['items'], 'driver'), 'code'))));
        $all = $this->expectOk($this->getAs('disp', '/api/transport/ops-requests?pageSize=100'));
        $this->assertGreaterThan($seen['total'], $all['total']);
        $this->assertSame(['review', 'approved', 'rejected'], collect($all['items'])->firstWhere('number', $mine['number'])['allowed']);
        $this->assertSame(1, $this->expectOk($this->getAs('disp', "/api/transport/ops-requests?type=emergency&status=review&driver=&q={$emergency['number']}"))['total']);

        // the driver is notified personally when his request moves
        $this->expectOk($this->postAs('disp', "/api/transport/ops-requests/{$mine['number']}/status", ['to' => 'review']));
        $this->assertSame(1, Notification::where('user_id', $own->user_id)->where('entity_id', $mine['id'])->count());
    }

    // ───────── routes / KPIs ─────────
    public function test_routes_create_update_list(): void
    {
        $name = 'مسار '.self::uid();
        $this->expectRejected($this->postAs('disp', '/api/transport/routes', ['name' => $name]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('disp', '/api/transport/routes', ['name' => $name, 'zones' => 'العليا', 'warehouseCode' => 'ZZZ']), 'WH_NOT_FOUND', [404]);
        $res = $this->postAs('disp', '/api/transport/routes', ['name' => $name, 'warehouseCode' => 'RYD', 'zones' => 'العليا']);
        $this->assertSame(201, $res->getStatusCode());
        $r = $this->expectOk($res);
        $this->assertMatchesRegularExpression('/^RT-\d+$/', $r['code']);
        $this->assertSame(['الأحد – الخميس', '08:00 – 14:00', 'dry'], [$r['days'], $r['window'], $r['tempNeed']], 'schema defaults');
        $this->assertSame(1, AuditLog::where('action', 'ROUTE.CREATE')->where('entity_id', $r['id'])->count());

        $this->expectRejected($this->patchAs('disp', "/api/transport/routes/{$r['code']}", ['warehouseCode' => 'ZZZ']), 'WH_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('disp', '/api/transport/routes/RT-NOPE', ['zones' => 'x']), 'ROUTE_NOT_FOUND', [404]);
        $u = $this->patchAs('disp', "/api/transport/routes/{$r['code']}", ['tempNeed' => 'reefer', 'zones' => 'الملقا', 'name' => $name]);
        $this->assertSame(200, $u->getStatusCode());
        $this->assertSame(['reefer', 'الملقا'], [$u->json('tempNeed'), $u->json('zones')]);
        $this->assertSame(2, AuditLog::where('action', 'ROUTE.UPDATE')->where('entity_id', $r['id'])->count());

        $list = $this->expectOk($this->getAs('sales', '/api/transport/routes?tempNeed=reefer&warehouse=RYD&q='.urlencode($name)));
        $this->assertSame([$r['code']], array_column($list['items'], 'code'));
        $this->assertSame(['id', 'code', 'nameAr'], array_keys($list['items'][0]['warehouse']));
        $this->assertSame(0, $this->expectOk($this->getAs('sales', '/api/transport/routes?warehouse=ZZZ'))['total']);

        // a trip planned on the route takes its English name from it
        $trip = $this->mkTrip([$this->mkFo(50)->number], ['routeCode' => $r['code'], 'routeAr' => 'خط الملقا']);
        $this->assertSame($name, $trip['routeEn']);
        Route::where('id', $r['id'])->delete();
    }

    public function test_fleet_kpis(): void
    {
        $k = $this->expectOk($this->getAs('sales', '/api/transport/fleet/kpis'));
        $this->assertSame(['vehicles', 'drivers', 'maintenance', 'fuel', 'trips', 'driverRanking'], array_keys($k));
        $this->assertSame(['total', 'available', 'onroute', 'maintenance', 'byState', 'docsExpiring', 'docsExpiringCodes', 'maintenanceDueSoon'], array_keys($k['vehicles']));
        $this->assertSame(Vehicle::where('active', true)->count(), $k['vehicles']['total']);
        $this->assertSame($k['vehicles']['total'], array_sum($k['vehicles']['byState']));
        $this->assertContains('V-09', $k['vehicles']['docsExpiringCodes']);
        $this->assertContains('DRV-11', $k['drivers']['docsExpiringCodes']);
        $this->assertSame(['open', 'openCost'], array_keys($k['maintenance']));
        $this->assertSame(['anomalies', 'cost30d', 'liters30d'], array_keys($k['fuel']));
        $this->assertSame(['closed', 'kmTotal'], array_keys($k['trips']));
        $scores = array_column($k['driverRanking'], 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'ranking is best first');
        $this->assertSame(['code', 'nameAr', 'nameEn', 'rating', 'ontimePct', 'safety', 'okPct', 'fuelScore', 'trips', 'deliveries', 'fails', 'state', 'blocked', 'score'], array_keys($k['driverRanking'][0]));
    }

    // ───────── RBAC ─────────
    public function test_permission_denied_changes_nothing_and_is_audited(): void
    {
        $denied = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $counts = fn () => [Vehicle::count(), Driver::count(), MaintenanceOrder::count(), FuelRecord::count(), Route::count(), FleetAlert::whereIn('status', self::OPEN)->count()];
        $before = $counts();
        $alert = FleetAlert::whereIn('status', self::OPEN)->firstOrFail();

        $attempts = [
            ['sales', 'post', '/api/transport/vehicles', ['code' => 'V-HACK', 'plateAr' => 'x', 'vin' => 'x', 'brand' => 'x', 'maxKg' => 1, 'maxCbm' => 1, 'regExpiry' => self::FAR, 'insuranceExpiry' => self::FAR, 'inspectionExpiry' => self::FAR]],
            ['worker', 'patch', '/api/transport/vehicles/V-08', ['maxKg' => 1]],
            ['sales', 'post', '/api/transport/vehicles/V-08/state', ['to' => 'oos']],
            ['driver', 'post', '/api/transport/vehicles/V-08/breakdown', ['location' => 'x', 'desc' => 'x']],
            ['wm', 'post', '/api/transport/drivers', ['code' => 'DRV-HACK']],
            ['wm', 'patch', '/api/transport/drivers/DRV-07', ['blocked' => true]],
            ['sales', 'post', '/api/transport/drivers/DRV-07/state', ['to' => 'off']],
            ['sales', 'post', '/api/transport/drivers/DRV-07/incidents', ['type' => 'late', 'desc' => 'x']],
            ['wm', 'post', '/api/transport/maintenance', ['vehicleCode' => 'V-08', 'desc' => 'x', 'shop' => 'x', 'startDate' => '2026-09-15', 'block' => true]],
            ['driver', 'post', '/api/transport/fuel', ['vehicleCode' => 'V-08', 'date' => '2026-09-15', 'odometer' => 999999, 'liters' => 10, 'cost' => 20]],
            ['sales', 'post', '/api/transport/alerts/generate', []],
            ['sales', 'post', "/api/transport/alerts/{$alert->code}/resolve", []],
            ['worker', 'post', '/api/transport/routes', ['name' => 'x', 'zones' => 'x']],
        ];
        foreach ($attempts as [$user, $verb, $url, $payload]) {
            $res = $verb === 'patch' ? $this->patchAs($user, $url, $payload) : $this->postAs($user, $url, $payload);
            $body = $this->expectRejected($res, 'FORBIDDEN', [403]);
            $this->assertSame('FORBIDDEN', $body['category'], "{$user} {$verb} {$url}");
        }
        $this->assertSame($before, $counts());
        $v08 = Vehicle::where('code', 'V-08')->first();
        $this->assertSame('available', $v08->state);
        $this->assertEquals(4000, $v08->max_kg);
        $this->assertFalse((bool) Driver::where('code', 'DRV-07')->value('blocked'));
        $this->assertSame($alert->status, $alert->refresh()->status);
        $this->assertSame($denied + count($attempts), AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());

        // an unauthenticated call never reaches the module
        $this->getJson('/api/transport/vehicles')->assertStatus(401);
    }
}
