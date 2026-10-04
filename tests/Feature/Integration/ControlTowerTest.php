<?php

namespace Tests\Feature\Integration;

use App\Integration\Models\IntDelivery;
use App\Integration\Models\IntException;
use App\Integration\Models\IntInbox;
use App\Integration\Services\EventPublisher;
use App\Integration\Services\ReconciliationService;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\SalesOrder;
use App\Models\TripStop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\ApiTestCase;
use Tests\Feature\Sales\SalesFixtures;

/**
 * Phase 8 + 10 — the Control Tower API (overview, queues, payload, trace, retry / replay / resolve), reconciliation
 * against the source system, and the return flow reported back to Sales.
 */
class ControlTowerTest extends ApiTestCase
{
    use IntegrationTestHelpers;
    use SalesFixtures;

    /** Envelopes OPS delivered to Sales. */
    private array $sent = [];

    /** What the fake Sales answers on its reconciliation endpoint: id → row. */
    private array $salesView = [];

    private int $reply = 202;

    private static ?array $world = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSystems(['reconcile_url' => 'https://sales.example.test/api/integration/orders']);
        Cache::forget('int:breaker:sales');
        Cache::forget(ReconciliationService::LAST_KEY);
        $this->sent = [];
        $this->reply = 202;
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/api/integration/orders')) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                $ids = explode(',', (string) ($q['ids'] ?? ''));

