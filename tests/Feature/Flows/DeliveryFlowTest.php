<?php

namespace Tests\Feature\Flows;

use App\Finance\Models\CustomerInvoice;
use App\Finance\Models\CustomerInvoiceLine;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Carton;
use App\Models\DeliveryRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\InventoryBalance;
use App\Models\OpsException;
use App\Models\Package;
use App\Models\PodAttachment;
use App\Models\ProofOfDelivery;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\StoredFile;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripStop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\ApiTestCase;

/**
 * Driver app: own trips only, arrive before POD, one POD per stop. A proof of delivery is a scan of the right goods +
 * a signature / photo that is really stored + the driver's own figure per item; partial / failed deliveries come back
 * as approved returns that carry the batch.
 */
class DeliveryFlowTest extends ApiTestCase
{
    use DeliveryFixtures;
    use FlowFixtures;

    public function test_driver_sees_and_touches_only_own_trips(): void
    {
        $product = $this->product('DLV-OWN');
        [$mine] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 10]]]);
        [$theirs, $theirStops] = $this->dispatchedTrip(Driver::where('code', 'DRV-07')->firstOrFail(), [[[$product, 10]]]);

        $view = $this->expectOk($this->getAs('driver', '/api/delivery/my-trips'));
        $this->assertSame('integration_pending', $view['gps']['status']);
        $this->assertSame([true, true], [$view['policy']['requireScan'], $view['policy']['requireSignature']], 'the app is told the delivery policies');
        $this->assertSame(['label_damaged', 'scanner_failed', 'on_behalf'], array_column($view['policy']['noScanReasons'], 'code'));
        $numbers = array_column(array_merge([$view['current']], $view['upcoming']), 'number');
        $this->assertContains($mine->number, $numbers);
        $this->assertNotContains($theirs->number, $numbers, 'another driver\'s trip is never listed');

        $trip = $this->expectOk($this->getAs('driver', "/api/delivery/trips/{$mine->number}"));
        $this->assertSame(['total' => 1, 'done' => 0, 'delivered' => 0, 'failed' => 0], $trip['progress']);
        // a trip written without coordinates has no planned arrival — and says so instead of "integration pending"
        $this->assertSame(['status' => 'unavailable'], $trip['eta']);
        $this->assertFalse($trip['onBehalf']);
        $this->assertSame(1, $trip['nextStop']['seq']);
        $this->assertSame($trip['stops'][0]['fo']['number'], $trip['nextStop']['fo']);
        $this->assertSame($product->sku, $trip['stops'][0]['fo']['lines'][0]['product']['sku']);
        $this->assertSame('DRV-04', $trip['driver']['code']);
        $this->assertNull($trip['stops'][0]['pod']);

        // the planned arrival of the next stop, once the trip has one
        TripStop::where('trip_id', $mine->id)->update(['eta' => '09:40', 'eta_source' => 'estimate', 'window_ok' => true]);
        $this->assertSame(['status' => 'planned', 'time' => '09:40', 'source' => 'estimate', 'windowOk' => true, 'stop' => 1], $this->expectOk($this->getAs('driver', "/api/delivery/trips/{$mine->number}"))['eta']);

        $stop = $theirStops[0];
        $this->expectRejected($this->getAs('driver', "/api/delivery/trips/{$theirs->number}"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$theirs->number}/start"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/scan", ['code' => 'x']), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed']), 'NOT_YOUR_TRIP', [403]);
        $this->assertSame('pending', $stop->refresh()->status);
        $this->assertSame(0, TripEvent::where('trip_id', $theirs->id)->count());
        $this->assertSame(0, ProofOfDelivery::where('trip_id', $theirs->id)->count());

        // dispatcher: may look at any driver's trips, may not execute deliveries; a user without a driver record has no trips
        $asDisp = $this->expectOk($this->getAs('disp', '/api/delivery/my-trips?driver=DRV-07'));
        $this->assertContains($theirs->number, array_column(array_merge([$asDisp['current']], $asDisp['upcoming']), 'number'));
        $seen = $this->expectOk($this->getAs('disp', "/api/delivery/trips/{$theirs->id}"));
        $this->assertSame([$theirs->number, true], [$seen['number'], $seen['onBehalf']]);
        $this->expectRejected($this->postAs('disp', "/api/delivery/trips/{$theirs->number}/start"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('disp', "/api/delivery/stops/{$stop->id}/scan", ['code' => 'x']), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "/api/delivery/stops/{$stop->id}/arrive"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', '/api/delivery/my-trips'), 'NOT_A_DRIVER', [403]);
        $this->expectRejected($this->getAs('driver', '/api/delivery/trips/TRP-NOPE'), 'TRIP_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('driver', '/api/delivery/stops/nope/arrive'), 'STOP_NOT_FOUND', [404]);
    }

    public function test_start_arrive_deliver_with_one_pod_per_stop_and_stored_proof(): void
    {
        $product = $this->product('DLV-OK');
        [$notDispatched] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 5]]], 'loading');
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$notDispatched->number}/start"), 'TRIP_NOT_DISPATCHED', [422]);
        $this->expectRejected($this->postAs('driver', '/api/delivery/stops/'.$notDispatched->stops()->first()->id.'/arrive'), 'TRIP_STATE', [422]);

        [$trip, [$stop, $lastStop], [$fo, $lastFo]] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 30], [$this->product('DLV-OK2'), 12]], [[$product, 1]]]);
        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start")));
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start"), 'TRIP_STARTED', [409]);
        $this->assertSame(1, AuditLog::where('action', 'TRIP.START')->where('entity_number', $trip->number)->count());

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'أبو فهد']), 'ARRIVE_FIRST', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/scan", ['code' => $fo->number]), 'ARRIVE_FIRST', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive", ['gps' => ['lat' => 'north']]), 'INVALID_INPUT', [400]);
        $this->assertSame(['ok' => true, 'stop' => 1], $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive", ['gps' => ['lat' => 24.7, 'lng' => 46.7]])));
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"), 'STOP_STATE', [409]);
        $this->assertStringContainsString('GPS 24.7,46.7', TripEvent::where('trip_id', $trip->id)->orderByDesc('id')->first()->text_ar);

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver"), 'INVALID_INPUT', [400]);
        // a real ~250 KB PNG signature and a photo, with the scan of the order
        $signatureBase64 = str_repeat('iVBORw0KGgo', 30000);
        $photo = $this->photoImage(1500);
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => ' أبو فهد ', 'signature' => 'data:image/png;base64,'.$signatureBase64, 'photo' => $photo,
            'scans' => [$fo->number], 'deliveredCartons' => 0, 'gps' => ['lat' => 24.7, 'lng' => 46.7, 'accuracy' => 12.5]]));
        $this->assertStringStartsWith('POD-', $pod['pod']);
        $this->assertSame(['delivered', 42, 0, null, 'captured', true], [$pod['result'], $pod['delivered'], $pod['returned'], $pod['return'], $pod['gps'], $pod['scanOk']]);
        $this->assertSame(['signature' => 'stored', 'photo' => 'stored'], $pod['attachments'], 'no object store configured → kept in the database, never "pending"');
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'POD_DUPLICATE', [409]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed']), 'POD_DUPLICATE', [409]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/scan", ['code' => $fo->number]), 'POD_DUPLICATE', [409]);
        $this->assertSame(1, ProofOfDelivery::where('stop_id', $stop->id)->count());
        $this->assertSame(1, DeliveryRecord::where('stop_id', $stop->id)->count());

        // the files are really kept: a row that says where, and the bytes themselves
        $files = PodAttachment::whereHas('pod', fn ($q) => $q->where('number', $pod['pod']))->orderBy('kind')->get();
        $signatureBytes = base64_decode($signatureBase64);
        $this->assertSame([
            ['photo', "photo-{$pod['pod']}.jpg", 'stored', 'image/jpeg', 1500, "pod/{$pod['pod']}/photo.jpg"],
            ['signature', "signature-{$pod['pod']}.png", 'stored', 'image/png', strlen($signatureBytes), "pod/{$pod['pod']}/signature.png"],
        ], $files->map(fn ($a) => [$a->kind, $a->file_name, $a->status, $a->mime, $a->size, $a->storage_key])->all());
        $this->assertSame("/api/integrations/files/pod/{$pod['pod']}/signature.png", $files[1]->url);
        $stored = StoredFile::where('key', "pod/{$pod['pod']}/signature.png")->firstOrFail();
        $this->assertSame([strlen($signatureBytes), hash('sha256', $signatureBytes), 'image/png', 'driver'], [$stored->size, $stored->sha256, $stored->mime, $stored->created_by]);
        $this->assertSame(0, PodAttachment::where('status', 'integration_pending')->whereHas('pod', fn ($q) => $q->where('trip_id', $trip->id))->count());

        // … and read back by a signed-in user: the bytes, or a data URL for the client
        $raw = $this->getAs('disp', "/api/integrations/files/pod/{$pod['pod']}/signature.png");
        $raw->assertOk();
        $this->assertSame($signatureBytes, $raw->getContent());
        $this->assertSame(['image/png', 'nosniff'], [$raw->headers->get('Content-Type'), $raw->headers->get('X-Content-Type-Options')]);
        $this->assertStringStartsWith('inline;', $raw->headers->get('Content-Disposition'));
        $data = $this->expectOk($this->getAs('driver', "/api/integrations/files/pod/{$pod['pod']}/photo.jpg?as=data"));
        $this->assertSame(['photo', 'image/jpeg', 1500, $photo], [$data['kind'], $data['mime'], $data['size'], $data['dataUrl']]);
        $this->expectRejected($this->getAs('disp', "/api/integrations/files/pod/{$pod['pod']}/nothing.png"), 'FILE_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('disp', '/api/integrations/files/pod'), 'FILE_NOT_FOUND', [404]);
        $this->assertSame(401, $this->getJson("/api/integrations/files/pod/{$pod['pod']}/signature.png")->getStatusCode());

        $this->assertSame('delivered', $stop->refresh()->status);
        $this->assertSame('delivered', FulfillmentOrder::find($fo->id)->status);
        $this->assertSame('delivered', SalesOrder::find($fo->so_id)->status);
        $this->assertSame([[30, 0], [12, 0]], FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        $this->assertSame([30, 12], SalesOrderLine::where('so_id', $fo->so_id)->orderBy('line_no')->pluck('delivered_qty')->all());
        $this->assertSame('onroute', Trip::find($trip->id)->status, 'one stop is still open');
        $this->assertSame(0, ReturnOrder::where('fo_id', $fo->id)->count());
        $this->assertSame(1, IntegrationEvent::where('type', 'DeliveryCompleted')->where('payload->pod', $pod['pod'])->count());

        $view = $this->expectOk($this->getAs('driver', "/api/delivery/trips/{$trip->number}"));
        $this->assertSame(['total' => 2, 'done' => 1, 'delivered' => 1, 'failed' => 0], $view['progress']);
        $this->assertSame(2, $view['nextStop']['seq']);
        $this->assertSame([$pod['pod'], 'أبو فهد', true], [$view['stops'][0]['pod']['number'], $view['stops'][0]['pod']['receiverName'], $view['stops'][0]['pod']['scanOk']]);
        $this->assertSame(['photo', 'signature'], collect($view['stops'][0]['pod']['attachments'])->pluck('kind')->sort()->values()->all());

        $pods = $this->expectOk($this->getAs('disp', '/api/delivery/pods?trip='.$trip->number.'&fo='.$fo->number));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($pods));
        $this->assertSame([1, $pod['pod'], 2], [$pods['total'], $pods['items'][0]['number'], count($pods['items'][0]['attachments'])]);
        $detail = $this->expectOk($this->getAs('disp', '/api/delivery/pods/'.$pod['pod']));
        $this->assertSame([$trip->number, $fo->number, $fo->so_id, 12.5, 'DRV-04', [$fo->number], true, 0], [$detail['trip']['number'], $detail['fo']['number'], $detail['fo']['soId'], $detail['gpsAccuracy'], $detail['driver']['code'], $detail['scans'], $detail['scanOk'], $detail['deliveredCartons']]);
        $this->expectRejected($this->getAs('disp', '/api/delivery/pods/POD-NOPE'), 'POD_NOT_FOUND', [404]);
        // the trip room carries the proof too
        $room = $this->expectOk($this->getAs('disp', "/api/transport/trips/{$trip->number}"));
        $this->assertSame(['id', 'kind', 'fileName', 'mime', 'size', 'storageKey', 'status'], array_keys($room['stops'][0]['pod']['attachments'][0]));

        // the last stop closes the trip; a closed trip accepts no further POD
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/arrive"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/deliver", $this->podBody($lastFo)));
        $this->assertSame('completed', Trip::find($trip->id)->status, 'all stops delivered → trip completed');
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/deliver", ['receiverName' => 'x']), 'TRIP_STATE', [422]);
        $this->assertSame(2, ProofOfDelivery::where('trip_id', $trip->id)->count());
    }

    public function test_pod_files_limits_object_storage_and_who_may_read_them(): void
    {
        $product = $this->product('DLV-FILE');
        [$trip, [$a, $b, $c], [$foA, $foB, $foC]] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 2]], [[$product, 2]], [[$product, 2]]]);
        foreach ([$a, $b, $c] as $stop) {
            $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        }
        $url = "/api/delivery/stops/{$a->id}/deliver";
        $png = fn (int $bytes) => 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", $bytes - 8));

        // size limits: 400 KB signature, 2 MB photo; only real images are accepted — a name or a script is not a signature
        $body = $this->expectRejected($this->postAs('driver', $url, $this->podBody($foA, ['signature' => $png(450 * 1024)])), 'ATTACHMENT_TOO_LARGE', [400]);
        $this->assertSame(400 * 1024, $body['details']['max']);
        $this->expectRejected($this->postAs('driver', $url, $this->podBody($foA, ['photo' => $this->photoImage(2 * 1024 * 1024 + 1)])), 'ATTACHMENT_TOO_LARGE', [400]);
        $this->expectRejected($this->postAs('driver', $url, $this->podBody($foA, ['signature' => 'sig.png'])), 'ATTACHMENT_INVALID', [400]);
        $this->expectRejected($this->postAs('driver', $url, $this->podBody($foA, ['photo' => 'data:text/html;base64,'.base64_encode('<script>alert(1)</script>')])), 'ATTACHMENT_INVALID', [400]);
        $this->assertSame(['arrived', 0], [$a->refresh()->status, ProofOfDelivery::where('stop_id', $a->id)->count()], 'a refused file refuses the delivery — nothing is half-saved');
        $this->assertSame('onroute', FulfillmentOrder::find($foA->id)->status);

        // with an object store configured the files go there (status `uploaded`) and are streamed back through the API
        config(['integrations.OBJECT_STORAGE_ENDPOINT' => 'https://s3.test', 'integrations.OBJECT_STORAGE_BUCKET' => 'pods', 'integrations.OBJECT_STORAGE_ACCESS_KEY' => 'AK', 'integrations.OBJECT_STORAGE_SECRET_KEY' => 'SK']);
        $signatureBytes = base64_decode(self::PNG_1PX);
        $down = false; // Http::fake stubs are appended, so one stub answers for both moods of the store
        Http::fake(['s3.test/*' => function (Request $r) use (&$down, $signatureBytes) {
            return $down ? Http::response('down', 503) : ($r->method() === 'PUT' ? Http::response('', 200) : Http::response($signatureBytes, 200, ['Content-Type' => 'image/png']));
        }]);
        $pod = $this->expectOk($this->postAs('driver', $url, $this->podBody($foA)));
        $this->assertSame(['signature' => 'uploaded', 'photo' => null], $pod['attachments']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === "https://s3.test/pods/pod/{$pod['pod']}/signature.png" && $r->body() === $signatureBytes && $r->hasHeader('Authorization'));
        $row = PodAttachment::where('storage_key', "pod/{$pod['pod']}/signature.png")->firstOrFail();
        $this->assertSame(['uploaded', 'image/png', strlen($signatureBytes)], [$row->status, $row->mime, $row->size]);
        $this->assertStringContainsString('X-Amz-Signature=', $row->url);
        $this->assertFalse(StoredFile::where('key', $row->storage_key)->exists(), 'uploaded to the object store, not duplicated in the database');
        $raw = $this->getAs('disp', "/api/integrations/files/{$row->storage_key}");
        $raw->assertOk();
        $this->assertSame($signatureBytes, $raw->getContent());

        // the object store does not answer: the proof is not lost and the delivery is not blocked — it goes to the database
        $down = true;
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$b->id}/deliver", $this->podBody($foB, ['photo' => $this->photoImage()])));
        $this->assertSame(['signature' => 'stored', 'photo' => 'stored'], $pod['attachments']);
        $this->assertTrue(StoredFile::where('key', "pod/{$pod['pod']}/photo.jpg")->exists());
        $this->assertSame($signatureBytes, $this->getAs('disp', "/api/integrations/files/pod/{$pod['pod']}/signature.png")->getContent(), 'read from where its row says it lives');

        // a driver reads the proofs of his own trips only
        [, [$other], [$otherFo]] = $this->dispatchedTrip(Driver::where('code', 'DRV-07')->firstOrFail(), [[[$product, 1]]]);
        $this->expectOk($this->postAs('admin', "/api/delivery/stops/{$other->id}/arrive"));
        $theirs = $this->expectOk($this->postAs('admin', "/api/delivery/stops/{$other->id}/deliver", $this->podBody($otherFo)));
        $this->expectRejected($this->getAs('driver', "/api/integrations/files/pod/{$theirs['pod']}/signature.png"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->getAs('driver', "/api/integrations/files/pod/{$theirs['pod']}/signature.png?as=data"), 'NOT_YOUR_TRIP', [403]);
        $this->getAs('sales', "/api/integrations/files/pod/{$theirs['pod']}/signature.png")->assertOk();
        $this->assertSame('arrived', $c->refresh()->status);
        $this->assertSame($trip->id, $c->trip_id);
    }

    public function test_delivery_needs_the_scan_of_the_right_goods_or_a_reason(): void
    {
        $product = $this->product('DLV-SCAN');
        [$trip, [$s1, $s2, $s3, $s4, $s5], [$fo1, $fo2, $fo3, $fo4, $fo5]] = $this->dispatchedTrip($this->demoDriver(), array_fill(0, 5, [[$product, 3]]));
        foreach ([$s1, $s2, $s3, $s4, $s5] as $stop) {
            $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        }
        $pkg = Package::create(['number' => self::code('PKG-T'), 'fo_id' => $fo2->id, 'cartons' => 1, 'label_ref' => "LBL-{$fo2->number}"]);
        Carton::create(['code' => "{$fo3->number}-C01", 'fo_id' => $fo3->id, 'package_id' => null, 'seq' => 1]);
        $signed = ['receiverName' => 'أبو فهد', 'signature' => $this->signatureImage()];
        $url = "/api/delivery/stops/{$s1->id}/deliver";

        // nothing scanned and no reason; a code the system does not know is not a scan either
        $this->expectRejected($this->postAs('driver', $url, $signed), 'SCAN_REQUIRED', [422]);
        $this->expectRejected($this->postAs('driver', $url, $signed + ['scans' => ['4006381333931', '  ']]), 'SCAN_REQUIRED', [422]);
        $this->expectRejected($this->postAs('driver', $url, $signed + ['scans' => 'FO-1']), 'INVALID_INPUT', [400]);

        // the goods of ANOTHER order: refused, and dispatch is told once however often the driver retries
        $wrong = $this->expectRejected($this->postAs('driver', $url, $signed + ['scans' => [$fo1->number, $fo2->number]]), 'WRONG_PACKAGE', [422]);
        $this->assertSame(['scanned' => $fo2->number, 'belongsTo' => $fo2->number, 'expected' => $fo1->number], $wrong['details']);
        $this->expectRejected($this->postAs('driver', $url, $signed + ['scans' => [$fo2->number]]), 'WRONG_PACKAGE', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s1->id}/scan", ['code' => $fo2->number]), 'WRONG_PACKAGE', [422]);
        $raised = OpsException::where('kind', 'wrong')->where('entity_number', $fo1->number)->get();
        $this->assertCount(1, $raised);
        $this->assertSame(['c', 'disp', $trip->number], [$raised[0]->severity, $raised[0]->owner_role, $raised[0]->document_number]);
        $this->assertStringContainsString($fo2->number, $raised[0]->text_ar);
        $this->assertSame(['arrived', 0], [$s1->refresh()->status, ProofOfDelivery::where('stop_id', $s1->id)->count()], 'the refusal saved nothing but the exception');

        // the scan check the app runs when a code is read, before the signature is taken
        $this->assertSame(['code' => $fo1->number, 'match' => true, 'order' => $fo1->number], $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s1->id}/scan", ['code' => " {$fo1->number} "])));
        $this->assertFalse($this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s1->id}/scan", ['code' => 'UNKNOWN-1']))['match']);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s1->id}/scan"), 'INVALID_INPUT', [400]);

        // any code of the order proves it: the order, its sales order (also as the QR link), its package / label, one of its cartons
        $pod = $this->expectOk($this->postAs('driver', $url, $signed + ['scans' => ['https://ops.test/so/'.SalesOrder::find($fo1->so_id)->number]]));
        $this->assertTrue($pod['scanOk']);
        $this->assertTrue($this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s2->id}/deliver", $signed + ['scans' => ['noise', "lbl-{$fo2->number}"]]))['scanOk']);
        $this->assertNull(ProofOfDelivery::where('stop_id', $s2->id)->value('no_scan_reason'));
        $this->assertSame($pkg->fo_id, $fo2->id);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s3->id}/deliver", $signed + ['scans' => ["{$fo3->number}-C01"], 'deliveredCartons' => 2]), 'CARTONS_EXCEED', [400]);
        $done = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s3->id}/deliver", $signed + ['scans' => ["{$fo3->number}-C01"], 'deliveredCartons' => 1]));
        $third = ProofOfDelivery::where('number', $done['pod'])->firstOrFail();
        $this->assertSame([true, ["{$fo3->number}-C01"], 1], [$third->scan_ok, $third->scans, $third->delivered_cartons]);

        // "I cannot scan" with a reason: accepted, recorded on the POD, and a warning is raised for dispatch
        $noScan = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s4->id}/deliver", $signed + ['noScanReason' => 'label_damaged', 'scans' => ['smudged']]));
        $this->assertFalse($noScan['scanOk']);
        $fourth = ProofOfDelivery::where('number', $noScan['pod'])->firstOrFail();
        $this->assertSame([false, 'label_damaged', ['smudged']], [$fourth->scan_ok, $fourth->no_scan_reason, $fourth->scans]);
        $warning = OpsException::where('kind', 'other')->where('entity_number', $fo4->number)->firstOrFail();
        $this->assertSame(['w', 'disp'], [$warning->severity, $warning->owner_role]);
        $this->assertStringContainsString('الملصق تالف', $warning->text_ar);
        $this->assertStringContainsString('"scanOk":false', AuditLog::where('action', 'POD.CREATE')->where('entity_number', $noScan['pod'])->value('new_value'));

        // policy off: no scan is asked for (and none is claimed) — but a wrong package stays a wrong package
        $this->withSetting('delivery.requireScan', false, function () use ($s5, $fo5, $fo1, $signed) {
            $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s5->id}/deliver", $signed + ['scans' => [$fo1->number]]), 'WRONG_PACKAGE', [422]);
            $free = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s5->id}/deliver", $signed));
            $this->assertNull($free['scanOk']);
            $this->assertSame(0, OpsException::where('kind', 'other')->where('entity_number', $fo5->number)->count());
        });
        $this->assertSame('completed', Trip::find($trip->id)->status);
    }

    public function test_delivery_needs_a_signature_or_photo_unless_recorded_on_behalf_with_a_reason(): void
    {
        $product = $this->product('DLV-SIG');
        [$trip, [$s1, $s2, $s3], [$fo1, $fo2, $fo3]] = $this->dispatchedTrip($this->demoDriver(), array_fill(0, 3, [[$product, 2]]));
        foreach ([$s1, $s2, $s3] as $stop) {
            $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        }
        $bare = fn (FulfillmentOrder $fo, array $extra = []) => $extra + ['receiverName' => 'م. ناصر', 'scans' => [$fo->number]];

        // the driver: a signature or a photo — one of them is enough
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s1->id}/deliver", $bare($fo1)), 'SIGNATURE_REQUIRED', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s1->id}/partial", $bare($fo1, ['deliveredQty' => 1])), 'SIGNATURE_REQUIRED', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s1->id}/deliver", $bare($fo1, ['notes' => 'العميل مستعجل'])), 'SIGNATURE_REQUIRED', [422]);
        $this->assertSame(0, ProofOfDelivery::where('stop_id', $s1->id)->count());
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s1->id}/deliver", $bare($fo1, ['photo' => $this->photoImage()])));
        $this->assertSame(['signature' => null, 'photo' => 'stored'], $pod['attachments']);

        // dispatch recording on behalf of the driver (trip.manage, not the trip's driver) has neither: a written reason instead
        $this->expectRejected($this->postAs('admin', "/api/delivery/stops/{$s2->id}/deliver", $bare($fo2)), 'SIGNATURE_REASON', [422]);
        $this->expectRejected($this->postAs('admin', "/api/delivery/stops/{$s2->id}/deliver", $bare($fo2, ['notes' => '   '])), 'SIGNATURE_REASON', [422]);
        $behalf = $this->expectOk($this->postAs('admin', "/api/delivery/stops/{$s2->id}/deliver", ['receiverName' => 'م. ناصر', 'notes' => 'هاتف السائق معطل — أكد العميل الاستلام هاتفيًا', 'noScanReason' => 'on_behalf']));
        $this->assertSame([['signature' => null, 'photo' => null], false], [$behalf['attachments'], $behalf['scanOk']]);
        $row = ProofOfDelivery::where('number', $behalf['pod'])->firstOrFail();
        $this->assertSame(['on_behalf', 'هاتف السائق معطل — أكد العميل الاستلام هاتفيًا', 0], [$row->no_scan_reason, $row->notes, PodAttachment::where('pod_id', $row->id)->count()]);
        $this->assertStringContainsString('بالنيابة', TripEvent::where('trip_id', $trip->id)->orderByDesc('at')->orderByDesc('id')->first()->text_ar);
        $this->assertStringContainsString('"onBehalf":true', AuditLog::where('action', 'POD.CREATE')->where('entity_number', $behalf['pod'])->value('new_value'));
        $this->assertSame(1, OpsException::where('kind', 'other')->where('entity_number', $fo2->number)->count(), 'no scan → dispatch is told, also when dispatch recorded it');

        // policy off: the receiver's name is enough
        $this->withSetting('delivery.requireSignature', false, function () use ($s3, $fo3, $bare) {
            $free = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$s3->id}/deliver", $bare($fo3)));
            $this->assertSame(['signature' => null, 'photo' => null], $free['attachments']);
        });
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$s3->id}/deliver", $bare($fo3)), 'TRIP_STATE', [422]);
    }

    public function test_partial_delivery_is_recorded_per_item_and_returns_exactly_what_was_not_delivered(): void
    {
        $a = $this->product('DLV-PA');
        $b = $this->product('DLV-PB');
        [$trip, [$stop, $second], [$fo, $secondFo]] = $this->dispatchedTrip($this->demoDriver(), [[[$a, 60], [$b, 40]], [[$a, 5]]]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive")); // no body, no GPS
        $url = "/api/delivery/stops/{$stop->id}/partial";
        $lines = fn (array ...$rows) => ['receiverName' => 'م. ناصر', 'lines' => array_map(fn ($r) => ['lineNo' => $r[0], 'deliveredQty' => $r[1]], $rows)];

        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'deliveredQty' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'lines' => []]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, $lines([1, -1])), 'INVALID_INPUT', [400]);
        // one figure for an order of several items says nothing about WHICH item is short
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'deliveredQty' => 80]), 'LINES_REQUIRED', [400]);
        // a partial delivery leaves at least one item short and hands over at least one unit, within each line's quantity
        $this->expectRejected($this->postAs('driver', $url, $lines([1, 60], [2, 40])), 'PARTIAL_LINES', [400]);
        $this->expectRejected($this->postAs('driver', $url, $lines([1, 0], [2, 0])), 'PARTIAL_LINES', [400]);
        $this->expectRejected($this->postAs('driver', $url, $lines([1, 61], [2, 0])), 'PARTIAL_LINES', [400]);
        $this->expectRejected($this->postAs('driver', $url, $lines([1, 60], [9, 1])), 'PARTIAL_LINES', [400]);
        $this->expectRejected($this->postAs('driver', $url, $lines([2, 10], [2, 20])), 'PARTIAL_LINES', [400]);
        $this->assertSame('arrived', $stop->refresh()->status);

        // item A in full (a line that is not listed is delivered in full), item B 20 of 40
        $pod = $this->expectOk($this->postAs('driver', $url, $this->podBody($fo, $lines([2, 20]) + ['gpsStatus' => 'denied', 'notes' => 'كرتونان ناقصان'])));
        $this->assertSame(['partial', 80, 20, 'denied'], [$pod['result'], $pod['delivered'], $pod['returned'], $pod['gps']]);
        $this->assertSame(['signature' => 'stored', 'photo' => null], $pod['attachments']);
        $this->assertStringStartsWith('RTN-', $pod['return']);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'POD_DUPLICATE', [409]);

        $rtn = ReturnOrder::with('lines')->where('number', $pod['return'])->firstOrFail();
        $this->assertSame(['approved', 'cust', 'partial', $fo->id, $trip->id, $fo->number], [$rtn->status, $rtn->type, $rtn->reason_code, $rtn->fo_id, $rtn->trip_id, $rtn->reference]);
        $this->assertSame([[$b->id, 20]], $rtn->lines->map(fn ($l) => [$l->product_id, $l->qty])->values()->all(), 'only the item that was short comes back — never a rounded share of every item');
        $this->assertSame([[60, 0], [20, 20]], FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        $this->assertSame([[60, 0], [20, 20]], SalesOrderLine::where('so_id', $fo->so_id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        // the invoice bills the driver's figures: 60 × 10 + 20 × 10
        $invoice = CustomerInvoice::where('fo_id', $fo->id)->firstOrFail();
        $this->assertSame([[$a->id, 60], [$b->id, 20]], CustomerInvoiceLine::where('invoice_id', $invoice->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->product_id, (int) $l->qty])->all());
        $this->assertEquals(800, $invoice->subtotal);
        $exception = OpsException::where('kind', 'partial')->where('entity_number', $fo->number)->get();
        $this->assertCount(1, $exception);
        $this->assertSame(['disp', $trip->number], [$exception[0]->owner_role, $exception[0]->document_number]);
        $this->assertStringContainsString($pod['return'], $exception[0]->text_ar);
        $this->assertSame('partial', FulfillmentOrder::find($fo->id)->status);
        $this->assertSame('onroute', Trip::find($trip->id)->status, 'one stop is still open');
        $this->assertSame(1, IntegrationEvent::where('type', 'PartialShipment')->where('payload->pod', $pod['pod'])->count());

        // closing the last stop closes the trip as partial
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$second->id}/arrive"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$second->id}/deliver", $this->podBody($secondFo)));
        $this->assertSame('partial', Trip::find($trip->id)->status);

        // the return is already approved (goods are on the truck): receive → inspect → restock
        $this->expectRejected($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/receive"));
        $this->expectRejected($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);
        $this->expectRejected($this->postAs('driver', "/api/returns/{$pod['return']}/inspect"), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/inspect", ['findings' => 'سليم']));
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']));
        $this->assertSame(['closed', $fo->number, $trip->number], [$closed['status'], $closed['fo']['number'], $closed['trip']['number']]);
        $this->assertSame([0, 20], [$this->inv()->availability($a->id)['total'], $this->inv()->availability($b->id)['total']]);
        $this->assertReconciles();
    }

    public function test_small_orders_are_never_rounded_into_a_wrong_invoice_or_an_empty_return(): void
    {
        $a = $this->product('DLV-R1');
        $b = $this->product('DLV-R2');
        [, [$one, $five], [$foOne, $foFive]] = $this->dispatchedTrip($this->demoDriver(), [[[$a, 1], [$b, 1]], [[$a, 5], [$b, 5]]]);
        foreach ([$one, $five] as $stop) {
            $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        }
        $body = fn (FulfillmentOrder $fo, array $rows) => $this->podBody($fo, ['lines' => array_map(fn ($r) => ['lineNo' => $r[0], 'deliveredQty' => $r[1]], $rows)]);

        // 1 + 1, one delivered: the old proportional split invoiced both and returned nothing
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$one->id}/partial", $body($foOne, [[1, 1], [2, 0]])));
        $this->assertSame([1, 1], [$pod['delivered'], $pod['returned']]);
        $this->assertSame([[1, 0], [0, 1]], FoLine::where('fo_id', $foOne->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        $this->assertSame([[$b->id, 1]], ReturnOrder::with('lines')->where('number', $pod['return'])->firstOrFail()->lines->map(fn ($l) => [$l->product_id, $l->qty])->all());
        $this->assertEquals(10, CustomerInvoice::where('fo_id', $foOne->id)->firstOrFail()->subtotal);

        // 5 + 5, five delivered as 2 + 3: recorded as counted (the old split wrote 3 + 3)
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$five->id}/partial", $body($foFive, [[1, 2], [2, 3]])));
        $this->assertSame([5, 5], [$pod['delivered'], $pod['returned']]);
        $this->assertSame([[2, 3], [3, 2]], FoLine::where('fo_id', $foFive->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        $this->assertSame([[$a->id, 3], [$b->id, 2]], ReturnOrder::with('lines')->where('number', $pod['return'])->firstOrFail()->lines->sortBy('line_no')->map(fn ($l) => [$l->product_id, $l->qty])->values()->all());
        $this->assertEquals(50, CustomerInvoice::where('fo_id', $foFive->id)->firstOrFail()->subtotal);
    }

    public function test_one_line_order_still_takes_a_single_figure_and_no_auto_return_setting_creates_no_return(): void
    {
        [, [$stop], [$fo]] = $this->dispatchedTrip($this->demoDriver(), [[[$this->product('DLV-NOR'), 10]]]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        $url = "/api/delivery/stops/{$stop->id}/partial";
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'أمين المخزن', 'deliveredQty' => 10]), 'PARTIAL_QTY', [400]);
        $pod = $this->withSetting('delivery.autoReturnOnPartial', false, fn () => $this->expectOk($this->postAs('driver', $url, $this->podBody($fo, ['receiverName' => 'أمين المخزن', 'deliveredQty' => 7]))));
        $this->assertSame([7, 3, null], [$pod['delivered'], $pod['returned'], $pod['return']]);
        $this->assertSame(0, ReturnOrder::where('fo_id', $fo->id)->count());
        $this->assertSame(1, OpsException::where('kind', 'partial')->where('entity_number', $fo->number)->count());
        $this->assertSame([7, 3], [FoLine::where('fo_id', $fo->id)->value('delivered_qty'), FoLine::where('fo_id', $fo->id)->value('returned_qty')]);
    }

    public function test_the_return_of_a_delivery_carries_the_batch_of_what_comes_back(): void
    {
        $a = $this->product('DLV-BA');
        $b = $this->product('DLV-BB');
        [, [$partial, $failed, $mostly], [$foPartial, $foFailed, $foMostly]] = $this->dispatchedTrip($this->demoDriver(), [[[$a, 10], [$b, 4]], [[$a, 10]], [[$a, 10]]]);
        // picked FEFO: the old batch first, the new one last — what comes back is taken from the last picked
        foreach ([$foPartial, $foFailed, $foMostly] as $fo) {
            $this->picked($fo, 1, [['BT-OLD', 6], ['BT-NEW', 4]]);
        }
        foreach ([$partial, $failed, $mostly] as $stop) {
            $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        }
        $linesOf = fn (string $rtn) => ReturnOrder::with('lines')->where('number', $rtn)->firstOrFail()->lines->sortBy('line_no')->map(fn ($l) => [$l->product_id, $l->qty, $l->batch_no])->values()->all();
        $old = Batch::where('product_id', $a->id)->where('batch_no', 'BT-OLD')->firstOrFail();
        $new = Batch::where('product_id', $a->id)->where('batch_no', 'BT-NEW')->firstOrFail();

        // 3 of item A come back → all from the last picked batch; item B has no pick record → its line carries no batch
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$partial->id}/partial", $this->podBody($foPartial, ['lines' => [['lineNo' => 1, 'deliveredQty' => 7], ['lineNo' => 2, 'deliveredQty' => 1]]])));
        $this->assertSame([[$a->id, 3, 'BT-NEW'], [$b->id, 3, null]], $linesOf($pod['return']));
        $this->assertSame($new->id, ReturnOrder::with('lines')->where('number', $pod['return'])->firstOrFail()->lines->firstWhere('product_id', $a->id)->batch_id);

        // a failed delivery returns everything, one line per batch (last picked first)
        $fail = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$failed->id}/fail", ['reason' => 'closed']));
        $this->assertSame([[$a->id, 4, 'BT-NEW'], [$a->id, 6, 'BT-OLD']], $linesOf($fail['return']));

        // 7 come back: the whole last batch and the rest from the one before
        $most = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$mostly->id}/partial", $this->podBody($foMostly, ['deliveredQty' => 3])));
        $this->assertSame([[$a->id, 4, 'BT-NEW'], [$a->id, 3, 'BT-OLD']], $linesOf($most['return']));

        // received at the warehouse, the goods are in the returns area under their own batches
        $this->expectOk($this->postAs('wm', "/api/returns/{$fail['return']}/receive"));
        $ret = $this->inv()->bin($this->ryd()->id, 'RET-01');
        $onHand = fn (string $batchId) => (int) InventoryBalance::where('product_id', $a->id)->where('bin_id', $ret->id)->where('batch_id', $batchId)->sum('on_hand');
        $this->assertSame([6, 4], [$onHand($old->id), $onHand($new->id)]);
        $this->assertReconciles();
    }

    public function test_a_stop_that_carries_a_warehouse_transfer_takes_no_proof_of_delivery(): void
    {
        // a transfer on a company vehicle is a trip whose stop is the destination warehouse: it is received there, on the
        // transfer, quantity by quantity — the driver reports the arrival and nothing else
        [, [$stop], [$fo]] = $this->dispatchedTrip($this->demoDriver(), [[[$this->product('DLV-TRF'), 4]]]);
        $stop->update(['transfer_id' => (string) Str::ulid()]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        $body = $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", $this->podBody($fo)), 'TRANSFER_STOP', [422]);
        $this->assertStringContainsString('المستودع المستقبِل', $body['message']);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/partial", $this->podBody($fo, ['deliveredQty' => 1])), 'TRANSFER_STOP', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed']), 'TRANSFER_STOP', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/scan", ['code' => $fo->number]), 'TRANSFER_STOP', [422]);
        $this->assertSame(['arrived', 0, 'onroute'], [$stop->refresh()->status, ProofOfDelivery::where('stop_id', $stop->id)->count(), FulfillmentOrder::find($fo->id)->status]);
        Trip::whereKey($stop->trip_id)->update(['status' => 'closed']);
    }

    public function test_failed_and_rejected_deliveries_return_everything_and_raise_a_critical_exception(): void
    {
        $product = $this->product('DLV-FAIL');
        [$trip, [$failedStop, $rejectedStop], [$failedFo, $rejectedFo]] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 15]], [[$product, 9]]]);

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'closed']), 'ARRIVE_FIRST', [422]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/arrive"));
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'lazy']), 'INVALID_INPUT', [400]);
        // nothing was handed over: no scan, no signature is asked for
        $failed = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'closed', 'notes' => 'المحل مغلق']));
        $this->assertSame(['failed', 0, 15, 'unavailable', null], [$failed['result'], $failed['delivered'], $failed['returned'], $failed['gps'], $failed['scanOk']]);
        $rtn = ReturnOrder::with('lines')->where('number', $failed['return'])->firstOrFail();
        $this->assertSame(['approved', 'del', 'del_fail', $failedFo->id, 15], [$rtn->status, $rtn->type, $rtn->reason_code, $rtn->fo_id, $rtn->lines[0]->qty]);
        $exception = OpsException::where('kind', 'faildel')->where('entity_number', $failedFo->number)->firstOrFail();
        $this->assertSame(['c', 'disp'], [$exception->severity, $exception->owner_role]);
        $this->assertStringContainsString('العميل مغلق', $exception->text_ar);
        $this->assertSame(['failed', 'closed'], [$failedStop->refresh()->status, $failedStop->fail_reason]);
        $this->assertSame('failed', FulfillmentOrder::find($failedFo->id)->status);
        $this->assertSame('failed', SalesOrder::find($failedFo->so_id)->status);
        $pod = ProofOfDelivery::where('number', $failed['pod'])->firstOrFail();
        $this->assertSame([null, 'closed', 'failed', null], [$pod->receiver_name, $pod->fail_reason, $pod->result, $pod->scan_ok]);
        $this->assertSame(1, IntegrationEvent::where('type', 'DeliveryFailed')->where('payload->pod', $failed['pod'])->count());

        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$rejectedStop->id}/arrive"));
        $rejected = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$rejectedStop->id}/fail", ['reason' => 'rejected']));
        $this->assertSame(['rejected', 0, 9], [$rejected['result'], $rejected['delivered'], $rejected['returned']]);
        $rtn = ReturnOrder::where('number', $rejected['return'])->firstOrFail();
        $this->assertSame(['approved', 'cust', 'cust_reject', $rejectedFo->id], [$rtn->status, $rtn->type, $rtn->reason_code, $rtn->fo_id]);
        $this->assertSame('rejected', TripStop::find($rejectedStop->id)->status);
        $this->assertSame('failed', FulfillmentOrder::find($rejectedFo->id)->status);
        $this->assertSame('partial', Trip::find($trip->id)->status, 'every stop closed, none delivered clean → partial');

        $view = $this->expectOk($this->getAs('driver', "/api/delivery/trips/{$trip->number}"));
        $this->assertSame(['total' => 2, 'done' => 2, 'delivered' => 0, 'failed' => 2], $view['progress']);

        // the failed goods come back: receive → inspect → damaged
        foreach (['receive', 'inspect'] as $step) {
            $this->expectOk($this->postAs('wm', "/api/returns/{$failed['return']}/{$step}"));
        }
        $this->expectOk($this->postAs('wm', "/api/returns/{$failed['return']}/decide", ['decision' => 'dmg']));
        $this->assertSame(15, $this->onHand($product, 'DMG-01'));
        $this->assertReconciles();
    }
}
