<?php

namespace Tests\Feature\Transport;

use App\Models\AuditLog;
use App\Models\TripStop;
use App\Models\Warehouse;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\MapboxMaps;
use App\Services\Integrations\Adapters\PendingMaps;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;

/**
 * The transport map: honest `integration_pending` without a provider, the Mapbox adapter behind a faked HTTP layer,
 * and provider-optimised stop order going through the normal reorder rule. No test ever calls the real provider.
 */
class TransportMapTest extends ApiTestCase
{
    use TransportFixtures;

    private const TOKEN = 'pk.test-public-token';

    /** A planned trip with three stops placed at distinct points. @return array{0:array, 1:string[]} */
    private function locatedTrip(): array
    {
        $t = $this->mkTrip([$this->mkFo(100)->number, $this->mkFo(120)->number, $this->mkFo(90)->number]);
        $ids = array_column($t['stops'], 'id');
        foreach ([[24.70, 46.68], [24.75, 46.80], [24.66, 46.60]] as $i => [$lat, $lng]) {
            TripStop::whereKey($ids[$i])->update(['lat' => $lat, 'lng' => $lng]);
        }

        return [$t, $ids];
    }

    private function useMapbox(): void
    {
        config(['integrations.MAPBOX_PUBLIC_TOKEN' => self::TOKEN]);
    }

    public function test_without_a_provider_the_map_is_honestly_pending(): void
    {
        $this->assertInstanceOf(PendingMaps::class, AdapterFactory::maps());
        Http::fake();
        [$t] = $this->locatedTrip();

        $cfg = $this->expectOk($this->getAs('disp', '/api/transport/map/config'));
        $this->assertSame(['provider' => null, 'configured' => false, 'token' => null, 'status' => 'integration_pending'], array_intersect_key($cfg, array_flip(['provider', 'configured', 'token', 'status'])));

        // stored coordinates are still served — only the provider-computed parts are pending
        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/map"));
        $this->assertSame('RYD', $map['origin']['code']);
        $this->assertNotNull($map['origin']['lat'], 'demo warehouses carry coordinates');
        $this->assertCount(3, $map['stops']);
        $this->assertSame(24.75, $map['stops'][1]['lat']);
        $this->assertSame('integration_pending', $map['route']['status']);
        $this->assertFalse($map['optimize']['allowed']);
        $this->assertSame('MAPS_PENDING', $map['optimize']['code']);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"), 'MAPS_PENDING', [422]);

        $overview = $this->expectOk($this->getAs('disp', '/api/transport/map?warehouse=RYD'));
        $this->assertSame(['RYD'], array_column($overview['warehouses'], 'code'));
        $this->assertContains($t['number'], array_column($overview['trips'], 'number'));
        $this->assertSame('integration_pending', $overview['gps']['status']);
        $this->assertSame([], array_values(array_filter($overview['vehicles'], fn ($v) => $v['at'] === null || $v['source'] !== 'gps')), 'every drawn vehicle carries a provider fix with its time — none is invented');
        Http::assertNothingSent();
    }

    public function test_only_a_public_token_is_ever_handed_to_the_browser(): void
    {
        config(['integrations.MAPBOX_PUBLIC_TOKEN' => 'sk.secret-token']);
        $this->assertInstanceOf(PendingMaps::class, AdapterFactory::maps());
        $this->assertNull($this->expectOk($this->getAs('disp', '/api/transport/map/config'))['token']);

        $this->useMapbox();
        $this->assertInstanceOf(MapboxMaps::class, AdapterFactory::maps());
        $cfg = $this->expectOk($this->getAs('worker', '/api/transport/map/config'));
        $this->assertSame(['mapbox', true, self::TOKEN], [$cfg['provider'], $cfg['configured'], $cfg['token']]);
        $this->assertSame(401, $this->getJson('/api/transport/map/config')->getStatusCode(), 'the token is for signed-in users only');
    }

    public function test_trip_route_comes_from_the_provider_and_is_cached(): void
    {
        $this->useMapbox();
        [$t] = $this->locatedTrip();
        $line = ['type' => 'LineString', 'coordinates' => [[46.844, 24.6408], [46.68, 24.70], [46.844, 24.6408]]];
        Http::fake(['api.mapbox.com/directions/*' => Http::response(['code' => 'Ok', 'routes' => [[
            'duration' => 5400, 'distance' => 61250, 'geometry' => $line, 'legs' => [['duration' => 1200, 'distance' => 14000], ['duration' => 1500, 'distance' => 16000], ['duration' => 1300, 'distance' => 15000], ['duration' => 1400, 'distance' => 16250]],
        ]]])]);

        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/map"));
        $this->assertSame(['ok', 90, 61.3], [$map['route']['status'], $map['route']['minutes'], $map['route']['distanceKm']]);
        $this->assertSame($line, $map['route']['geometry']);
        $this->assertCount(4, $map['route']['legs'], 'warehouse → 3 stops → warehouse');
        $this->assertTrue($map['optimize']['allowed']);

        $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/map"));
        Http::assertSentCount(1); // second read served from the cache
        Http::assertSent(function (Request $r) {
            // lng,lat order; round trip from the warehouse; traffic-aware profile; the token travels as a query value
            return str_contains($r->url(), '/directions/v5/mapbox/driving-traffic/46.844,24.6408;46.68,24.7;46.8,24.75;46.6,24.66;46.844,24.6408') && str_contains($r->url(), 'access_token='.self::TOKEN);
        });
    }

