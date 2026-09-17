<?php

namespace Tests\Feature\Flows;

use App\Models\AuditLog;
use App\Models\DeliveryRecord;
use App\Models\Driver;
use App\Models\FoLine;
use App\Models\FulfillmentOrder;
use App\Models\IntegrationEvent;
use App\Models\OpsException;
use App\Models\PodAttachment;
use App\Models\ProofOfDelivery;
use App\Models\ReturnOrder;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Trip;
use App\Models\TripEvent;
use App\Models\TripStop;
use App\Services\Core\SettingsService;
use Tests\ApiTestCase;

/** Driver app: own trips only, arrive before POD, one POD per stop, partial / failed deliveries come back as approved returns. */
class DeliveryFlowTest extends ApiTestCase
{
    use FlowFixtures;

    public function test_driver_sees_and_touches_only_own_trips(): void
    {
        $product = $this->product('DLV-OWN');
        [$mine] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 10]]]);
        [$theirs, $theirStops] = $this->dispatchedTrip(Driver::where('code', 'DRV-07')->firstOrFail(), [[[$product, 10]]]);

        $view = $this->expectOk($this->getAs('driver', '/api/delivery/my-trips'));
        $this->assertSame('integration_pending', $view['gps']['status']);
        $numbers = array_column(array_merge([$view['current']], $view['upcoming']), 'number');
        $this->assertContains($mine->number, $numbers);
        $this->assertNotContains($theirs->number, $numbers, 'another driver\'s trip is never listed');

        $trip = $this->expectOk($this->getAs('driver', "/api/delivery/trips/{$mine->number}"));
        $this->assertSame(['total' => 1, 'done' => 0, 'delivered' => 0, 'failed' => 0], $trip['progress']);
        $this->assertSame(['status' => 'integration_pending'], $trip['eta']);
        $this->assertSame(1, $trip['nextStop']['seq']);
        $this->assertSame($trip['stops'][0]['fo']['number'], $trip['nextStop']['fo']);
        $this->assertSame($product->sku, $trip['stops'][0]['fo']['lines'][0]['product']['sku']);
        $this->assertSame('DRV-04', $trip['driver']['code']);
        $this->assertNull($trip['stops'][0]['pod']);

        $stop = $theirStops[0];
        $this->expectRejected($this->getAs('driver', "/api/delivery/trips/{$theirs->number}"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$theirs->number}/start"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'NOT_YOUR_TRIP', [403]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed']), 'NOT_YOUR_TRIP', [403]);
        $this->assertSame('pending', $stop->refresh()->status);
        $this->assertSame(0, TripEvent::where('trip_id', $theirs->id)->count());
        $this->assertSame(0, ProofOfDelivery::where('trip_id', $theirs->id)->count());

        // dispatcher: may look at any driver's trips, may not execute deliveries; a user without a driver record has no trips
        $asDisp = $this->expectOk($this->getAs('disp', '/api/delivery/my-trips?driver=DRV-07'));
        $this->assertContains($theirs->number, array_column(array_merge([$asDisp['current']], $asDisp['upcoming']), 'number'));
        $this->assertSame($theirs->number, $this->expectOk($this->getAs('disp', "/api/delivery/trips/{$theirs->id}"))['number']);
        $this->expectRejected($this->postAs('disp', "/api/delivery/trips/{$theirs->number}/start"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('sales', "/api/delivery/stops/{$stop->id}/arrive"), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', '/api/delivery/my-trips'), 'NOT_A_DRIVER', [403]);
        $this->expectRejected($this->getAs('driver', '/api/delivery/trips/TRP-NOPE'), 'TRIP_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('driver', '/api/delivery/stops/nope/arrive'), 'STOP_NOT_FOUND', [404]);
    }

    public function test_start_arrive_deliver_with_one_pod_per_stop(): void
    {
        $product = $this->product('DLV-OK');
        [$notDispatched] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 5]]], 'loading');
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$notDispatched->number}/start"), 'TRIP_NOT_DISPATCHED', [422]);
        $this->expectRejected($this->postAs('driver', '/api/delivery/stops/'.$notDispatched->stops()->first()->id.'/arrive'), 'TRIP_STATE', [422]);

        [$trip, [$stop, $lastStop], [$fo]] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 30], [$this->product('DLV-OK2'), 12]], [[$product, 1]]]);
        $this->assertSame(['ok' => true], $this->expectOk($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start")));
        $this->expectRejected($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start"), 'TRIP_STARTED', [409]);
        $this->assertSame(1, AuditLog::where('action', 'TRIP.START')->where('entity_number', $trip->number)->count());

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'أبو فهد']), 'ARRIVE_FIRST', [422]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive", ['gps' => ['lat' => 'north']]), 'INVALID_INPUT', [400]);
        $this->assertSame(['ok' => true, 'stop' => 1], $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive", ['gps' => ['lat' => 24.7, 'lng' => 46.7]])));
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"), 'STOP_STATE', [409]);
        $this->assertStringContainsString('GPS 24.7,46.7', TripEvent::where('trip_id', $trip->id)->orderByDesc('id')->first()->text_ar);

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => '']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver"), 'INVALID_INPUT', [400]);
        $signature = 'data:image/png;base64,'.str_repeat('iVBORw0KGgo', 30000); // ~330 KB, well under the 8 MB body limit
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => ' أبو فهد ', 'signature' => $signature, 'photo' => 'door.jpg', 'gps' => ['lat' => 24.7, 'lng' => 46.7, 'accuracy' => 12.5]]));
        $this->assertStringStartsWith('POD-', $pod['pod']);
        $this->assertSame(['delivered', 42, 0, null, 'captured'], [$pod['result'], $pod['delivered'], $pod['returned'], $pod['return'], $pod['gps']]);
        $this->assertSame(['signature' => 'integration_pending', 'photo' => 'integration_pending'], $pod['attachments']);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'POD_DUPLICATE', [409]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed']), 'POD_DUPLICATE', [409]);
        $this->assertSame(1, ProofOfDelivery::where('stop_id', $stop->id)->count());
        $this->assertSame(1, DeliveryRecord::where('stop_id', $stop->id)->count());

        // attachments are references with an honest status — nothing was uploaded anywhere
        $files = PodAttachment::whereHas('pod', fn ($q) => $q->where('number', $pod['pod']))->orderBy('kind')->get();
        $this->assertSame([['photo', 'door.jpg', 'integration_pending'], ['signature', "signature-{$pod['pod']}.png", 'integration_pending']], $files->map(fn ($a) => [$a->kind, $a->file_name, $a->status])->all());
        $this->assertTrue($files->every(fn ($a) => $a->url === null && $a->storage_key === null));

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
        $this->assertSame([$pod['pod'], 'أبو فهد'], [$view['stops'][0]['pod']['number'], $view['stops'][0]['pod']['receiverName']]);

        $pods = $this->expectOk($this->getAs('disp', '/api/delivery/pods?trip='.$trip->number.'&fo='.$fo->number));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($pods));
        $this->assertSame([1, $pod['pod'], 2], [$pods['total'], $pods['items'][0]['number'], count($pods['items'][0]['attachments'])]);
        $detail = $this->expectOk($this->getAs('disp', '/api/delivery/pods/'.$pod['pod']));
        $this->assertSame([$trip->number, $fo->number, $fo->so_id, 12.5, 'DRV-04'], [$detail['trip']['number'], $detail['fo']['number'], $detail['fo']['soId'], $detail['gpsAccuracy'], $detail['driver']['code']]);
        $this->expectRejected($this->getAs('disp', '/api/delivery/pods/POD-NOPE'), 'POD_NOT_FOUND', [404]);

        // the last stop closes the trip; a closed trip accepts no further POD
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/arrive"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/deliver", ['receiverName' => 'أبو فهد']));
        $this->assertSame('completed', Trip::find($trip->id)->status, 'all stops delivered → trip completed');
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$lastStop->id}/deliver", ['receiverName' => 'x']), 'TRIP_STATE', [422]);
        $this->assertSame(2, ProofOfDelivery::where('trip_id', $trip->id)->count());
    }

    public function test_partial_delivery_creates_an_approved_return_that_flows_back_into_stock(): void
    {
        $a = $this->product('DLV-PA');
        $b = $this->product('DLV-PB');
        [$trip, [$stop, $second], [$fo]] = $this->dispatchedTrip($this->demoDriver(), [[[$a, 60], [$b, 40]], [[$a, 5]]]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive")); // no body, no GPS
        $url = "/api/delivery/stops/{$stop->id}/partial";

        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'deliveredQty' => 0]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'deliveredQty' => 100]), 'PARTIAL_QTY', [400]);
        $pod = $this->expectOk($this->postAs('driver', $url, ['receiverName' => 'م. ناصر', 'deliveredQty' => 80, 'gpsStatus' => 'denied', 'notes' => 'كرتونان ناقصان']));
        $this->assertSame(['partial', 80, 20, 'denied'], [$pod['result'], $pod['delivered'], $pod['returned'], $pod['gps']]);
        $this->assertSame(['signature' => null, 'photo' => null], $pod['attachments']);
        $this->assertStringStartsWith('RTN-', $pod['return']);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$stop->id}/deliver", ['receiverName' => 'x']), 'POD_DUPLICATE', [409]);

        $rtn = ReturnOrder::with('lines')->where('number', $pod['return'])->firstOrFail();
        $this->assertSame(['approved', 'cust', 'partial', $fo->id, $trip->id, $fo->number], [$rtn->status, $rtn->type, $rtn->reason_code, $rtn->fo_id, $rtn->trip_id, $rtn->reference]);
        $this->assertSame([[$a->id, 12], [$b->id, 8]], $rtn->lines->sortBy('line_no')->map(fn ($l) => [$l->product_id, $l->qty])->values()->all());
        $this->assertSame([[48, 12], [32, 8]], FoLine::where('fo_id', $fo->id)->orderBy('line_no')->get()->map(fn ($l) => [$l->delivered_qty, $l->returned_qty])->all());
        $exception = OpsException::where('kind', 'partial')->where('entity_number', $fo->number)->get();
        $this->assertCount(1, $exception);
        $this->assertSame(['disp', $trip->number], [$exception[0]->owner_role, $exception[0]->document_number]);
        $this->assertStringContainsString($pod['return'], $exception[0]->text_ar);
        $this->assertSame('partial', FulfillmentOrder::find($fo->id)->status);
        $this->assertSame('onroute', Trip::find($trip->id)->status, 'one stop is still open');
        $this->assertSame(1, IntegrationEvent::where('type', 'PartialShipment')->where('payload->pod', $pod['pod'])->count());

        // closing the last stop closes the trip as partial
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$second->id}/arrive"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$second->id}/deliver", ['receiverName' => 'المستودع']));
        $this->assertSame('partial', Trip::find($trip->id)->status);

        // the return is already approved (goods are on the truck): receive → inspect → restock
        $this->expectRejected($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/receive"));
        $this->expectRejected($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']), 'RTN_DECISION_STATE', [422]);
        $this->expectRejected($this->postAs('driver', "/api/returns/{$pod['return']}/inspect"), 'FORBIDDEN', [403]);
        $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/inspect", ['findings' => 'سليم']));
        $closed = $this->expectOk($this->postAs('wm', "/api/returns/{$pod['return']}/decide", ['decision' => 'restock']));
        $this->assertSame(['closed', $fo->number, $trip->number], [$closed['status'], $closed['fo']['number'], $closed['trip']['number']]);
        $this->assertSame([12, 8], [$this->inv()->availability($a->id)['total'], $this->inv()->availability($b->id)['total']]);
        $this->assertReconciles();
    }

    public function test_partial_delivery_without_auto_return_setting_creates_no_return(): void
    {
        [, [$stop], [$fo]] = $this->dispatchedTrip($this->demoDriver(), [[[$this->product('DLV-NOR'), 10]]]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        $settings = $this->app->make(SettingsService::class);
        $settings->set('delivery.autoReturnOnPartial', false);
        try {
            $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/partial", ['receiverName' => 'أمين المخزن', 'deliveredQty' => 7]));
        } finally {
            $settings->set('delivery.autoReturnOnPartial', true);
        }
        $this->assertSame([7, 3, null], [$pod['delivered'], $pod['returned'], $pod['return']]);
        $this->assertSame(0, ReturnOrder::where('fo_id', $fo->id)->count());
        $this->assertSame(1, OpsException::where('kind', 'partial')->where('entity_number', $fo->number)->count());
    }

    public function test_failed_and_rejected_deliveries_return_everything_and_raise_a_critical_exception(): void
    {
        $product = $this->product('DLV-FAIL');
        [$trip, [$failedStop, $rejectedStop], [$failedFo, $rejectedFo]] = $this->dispatchedTrip($this->demoDriver(), [[[$product, 15]], [[$product, 9]]]);

        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'closed']), 'ARRIVE_FIRST', [422]);
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/arrive"));
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail"), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'lazy']), 'INVALID_INPUT', [400]);
        $failed = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$failedStop->id}/fail", ['reason' => 'closed', 'notes' => 'المحل مغلق']));
        $this->assertSame(['failed', 0, 15, 'unavailable'], [$failed['result'], $failed['delivered'], $failed['returned'], $failed['gps']]);
        $rtn = ReturnOrder::with('lines')->where('number', $failed['return'])->firstOrFail();
        $this->assertSame(['approved', 'del', 'del_fail', $failedFo->id, 15], [$rtn->status, $rtn->type, $rtn->reason_code, $rtn->fo_id, $rtn->lines[0]->qty]);
        $exception = OpsException::where('kind', 'faildel')->where('entity_number', $failedFo->number)->firstOrFail();
        $this->assertSame(['c', 'disp'], [$exception->severity, $exception->owner_role]);
        $this->assertStringContainsString('العميل مغلق', $exception->text_ar);
        $this->assertSame(['failed', 'closed'], [$failedStop->refresh()->status, $failedStop->fail_reason]);
        $this->assertSame('failed', FulfillmentOrder::find($failedFo->id)->status);
        $this->assertSame('failed', SalesOrder::find($failedFo->so_id)->status);
        $pod = ProofOfDelivery::where('number', $failed['pod'])->firstOrFail();
        $this->assertSame([null, 'closed', 'failed'], [$pod->receiver_name, $pod->fail_reason, $pod->result]);
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
