<?php

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\Vehicle;
use App\Models\VehiclePosition;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\PendingGps;
use App\Services\Integrations\Adapters\WialonGps;
use App\Services\Transport\GpsTrackingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;

/**
 * Live tracking through Wialon: token → session, units → vehicles, throttled sync, honest pending / error states.
 * The provider is always faked — no test ever reaches gps.tawasolmap.com.
 */
class GpsTrackingTest extends ApiTestCase
{
    use TransportFixtures;

    private const BASE = 'https://gps.example.test';

    private function useWialon(): void
    {
        config(['integrations.WIALON_BASE_URL' => self::BASE, 'integrations.WIALON_TOKEN' => 'wialon-test-token']);
        Cache::forget(WialonGps::SESSION_CACHE);
        Cache::forget('gps:last-sync');
    }

    /** Wialon answers as a form POST to /wialon/ajax.html with svc + params (+ sid). */
    private static function svc(Request $r): string
    {
        return (string) ($r['svc'] ?? '');
    }

    private array $items = [];

    private ?array $loginError = null;

    private array $log = [];

    private function fakeProvider(array $items): void
    {
        $this->items = $items;
        $this->loginError = null;
        $this->log = [];
        Http::fake(function (Request $r) {
            $svc = self::svc($r);
            $this->log[] = $svc;
            $items = $this->items;
            if ($this->loginError) {
                return Http::response($this->loginError);
            }
            if (! str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/wialon/ajax.html')) {
                return Http::response('not found', 404);
            }
            if ($svc === 'token/login') {
                return Http::response(['eid' => 'sid-1', 'user' => ['nm' => 'salem']]);
            }
            if ($svc === 'core/search_items') {
                return ($r['sid'] ?? null) === 'sid-1' ? Http::response(['items' => $items, 'totalItemsCount' => count($items)]) : Http::response(['error' => 1]);
            }

            return Http::response(['error' => 2]);
        });
    }

    private function unit(string $name, string $imei, float $lat, float $lng, int $secondsAgo, float $speed = 0): array
    {
        return ['id' => crc32($imei), 'nm' => $name, 'uid' => $imei, 'ph' => '+9665', 'pos' => ['t' => now()->subSeconds($secondsAgo)->timestamp, 'y' => $lat, 'x' => $lng, 's' => $speed, 'c' => 90]];
    }

    public function test_without_a_provider_everything_is_honestly_pending(): void
    {
        $this->assertInstanceOf(PendingGps::class, AdapterFactory::gps());
        Http::fake();
        $st = $this->expectOk($this->getAs('disp', '/api/transport/gps/status'));
        $this->assertSame([null, false, 'integration_pending'], [$st['provider'], $st['configured'], $st['status']]);
        $this->expectRejected($this->getAs('disp', '/api/transport/gps/units'), 'GPS_PENDING', [422]);
        $sync = $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertFalse($sync['ran']);
        $this->assertSame('integration_pending', $this->expectOk($this->getAs('disp', '/api/transport/map'))['gps']['status']);
        Http::assertNothingSent();
    }

    public function test_units_are_matched_to_vehicles_by_imei_name_or_id_and_positions_land_on_the_vehicle(): void
    {
        $this->useWialon();
        $this->assertInstanceOf(WialonGps::class, AdapterFactory::gps());
        $byImei = $this->mkVehicle('dry', extra: ['gpsDeviceId' => '356938035643809']);
        $byName = $this->mkVehicle('dry', extra: ['gpsDeviceId' => 'Truck RYD-07']);
        $orphan = $this->mkVehicle('dry', extra: ['gpsDeviceId' => 'no-such-unit']);
        $unpaired = $this->mkVehicle('dry');
        $this->fakeProvider([
            $this->unit('Isuzu 4470', '356938035643809', 24.7136, 46.6753, 30, 42.5),
            $this->unit('Truck RYD-07', '867857031234567', 24.8000, 46.7000, 60 * 60, 0), // an hour old → offline
            ['id' => 9, 'nm' => 'no position yet', 'uid' => '111'],
        ]);

        $r = $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertTrue($r['ran'] && $r['ok']);
        $this->assertSame([3, 2, 2], [$r['units'], $r['matched'], $r['updated']]);
        $this->assertContains($orphan['code'], $r['unmatched']); // demo vehicles carry prototype device ids → unmatched too
        $this->assertSame(['token/login', 'core/search_items'], $this->log);

        $v = Vehicle::where('code', $byImei['code'])->first();
        $this->assertSame([24.7136, 46.6753, 42.5, 90, true, 'Isuzu 4470'], [$v->lat, $v->lng, $v->speed_kph, $v->course, (bool) $v->gps_online, $v->gps_unit_name]);
        $this->assertNotNull($v->gps_at);
        $this->assertSame(1, VehiclePosition::where('vehicle_id', $v->id)->count());
        $old = Vehicle::where('code', $byName['code'])->first();
        $this->assertSame([24.8, false], [$old->lat, (bool) $old->gps_online], 'an old fix is shown but flagged offline');
        $this->assertNull(Vehicle::where('code', $orphan['code'])->value('lat'));
        $this->assertNull(Vehicle::where('code', $unpaired['code'])->value('lat'));

        // the map screens carry the live position, speed and time — only for vehicles the provider reported
        $map = $this->expectOk($this->getAs('worker', '/api/transport/map?warehouse=RYD'));
        $codes = array_column($map['vehicles'], 'code');
        $this->assertContains($byImei['code'], $codes);
        $this->assertNotContains($orphan['code'], $codes);
        $me = collect($map['vehicles'])->firstWhere('code', $byImei['code']);
        $this->assertSame([42.5, true, 'gps'], [$me['speedKph'], $me['gpsOnline'], $me['source']]);
        $this->assertSame('connected', $map['gps']['status']);
        $this->assertContains($orphan['code'], $map['gps']['unmatched']);

        // trail + status
        $trail = $this->expectOk($this->getAs('disp', "/api/transport/vehicles/{$byImei['code']}/trail"));
        $this->assertCount(1, $trail['trail']);
        $this->assertSame('Isuzu 4470', $trail['vehicle']['unit']);
        $st = $this->expectOk($this->getAs('disp', '/api/transport/gps/status'));
        $this->assertSame(['wialon', 'connected'], [$st['provider'], $st['status']]);
        $this->assertTrue($st['lastSync']['ok']);
        $this->expectRejected($this->getAs('disp', '/api/transport/vehicles/NOPE-1/trail'), 'VEHICLE_NOT_FOUND', [404]);
    }