                return Http::response(['orders' => array_values(array_intersect_key($this->salesView, array_flip($ids)))]);
            }
            if ($this->reply === 202) {
                $this->sent[] = json_decode($r->body(), true);
            }

            return Http::response(['results' => []], $this->reply);
        });
        self::$world ??= $this->world();
    }

    private function world(): array
    {
        $cust = 'C'.self::uid();
        $this->send($this->envelope('customer.created', $cust, ['id' => $cust, 'name' => 'مطاعم البرج'], 1));
        $ext = 'P-T'.self::uid();
        $this->send($this->envelope('product.created', $ext, ['id' => $ext, 'name' => 'منتج البرج'], 1));
        $p = $this->makeProduct();
        $this->expectOk($this->postAs('admin', '/api/integration/mappings/product', ['externalId' => $ext, 'internal' => $p->sku]));
        $this->stock($p, 'A-01-1-B1', 100);

        return ['customer' => $cust, 'ext' => $ext, 'product' => $p];
    }

    private function order(string $ref, int $qty): SalesOrder
    {
        $r = $this->send($this->envelope('sales_order.confirmed', $ref, ['id' => $ref, 'customerId' => self::$world['customer'],
            'lines' => [['productId' => self::$world['ext'], 'qty' => $qty, 'unitPrice' => 10]]], 1))->json('results.0');
        $this->assertSame('processed', $r['status'], json_encode($r, JSON_UNESCAPED_UNICODE));

        return SalesOrder::where('external_ref', $ref)->firstOrFail();
    }

    public function test_the_tower_shows_health_queues_payloads_and_the_trace_of_an_order(): void
    {
        $ref = 'ORD-T'.self::uid();
        $so = $this->order($ref, 3);

        $ov = $this->expectOk($this->getAs('admin', '/api/integration/overview'));
        $sales = collect($ov['systems'])->firstWhere('code', 'sales');
        $this->assertSame([true, 'ok', false], [$sales['enabled'], $sales['health'], $sales['breaker']['open']]);
        $this->assertGreaterThanOrEqual(1, $ov['inbox']['last24h']['processed']);
        $this->assertGreaterThanOrEqual(1, $ov['integratedOrders']['open']);
        $this->assertArrayNotHasKey('keys_secret', $sales, 'secrets never leave the server');
        $this->assertStringNotContainsString(self::SALES_SECRET, json_encode($ov));

        // trace by the Sales order id or by the OPS number: same journey, in time order
        foreach ([$ref, $so->number] as $key) {
            $tr = $this->expectOk($this->getAs('admin', "/api/integration/trace/{$key}"));
            $this->assertSame([$ref, $so->number], [$tr['correlationId'], $tr['order']['number']]);
            $titles = array_column($tr['timeline'], 'title');
            $this->assertSame('sales_order.confirmed', $titles[0]);
            $this->assertContains('order.accepted', $titles);
            $this->assertContains('order.reserved', $titles);
            $this->assertSame('SalesOrder', $tr['documents'][0]['type']);
        }
        $this->expectRejected($this->getAs('admin', '/api/integration/trace/ORD-NOPE'), 'TRACE_NOT_FOUND', [404]);

        // lists + payload inspection
        $in = $this->expectOk($this->getAs('admin', "/api/integration/inbox?q={$ref}"));
        $this->assertSame(['sales_order.confirmed', 'processed'], [$in['items'][0]['type'], $in['items'][0]['status']]);
        $pl = $this->expectOk($this->getAs('admin', "/api/integration/events/{$in['items'][0]['id']}"));
        $this->assertSame(['in', $ref, 3], [$pl['direction'], $pl['envelope']['subject'], $pl['envelope']['data']['lines'][0]['qty']]);
        $out = $this->expectOk($this->getAs('admin', "/api/integration/deliveries?q={$ref}"));
        $this->assertSame(2, $out['total']);
        $this->assertSame('out', $this->expectOk($this->getAs('admin', "/api/integration/events/{$out['items'][0]['id']}"))['direction']);

        // RBAC: view for gm, nothing for sales, manage only for super
        $this->expectOk($this->getAs('gm', '/api/integration/overview'));
        $this->expectRejected($this->getAs('sales', '/api/integration/overview'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->postAs('gm', '/api/integration/run'), 'FORBIDDEN', [403]);
        $this->assertTrue($this->expectOk($this->postAs('admin', '/api/integration/run'))['ran']);
    }

    public function test_dead_deliveries_and_exceptions_are_handled_from_the_tower(): void
    {
        config(['integration.backoff' => []]); // first failure is final: straight to the dead-letter queue
        $this->reply = 503;
        $e = app(EventPublisher::class)->publish('order.packed', 'ORD-D'.self::uid(), ['opsOrder' => 'SO-X']);
        $d = IntDelivery::where('event_id', $e->id)->firstOrFail();
        app(\App\Integration\Services\Dispatcher::class)->attempt($d);
        $this->assertSame('dead', $d->refresh()->status);
        $dead = $this->expectOk($this->getAs('admin', '/api/integration/deliveries?status=dead'));
        $this->assertContains($d->id, array_column($dead['items'], 'id'));
        $this->assertSame('degraded', collect($this->expectOk($this->getAs('admin', '/api/integration/overview'))['systems'])->firstWhere('code', 'sales')['health']);

        // receiver back → retried by a person, audited
        $this->reply = 202;
        Cache::forget('int:breaker:sales');
        $this->expectRejected($this->postAs('gm', "/api/integration/deliveries/{$d->id}/retry"), 'FORBIDDEN', [403]);
        $this->assertSame('sent', $this->expectOk($this->postAs('admin', "/api/integration/deliveries/{$d->id}/retry"))['status']);
        $this->assertTrue(AuditLog::where('action', 'INTEGRATION.DELIVERY_RETRY')->where('entity_id', $d->id)->where('username', 'admin')->exists());

        // the DELIVERY_DEAD exception is closed by a person with a note (audited); closing twice is refused
        $x = IntException::where('code', 'DELIVERY_DEAD')->where('entity_ref', $e->id)->firstOrFail();
        $list = $this->expectOk($this->getAs('admin', '/api/integration/exceptions?status=open&code=DELIVERY_DEAD'));
        $this->assertContains($x->id, array_column($list['items'], 'id'));
        $this->expectRejected($this->postAs('admin', "/api/integration/exceptions/{$x->id}/resolve", ['status' => 'resolved']), 'INVALID_INPUT', [400]);
        $this->assertSame('resolved', $this->expectOk($this->postAs('admin', "/api/integration/exceptions/{$x->id}/resolve", ['status' => 'resolved', 'note' => 'أُعيد الإرسال بعد عودة المستقبل']))['status']);
        $this->expectRejected($this->postAs('admin', "/api/integration/exceptions/{$x->id}/resolve", ['status' => 'ignored', 'note' => 'again']), 'INT_EXCEPTION_CLOSED', [422]);

        // a rejected inbound event can be replayed from the tower (still rejected: its content did not change)
        $bad = $this->envelope('sales_order.confirmed', 'ORD-R'.self::uid(), ['id' => 'other-id', 'lines' => []], 1);
        $this->send($bad);
        $row = IntInbox::where('event_id', $bad['id'])->firstOrFail();
        $this->assertSame('rejected', $this->expectOk($this->postAs('admin', "/api/integration/inbox/{$row->id}/replay"))['status']);
    }

    public function test_reconciliation_flags_disagreements_without_changing_either_side(): void
    {
        $a = $this->order('ORD-RA'.self::uid(), 1); // Sales says delivered, OPS still allocated
        $b = $this->order('ORD-RB'.self::uid(), 1); // both agree
        $c = $this->order('ORD-RC'.self::uid(), 1); // Sales does not know it
        $d = $this->order('ORD-RD'.self::uid(), 1); // Sales rejected it, OPS still open
        $this->salesView = [
            $a->external_ref => ['id' => $a->external_ref, 'st' => 'done', 'opsRef' => $a->number],
            $b->external_ref => ['id' => $b->external_ref, 'st' => 'b2b', 'opsRef' => $b->number],
            $d->external_ref => ['id' => $d->external_ref, 'st' => 'rej', 'opsRef' => $d->number],
        ];
        $r = $this->expectOk($this->postAs('admin', '/api/integration/reconcile'));
        $this->assertGreaterThanOrEqual(4, $r['systems']['sales']['compared']);
        $this->assertNull($r['systems']['sales']['error']);
        $open = fn (string $code, SalesOrder $so) => IntException::where('code', $code)->where('entity_ref', $so->external_ref)->where('status', 'open')->exists();
        $this->assertTrue($open('RECON_MISMATCH', $a));
        $this->assertFalse($open('RECON_MISMATCH', $b));
        $this->assertTrue($open('RECON_MISSING', $c));
        $this->assertTrue($open('RECON_MISMATCH', $d));
        // nothing was "fixed" behind anyone's back
        $this->assertSame(['allocated', 'allocated', 'allocated'], [$a->refresh()->status, $c->refresh()->status, $d->refresh()->status]);

        // Sales corrected its side → the next run closes the mismatch by itself; a run inside the interval is skipped
        $this->salesView[$a->external_ref]['st'] = 'b2b';
        $this->assertNull(app(ReconciliationService::class)->runIfDue(), 'not due yet');
        $this->expectOk($this->postAs('admin', '/api/integration/reconcile'));
        $this->assertFalse($open('RECON_MISMATCH', $a));
        $this->assertTrue($open('RECON_MISMATCH', $d));
        $this->assertNotNull($this->expectOk($this->getAs('admin', '/api/integration/overview'))['lastReconciliation']);
    }

    public function test_without_a_scheduler_a_cycle_runs_after_relevant_requests_at_most_once_per_window(): void
    {
        Cache::forget('int:opportunistic');
        Cache::forget(\App\Integration\Services\IntegrationRunner::LAST_KEY);
        $last = fn () => Cache::get(\App\Integration\Services\IntegrationRunner::LAST_KEY);

        // off (the default in tests): nothing runs by itself
        $this->signed('GET', '/api/v1/health')->assertOk();
        $this->assertNull($last());

        config(['integration.opportunistic_seconds' => 120]);
        $this->getAs('admin', '/api/inventory/balances?pageSize=1')->assertOk(); // a plain read never triggers it
        $this->assertNull($last());
        $this->signed('GET', '/api/v1/health')->assertOk();                      // a call from another system does
        $first = $last();
        $this->assertSame('opportunistic', $first['trigger']);
        $this->signed('GET', '/api/v1/health')->assertOk();                      // inside the window: not again
        $this->assertSame($first['at'], $last()['at']);
        $this->travel(121)->seconds();
        Cache::forget('int:opportunistic');                                      // the array cache does not follow the test clock
        $this->signed('GET', '/api/v1/health')->assertOk();
        $this->assertNotSame($first['at'], $last()['at']);
    }

    public function test_a_partial_delivery_and_its_return_are_reported_to_sales(): void
    {
        $ref = 'ORD-P'.self::uid();
        $so = $this->order($ref, 10);
        $fo = $this->packedOrder($so->number);
        $trip = $this->makeTrip([$fo], $this->makeVehicle(), Driver::where('code', 'DRV-04')->firstOrFail());
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]));
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"));
        $stop = TripStop::where('trip_id', $trip->id)->firstOrFail();
        $this->expectOk($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        $pod = $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/partial", ['receiverName' => 'أمين المستودع', 'deliveredQty' => 7]));
        $rtn = $pod['return'];
        $mine = fn () => array_values(array_filter($this->sent, fn ($e) => $e['subject'] === $ref));
        $types = array_column($mine(), 'type');
        $this->assertContains('return.created', $types);
        $partial = collect($mine())->firstWhere('type', 'delivery.partial');
        $this->assertSame(['delivered_partial', 7, 3, $rtn], [$partial['data']['status'], $partial['data']['deliveredQty'], $partial['data']['returnedQty'], $partial['data']['return']]);

        // the warehouse receives, inspects and restocks the returned goods: each step reaches Sales
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/receive"));
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/inspect", ['findings' => 'سليم']));
        $this->expectOk($this->postAs('wm', "/api/returns/{$rtn}/decide", ['decision' => 'restock']));
        $types = array_column($mine(), 'type');
        $this->assertSame(['return.received', 'return.inspect', 'return.closed'], array_slice($types, -3));
        $closed = collect($mine())->last();
        $this->assertSame([$rtn, 'restock'], [$closed['data']['return'], $closed['data']['decision']]);
        $this->assertSame(range(1, count($types)), array_column($mine(), 'sequence'));

        // the trace shows the return among the order's documents
        $tr = $this->expectOk($this->getAs('admin', "/api/integration/trace/{$ref}"));
        $this->assertContains($rtn, array_column($tr['documents'], 'number'));
        $this->assertReconciles();
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$trip->number}/close"));
    }

    public function test_a_failed_delivery_reaches_sales_with_its_reason(): void
    {
        $ref = 'ORD-F'.self::uid();
        $so = $this->order($ref, 4);
        $fo = $this->packedOrder($so->number);
        $trip = $this->makeTrip([$fo], $this->makeVehicle(), Driver::where('code', 'DRV-04')->firstOrFail());
        $this->expectOk($this->postAs('worker', "/api/fulfillment/trips/{$trip->number}/load", ['foNumber' => $fo]));
        $this->expectOk($this->postAs('disp', "/api/fulfillment/trips/{$trip->number}/dispatch"));
        $stop = TripStop::where('trip_id', $trip->id)->firstOrFail();
        $this->expectOk($this->postAs('driver', "/api/delivery/trips/{$trip->number}/start"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/arrive"));
        $this->expectOk($this->postAs('driver', "/api/delivery/stops/{$stop->id}/fail", ['reason' => 'closed', 'notes' => 'المطعم مغلق']));
        $failed = collect($this->sent)->last(fn ($e) => $e['subject'] === $ref && $e['type'] === 'delivery.failed');
        $this->assertSame(['delivery_failed', 'closed', 0, 4], [$failed['data']['status'], $failed['data']['reason'], $failed['data']['deliveredQty'], $failed['data']['returnedQty']]);
        $this->assertNotEmpty($failed['data']['reasonAr']);
        $this->assertMatchesRegularExpression('/^RTN-/', (string) $failed['data']['return'], 'the goods come back through a return');
        $this->assertSame('failed', $so->refresh()->status);
        $this->expectOk($this->postAs('wm', "/api/returns/{$failed['data']['return']}/receive"));
        $this->expectOk($this->postAs('disp', "/api/transport/trips/{$trip->number}/close"));
    }
}