    public function test_provider_failure_is_reported_not_hidden(): void
    {
        $this->useMapbox();
        [$t] = $this->locatedTrip();
        Http::fake(['api.mapbox.com/*' => Http::response(['code' => 'InvalidInput', 'message' => 'bad coordinate'], 422)]);

        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/map"));
        $this->assertSame('error', $map['route']['status']);
        $this->assertStringContainsString('InvalidInput', $map['route']['detail']);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"), 'MAPS_ERROR', [422]);
        $this->assertSame(array_column($t['stops'], 'id'), TripStop::where('trip_id', $t['id'])->orderBy('seq')->pluck('id')->all(), 'a failed optimisation changes nothing');
    }

    public function test_optimise_reorders_the_stops_through_the_normal_rule(): void
    {
        $this->useMapbox();
        [$t, $ids] = $this->locatedTrip();
        Http::fake([
            // waypoints answer in INPUT order (warehouse, stop 1, 2, 3); waypoint_index = position in the optimised tour
            'api.mapbox.com/optimized-trips/*' => Http::response(['code' => 'Ok', 'trips' => [['distance' => 48700, 'duration' => 4380]],
                'waypoints' => [['waypoint_index' => 0], ['waypoint_index' => 3], ['waypoint_index' => 1], ['waypoint_index' => 2]]]),
            'api.mapbox.com/directions/*' => Http::response(['code' => 'Ok', 'routes' => [['duration' => 4380, 'distance' => 48700, 'geometry' => ['type' => 'LineString', 'coordinates' => []], 'legs' => []]]]),
        ]);

        // RBAC: optimising is a trip-management action
        $security = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->postAs('sales', "/api/transport/trips/{$t['number']}/optimize"), 'FORBIDDEN', [403]);
        $this->assertSame($security + 1, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());

        $res = $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"));
        $this->assertTrue($res['changed']);
        $this->assertSame([48.7, 73], [$res['totalKm'], $res['totalMinutes']]);
        $this->assertSame([$ids[1], $ids[2], $ids[0]], array_column($res['map']['stops'], 'id'));
        $this->assertSame([$ids[1], $ids[2], $ids[0]], TripStop::where('trip_id', $t['id'])->orderBy('seq')->pluck('id')->all());
        $this->assertSame(1, AuditLog::where('action', 'TRIP.REORDER_STOPS')->where('entity_number', $t['number'])->count());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/optimized-trips/v1/mapbox/driving/46.844,24.6408;') && str_contains($r->url(), 'source=first') && str_contains($r->url(), 'roundtrip=true'));
    }

    public function test_optimise_refuses_what_it_cannot_do_and_says_why(): void
    {
        $this->useMapbox();
        Http::fake();
        [$t, $ids] = $this->locatedTrip();

        // a stop with no coordinates (its customer has none either)
        TripStop::whereKey($ids[2])->update(['lat' => null, 'lng' => null, 'customer_id' => null]);
        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$t['number']}/map"));
        $this->assertSame([3], array_column($map['unlocated'], 'seq'));
        $this->assertSame('MAP_STOPS_UNLOCATED', $map['optimize']['code']);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"), 'MAP_STOPS_UNLOCATED', [422]);
        TripStop::whereKey($ids[2])->update(['lat' => 24.66, 'lng' => 46.60]);

        // a warehouse without a pin
        $ryd = Warehouse::where('code', 'RYD')->firstOrFail();
        [$lat, $lng] = [$ryd->lat, $ryd->lng];
        $ryd->update(['lat' => null, 'lng' => null]);
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"), 'MAP_NO_ORIGIN', [422]);
        $ryd->update(['lat' => $lat, 'lng' => $lng]);

        // a trip past planning is locked
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$t['number']}/cancel", ['reason' => 'اختبار']));
        $this->expectRejected($this->postAs('disp', "/api/transport/trips/{$t['number']}/optimize"), 'TRIP_LOCKED', [422]);
        $this->expectRejected($this->getAs('disp', '/api/transport/trips/TRP-NOPE/map'), 'TRIP_NOT_FOUND', [404]);
    }

    public function test_a_warehouse_gets_its_pin_from_the_api(): void
    {
        $code = 'M'.chr(65 + random_int(0, 25)).chr(65 + random_int(0, 25));
        Warehouse::where('code', $code)->delete();
        $this->expectOk($this->postAs('admin', '/api/warehouses', ['code' => $code, 'nameAr' => 'مستودع الخريطة', 'city' => 'الرياض', 'areaM2' => 900, 'lat' => 24.71, 'lng' => 46.67]));
        $this->assertSame([24.71, 46.67], [Warehouse::where('code', $code)->value('lat'), Warehouse::where('code', $code)->value('lng')]);

        $this->expectRejected($this->patchAs('admin', "/api/warehouses/{$code}", ['lat' => 95, 'lng' => 46]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('admin', "/api/warehouses/{$code}", ['lat' => 24.7]), 'INVALID_INPUT', [400]);
        $this->expectOk($this->patchAs('admin', "/api/warehouses/{$code}", ['lat' => 24.72, 'lng' => 46.69]));
        $this->assertSame(24.72, Warehouse::where('code', $code)->value('lat'));
        $this->expectOk($this->patchAs('admin', "/api/warehouses/{$code}", ['lat' => null, 'lng' => null]));
        $this->assertNull(Warehouse::where('code', $code)->value('lat'));
    }
}
