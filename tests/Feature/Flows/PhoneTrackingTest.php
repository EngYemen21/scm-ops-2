<?php

namespace Tests\Feature\Flows;

use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\DriverPosition;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\Delivery\PhoneTrackingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;

/**
 * Driver phone tracking: nothing is kept outside an active trip or before the driver's consent; fixes are validated,
 * de-duplicated and tied to the trip + vehicle; the maps compare the phone with the truck.
 */
class PhoneTrackingTest extends ApiTestCase
{
    use FlowFixtures;

    private function fresh(): Driver
    {
        $d = $this->demoDriver();
        $d->update(['phone_consent_at' => null, 'phone_lat' => null, 'phone_lng' => null, 'phone_at' => null, 'phone_trip_id' => null]);
        // the story needs exactly one tracked trip for this driver: park any other one
        Trip::where('driver_id', $d->id)->whereIn('status', PhoneTrackingService::TRACKED_TRIP_STATES)->update(['status' => 'closed']);

        return $d->refresh();
    }

    private function point(float $lat, float $lng, int $secondsAgo = 0, array $extra = []): array
    {
        return array_merge(['lat' => $lat, 'lng' => $lng, 'accuracy' => 12, 'speed' => 10, 'heading' => 90, 'at' => now()->subSeconds($secondsAgo)->toIso8601ZuluString('millisecond')], $extra);
    }