    public function test_sync_is_throttled_and_a_newer_fix_extends_the_trail(): void
    {
        $this->useWialon();
        $v = $this->mkVehicle('dry', extra: ['gpsDeviceId' => '990000000000001']);
        $this->fakeProvider([$this->unit('U1', '990000000000001', 24.70, 46.60, 20)]);
        $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertCount(2, $this->log);

        // reads inside the throttle window reuse the last result: no provider call
        $this->expectOk($this->getAs('disp', '/api/transport/map'));
        $this->expectOk($this->getAs('disp', '/api/transport/map'));
        $this->assertCount(2, $this->log, 'the map did not call the provider again');
        $this->assertSame(1, VehiclePosition::where('vehicle_id', Vehicle::where('code', $v['code'])->value('id'))->count());

        // a forced sync with a newer position (session still cached → no login) appends to the trail
        $this->items = [$this->unit('U1', '990000000000001', 24.71, 46.61, 5, 30)];
        $r = $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertSame(1, $r['updated']);
        $this->assertSame(['core/search_items'], array_slice($this->log, 2));
        $this->assertSame(2, VehiclePosition::where('vehicle_id', Vehicle::where('code', $v['code'])->value('id'))->count());

        // the same (not newer) position again: nothing appended
        $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertSame(2, VehiclePosition::where('vehicle_id', Vehicle::where('code', $v['code'])->value('id'))->count());
    }

    public function test_an_expired_session_is_reopened_once_and_provider_errors_are_reported(): void
    {
        $this->useWialon();
        $this->mkVehicle('dry', extra: ['gpsDeviceId' => 'STALE-1']);
        Cache::put(WialonGps::SESSION_CACHE, 'stale-sid', now()->addMinutes(3));
        $this->fakeProvider([$this->unit('U', 'STALE-1', 24.7, 46.7, 10)]);
        $r = $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertTrue($r['ok']);
        $this->assertSame(['core/search_items', 'token/login', 'core/search_items'], $this->log, 'stale sid → error 1 → login → retry');

        // a bad token: error, no invented positions, status says so
        Cache::forget(WialonGps::SESSION_CACHE);
        Cache::forget('gps:last-sync');
        $this->loginError = ['error' => 4, 'reason' => 'INVALID_TOKEN'];
        $r = $this->expectOk($this->postAs('disp', '/api/transport/gps/sync'));
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Wialon error 4', $r['detail']);
        $this->assertSame('error', $this->expectOk($this->getAs('disp', '/api/transport/gps/status'))['status']);
        $this->expectRejected($this->getAs('disp', '/api/transport/gps/units'), 'GPS_ERROR', [422]);
        $this->assertSame('error', $this->expectOk($this->getAs('disp', '/api/transport/map'))['gps']['status']);
    }

    public function test_pairing_screen_lists_units_with_their_vehicle_and_needs_the_permission(): void
    {
        $this->useWialon();
        $v = $this->mkVehicle('dry', extra: ['gpsDeviceId' => 'Truck A']);
        $this->fakeProvider([$this->unit('Truck A', '10', 24.7, 46.7, 10), $this->unit('Truck B', '11', 24.7, 46.7, 10)]);
        $security = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->getAs('sales', '/api/transport/gps/units'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', '/api/transport/gps/sync'), 'FORBIDDEN', [403]);
        $this->assertSame($security + 2, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());

        $u = $this->expectOk($this->getAs('disp', '/api/transport/gps/units'));
        $this->assertSame(2, $u['count']);
        $this->assertSame([$v['code'], null], [collect($u['units'])->firstWhere('name', 'Truck A')['vehicle'], collect($u['units'])->firstWhere('name', 'Truck B')['vehicle']]);
    }

    public function test_the_console_command_and_apply_are_usable_without_http(): void
    {
        $v = $this->mkVehicle('dry', extra: ['gpsDeviceId' => 'APPLY-X1']);
        $r = app(GpsTrackingService::class)->apply([['id' => 'apply-1', 'name' => 'APPLY-X1', 'uid' => null, 'phone' => null, 'lat' => 21.5, 'lng' => 39.2, 'speedKph' => 10.0, 'course' => 180, 'at' => now()->toIso8601ZuluString()]]);
        $this->assertSame([1, 1], [$r['matched'], $r['updated']]);
        $this->assertSame(21.5, Vehicle::where('code', $v['code'])->value('lat'));
        $this->artisan('scm:gps-sync')->assertExitCode(0); // pending provider: "did not run" is not a failure
    }
}