    public function test_nothing_is_tracked_without_a_trip_or_without_consent(): void
    {
        $this->fresh();
        $st = $this->expectOk($this->getAs('driver', '/api/delivery/tracking'));
        $this->assertSame([false, false, null], [$st['tracking'], $st['needsConsent'], $st['trip']]);
        $this->expectRejected($this->postAs('driver', '/api/delivery/tracking/points', ['points' => [$this->point(24.7, 46.7)]]), 'TRACKING_NOT_CONSENTED', [422]);

        // consented but no active trip: the phone is told to stop and nothing is stored
        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/consent', ['accepted' => true]));
        $r = $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['points' => [$this->point(24.7, 46.7)]]));
        $this->assertSame([0, false], [$r['accepted'], $r['tracking']]);
        $this->assertSame(0, DriverPosition::where('driver_id', $this->demoDriver()->id)->count());

        // not a driver / no permission
        $this->expectRejected($this->getAs('sales', '/api/delivery/tracking'), 'NOT_A_DRIVER', [403]);
        $this->expectRejected($this->postAs('sales', '/api/delivery/tracking/points', ['points' => [$this->point(24.7, 46.7)]]), 'FORBIDDEN', [403]);
    }

    public function test_consent_then_fixes_are_stored_on_the_trip_and_bad_ones_rejected(): void
    {
        $d = $this->fresh();
        [$trip] = $this->dispatchedTrip($d, [[[$this->product('PT-A'), 1]]]);

        $st = $this->expectOk($this->getAs('driver', '/api/delivery/tracking'));
        $this->assertSame([false, true, $trip->number], [$st['tracking'], $st['needsConsent'], $st['trip']['number']]);
        $audits = AuditLog::where('action', 'DRIVER.PHONE_TRACKING_CONSENT')->where('entity_id', $d->id)->count();
        $st = $this->expectOk($this->postAs('driver', '/api/delivery/tracking/consent', ['accepted' => true]));
        $this->assertTrue($st['tracking']);
        $this->assertSame($audits + 1, AuditLog::where('action', 'DRIVER.PHONE_TRACKING_CONSENT')->where('entity_id', $d->id)->count());

        $batch = [
            $this->point(24.7000, 46.7000, 120),
            $this->point(24.7010, 46.7010, 60),
            $this->point(24.7020, 46.7020, 0, ['speed' => 15]),            // 15 m/s → 54 km/h
            $this->point(24.7, 46.7, 0, ['at' => now()->addHour()->toIso8601ZuluString()]),     // future
            $this->point(24.7, 46.7, 0, ['at' => now()->subDays(2)->toIso8601ZuluString()]),    // too old
            $this->point(0, 0, 5),                                            // null island
            $this->point(24.7, 46.7, 10, ['accuracy' => 5000]),              // useless accuracy
        ];
        $r = $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['platform' => 'android', 'points' => $batch]));
        $this->assertSame([3, 4, true], [$r['accepted'], $r['rejected'], $r['tracking']]);
        $rows = DriverPosition::where('trip_id', $trip->id)->orderBy('at')->get();
        $this->assertCount(3, $rows);
        $this->assertSame($trip->vehicle_id, $rows[0]->vehicle_id);
        $this->assertSame(54.0, $rows[2]->speed_kph);

        // the same batch again (a retry after a lost response) adds nothing
        $r = $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['points' => array_slice($batch, 0, 3)]));
        $this->assertSame(0, $r['accepted']);
        $this->assertSame(3, DriverPosition::where('trip_id', $trip->id)->count());

        $d->refresh();
        $this->assertSame([24.702, 46.702, 'android', $trip->id], [$d->phone_lat, $d->phone_lng, $d->phone_platform, $d->phone_trip_id]);

        // the trip ends: the phone is told to stop, nothing more is kept
        $trip->update(['status' => 'closed']);
        $r = $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['points' => [$this->point(24.71, 46.71)]]));
        $this->assertSame([0, false], [$r['accepted'], $r['tracking']]);

        // withdrawing consent stops tracking on the next trip
        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/consent', ['accepted' => false]));
        $this->assertNull($d->refresh()->phone_consent_at);
    }

    public function test_the_trip_map_compares_the_phone_with_the_truck(): void
    {
        $d = $this->fresh();
        [$trip] = $this->dispatchedTrip($d, [[[$this->product('PT-B'), 1]]]);
        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/consent', ['accepted' => true]));

        // before any fix: waiting
        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}/map"));
        $this->assertSame('waiting', $map['phone']['status']);

        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['points' => [$this->point(24.70, 46.70, 30), $this->point(24.7005, 46.7005, 0)]]));
        // the truck (Wialon) is 5 km away with a fresh fix → flagged "apart"
        Vehicle::whereKey($trip->vehicle_id)->update(['lat' => 24.745, 'lng' => 46.70, 'gps_at' => now()]);
        $map = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}/map"));
        $this->assertSame('live', $map['phone']['status']);
        $this->assertTrue($map['phone']['apart']);
        $this->assertGreaterThan(4500, $map['phone']['metresFromTruck']);
        $this->assertCount(2, $map['phoneTrail']);

        // truck next to the driver → not apart
        Vehicle::whereKey($trip->vehicle_id)->update(['lat' => 24.7006, 'lng' => 46.7006, 'gps_at' => now()]);
        $this->assertFalse($this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}/map"))['phone']['apart']);

        // the overview carries the phone of every open trip
        $o = $this->expectOk($this->getAs('disp', '/api/transport/map'));
        $mine = collect($o['trips'])->firstWhere('number', $trip->number);
        $this->assertSame('live', $mine['phone']['status']);

        // silence for longer than the stale window → "stale" (the app was force-closed or lost the network)
        $d->update(['phone_at' => now()->subMinutes(PhoneTrackingService::STALE_MINUTES + 5)]);
        $this->assertSame('stale', $this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}/map"))['phone']['status']);
    }

    public function test_the_phone_trail_is_cleaned_and_snapped_to_the_streets(): void
    {
        $d = $this->fresh();
        [$trip] = $this->dispatchedTrip($d, [[[$this->product('PT-C'), 1]]]);
        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/consent', ['accepted' => true]));
        $this->expectOk($this->postAs('driver', '/api/delivery/tracking/points', ['points' => [
            $this->point(24.7000, 46.7000, 300, ['accuracy' => 6]),
            $this->point(24.70003, 46.70002, 280, ['accuracy' => 6]),   // 4 m away while standing: GPS noise
            $this->point(24.7040, 46.7000, 240, ['accuracy' => 80]),    // imprecise fix: stored, not drawn
            $this->point(24.7010, 46.7000, 200, ['accuracy' => 5]),
            $this->point(24.7020, 46.7010, 100, ['accuracy' => 5]),
        ]]));
        $this->assertSame(5, DriverPosition::where('trip_id', $trip->id)->count());
        $url = "/api/transport/trips/{$trip->number}/map";

        // no maps provider: the cleaned recorded line
        config(['integrations.MAPBOX_PUBLIC_TOKEN' => null]);
        $map = $this->expectOk($this->getAs('disp', $url));
        $this->assertSame([[24.7, 46.7], [24.701, 46.7], [24.702, 46.701]], array_map(fn ($p) => [$p['lat'], $p['lng']], $map['phoneTrail']));
        $this->assertSame(['matched' => false, 'lines' => [[[46.7, 24.7], [46.7, 24.701], [46.701, 24.702]]]], $map['phoneRoute']);

        // Mapbox: the line follows the streets; fix accuracy → search radius, fix time → timestamp
        config(['integrations.MAPBOX_PUBLIC_TOKEN' => 'pk.test-token']);
        $street = [[46.7, 24.7], [46.7001, 24.7005], [46.7, 24.701], [46.7005, 24.7015], [46.701, 24.702]];
        $matching = fn (float $confidence) => ['code' => 'Ok', 'matchings' => [['confidence' => $confidence, 'geometry' => ['type' => 'LineString', 'coordinates' => $street]]]];
        Http::fake(['api.mapbox.com/matching/*' => Http::sequence()->push($matching(0.93))
            ->push($matching(0.05))->push($matching(0.6))       // 2nd load: not a road → the walking network places it
            ->push(['code' => 'NoMatch'], 200)->push($matching(0.05))]); // 3rd load: neither → recorded line
        $map = $this->expectOk($this->getAs('disp', $url));
        $this->assertSame(['matched' => true, 'lines' => [$street]], $map['phoneRoute']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/matching/v5/mapbox/driving?access_token=pk.test-token')
            && $r['coordinates'] === '46.7,24.7;46.7,24.701;46.701,24.702' && $r['radiuses'] === '12;10;10' && count(explode(';', $r['timestamps'])) === 3);

        // reopened: served from the cache, no second provider call
        $this->expectOk($this->getAs('disp', $url));
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/matching/')));

        // off the road network (the driver walking to a door): matched on foot
        Cache::flush();
        $this->assertSame(['matched' => true, 'lines' => [$street]], $this->expectOk($this->getAs('disp', $url))['phoneRoute']);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/matching/v5/mapbox/walking?')));

        // a match Mapbox cannot make or is unsure of is not drawn: the recorded line is
        Cache::flush();
        $this->assertSame(['matched' => false, 'lines' => [[[46.7, 24.7], [46.7, 24.701], [46.701, 24.702]]]], $this->expectOk($this->getAs('disp', $url))['phoneRoute']);
    }
}
