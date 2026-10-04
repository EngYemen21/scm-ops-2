<?php

namespace Tests\Feature\Integration;

use App\Integration\Handlers\Blocked;
use App\Integration\Handlers\EventHandler;
use App\Integration\Models\IntDelivery;
use App\Integration\Models\IntException;
use App\Integration\Models\IntInbox;
use App\Integration\Services\Dispatcher;
use App\Integration\Services\EventPublisher;
use App\Integration\Services\InboxService;
use App\Integration\Support\Signature;
use App\Models\AuditLog;
use App\Support\AuthUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\ApiTestCase;

/** Fails the first `$failures` times, then succeeds — a consumer that recovers. */
final class FlakyHandler implements EventHandler
{
    public static int $failures = 0;

    public static int $calls = 0;

    public function handle(\App\Integration\Models\IntInbox $event, AuthUser $actor): array
    {
        self::$calls++;
        if (self::$failures-- > 0) {
            throw new RuntimeException('downstream not ready');
        }

        return ['ok' => true];
    }
}

/** Blocked until $open is true — an event waiting for a mapping. */
final class GateHandler implements EventHandler
{
    public static bool $open = false;

    public function handle(\App\Integration\Models\IntInbox $event, AuthUser $actor): array
    {
        if (! self::$open) {
            throw new Blocked('PRODUCT_UNMAPPED', 'product P-TEST is not mapped', 'product', 'P-TEST');
        }

        return ['applied' => $event->subject];
    }
}

/**
 * The integration layer's core, independent of any business handler: signed gateway, inbox (dedupe, ordering, retry,
 * dead letter, parking), outbox deliveries (signature, back-off, dead letter, circuit breaker) and the run cycle.
 */
class IntegrationCoreTest extends ApiTestCase
{
    use IntegrationTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSystems();
        config([
            'integration.systems.sales.emits' => ['integration.ping', 'test.flaky', 'test.gate'],
            'integration.handlers.test_flaky' => FlakyHandler::class,
            'integration.handlers.test_gate' => GateHandler::class,
            'integration.backoff' => [30, 60],
        ]);
        Cache::forget('int:breaker:sales');
    }

    public function test_the_gateway_only_accepts_correctly_signed_system_calls(): void
    {
        // a person's session token is not a system signature
        $this->getJson('/api/v1/health', ['Authorization' => 'Bearer '.$this->token('admin')])->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_MISSING');

        $ok = $this->signed('GET', '/api/v1/health')->assertOk();
        $this->assertSame(['ops', 'sales', 'k1'], [$ok->json('system'), $ok->json('caller.system'), $ok->json('caller.keyId')]);
        $this->signed('GET', '/api/v1/health', keyId: 'k2', secret: 'rotated-secret-for-tests')->assertOk(); // rotation: both keys valid

        $this->signed('GET', '/api/v1/health', secret: 'wrong')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        $this->signed('GET', '/api/v1/health', keyId: 'k9')->assertStatus(401)->assertJsonPath('code', 'KEY_UNKNOWN');
        $this->signed('GET', '/api/v1/health', ts: now()->subMinutes(10)->getTimestamp())->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_EXPIRED');
        $this->signed('GET', '/api/v1/health', system: 'erp')->assertStatus(401)->assertJsonPath('code', 'SYSTEM_UNKNOWN');
        // signed body ≠ sent body (payload tampered in transit)
        $this->signed('POST', '/api/v1/events', $this->envelope('integration.ping', 'PING-1'), tamper: fn ($h, $c) => [$h, str_replace('PING-1', 'PING-2', $c)])
            ->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');
        // signed for one route, replayed on another
        $this->signed('GET', '/api/v1/health', tamper: fn ($h, $c) => [$h, $c])->assertOk();
        $h = Signature::headers('sales', 'k1', self::SALES_SECRET, 'POST', '/api/v1/ops/heartbeat', '');
        $server = ['HTTP_ACCEPT' => 'application/json'];
        foreach ($h as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->call('POST', '/api/v1/events', [], [], [], $server, '')->assertStatus(401)->assertJsonPath('code', 'SIGNATURE_INVALID');

        // least privilege: the scheduler may run a cycle, not send events; Sales may not run cycles
        $this->signed('POST', '/api/v1/events', $this->envelope('integration.ping', 'X'), system: 'scheduler', keyId: 's1')->assertStatus(403)->assertJsonPath('code', 'SCOPE_MISSING');
        $this->signed('POST', '/api/v1/ops/heartbeat', null)->assertStatus(403)->assertJsonPath('code', 'SCOPE_MISSING');
        $this->signed('POST', '/api/v1/ops/heartbeat', null, system: 'scheduler', keyId: 's1')->assertOk()->assertJsonPath('ran', true);

        // a disabled system cannot call at all
        config(['integration.systems.sales.enabled' => false]);
        $this->signed('GET', '/api/v1/health')->assertStatus(401)->assertJsonPath('code', 'SYSTEM_UNKNOWN');
        config(['integration.systems.sales.enabled' => true]);

        // rate limit per system key
        config(['integration.systems.sales.rate_per_minute' => 2]);
        Cache::flush();
        $this->signed('GET', '/api/v1/health')->assertOk();
        $this->signed('GET', '/api/v1/health')->assertOk();
        $this->signed('GET', '/api/v1/health')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
        Cache::flush();
    }

    public function test_events_are_received_once_validated_and_applied_in_order(): void
    {
        $ping = $this->envelope('integration.ping', 'PING-'.self::uid());
        $r = $this->send($ping)->assertStatus(202);
        $this->assertSame(['processed', true, 'svc.sales'], [$r->json('results.0.status'), $r->json('results.0.result.pong'), $r->json('results.0.result.as')]);
        // the same event again (a retry after a lost response): nothing happens twice
        $this->assertSame('duplicate', $this->send($ping)->json('results.0.status'));
        $this->assertSame(1, IntInbox::where('event_id', $ping['id'])->count());
        $this->assertTrue(AuditLog::where('action', 'INTEGRATION.EVENT_PROCESSED')->where('entity_number', 'like', '%'.$ping['subject'])->exists());

        // invalid envelopes are rejected with a reason and an exception, never stored as work
        $bad = $this->send(
            $this->envelope('order.secret_admin', 'ORD-1'),                               // type Sales may not send
            $this->envelope('integration.ping', 'ORD-1', [], null, ['source' => 'ops']),  // pretends to be another system
            $this->envelope('integration.ping', 'ORD-1', [], null, ['schemaVersion' => 9]),
            $this->envelope('integration.ping', '', []),
        )->assertStatus(202);
        $this->assertSame(['EVENT_TYPE_NOT_ALLOWED', 'SOURCE_MISMATCH', 'UNSUPPORTED_SCHEMA', 'INVALID_ENVELOPE'], array_column($bad->json('results'), 'code'));
        $this->assertTrue(IntException::where('code', 'EVENT_REJECTED')->where('status', 'open')->exists());
        $this->signed('POST', '/api/v1/events', 'not json')->assertStatus(400)->assertJsonPath('code', 'INVALID_JSON');
        $this->signed('POST', '/api/v1/events', array_fill(0, 101, $this->envelope('integration.ping', 'X')))->assertStatus(400)->assertJsonPath('code', 'BATCH_TOO_LARGE');

        // ordering per subject: an older event arriving after a newer one is recorded as stale and not applied
        $subject = 'ORD-SEQ-'.self::uid();
        $this->assertSame('processed', $this->send($this->envelope('integration.ping', $subject, [], 5))->json('results.0.status'));
        $late = $this->envelope('integration.ping', $subject, [], 3);
        $this->assertSame('stale', $this->send($late)->json('results.0.status'));
        $this->assertSame(5, (int) DB::table('int_subject_state')->where('source', 'sales')->where('subject', $subject)->value('last_sequence'));
        $this->assertSame('processed', $this->send($this->envelope('integration.ping', $subject, [], 6))->json('results.0.status'));
    }

    public function test_a_failing_consumer_is_retried_with_back_off_then_dead_lettered(): void
    {
        $inbox = app(InboxService::class);
        // fails once, then recovers on the scheduled retry
        FlakyHandler::$failures = 1;
        FlakyHandler::$calls = 0;
        $e = $this->envelope('test.flaky', 'ORD-FL-'.self::uid());
        $first = $this->send($e)->json('results.0');
        $this->assertSame(['failed', 'HANDLER_ERROR'], [$first['status'], $first['code']]);
        $row = IntInbox::where('event_id', $e['id'])->first();
        $this->assertSame(1, $row->attempts);
        $this->assertEqualsWithDelta(now()->addSeconds(30)->getTimestamp(), $row->next_attempt_at->getTimestamp(), 2);
        $this->assertSame(0, $inbox->processDue()['attempted'], 'not due yet');
        $this->travel(31)->seconds();
        $run = $this->signed('POST', '/api/v1/ops/heartbeat', null, system: 'scheduler', keyId: 's1')->assertOk();
        $this->assertSame(1, $run->json('inbox.processed'));
        $this->assertSame(['processed', 2], [$row->refresh()->status, FlakyHandler::$calls]);

        // never recovers: after the last back-off step it is dead and a critical exception is raised
        FlakyHandler::$failures = 99;
        $e2 = $this->envelope('test.flaky', 'ORD-DEAD-'.self::uid());
        $this->send($e2);
        $this->travel(31)->seconds();
        $inbox->processDue();
        $this->travel(61)->seconds();
        $inbox->processDue();
        $dead = IntInbox::where('event_id', $e2['id'])->first();
        $this->assertSame(['dead', 3], [$dead->status, $dead->attempts]);
        $x = IntException::where('code', 'EVENT_DEAD')->where('entity_ref', $e2['id'])->first();
        $this->assertSame(['open', 'crit'], [$x->status, $x->severity]);

        // replayed by a human once the consumer is fixed → processed, and the exception closes itself
        FlakyHandler::$failures = 0;
        $this->assertSame('processed', $inbox->replay($this->actor(), $dead->id)->status);
        $this->assertSame('resolved', $x->refresh()->status);
    }

    public function test_an_event_waiting_for_a_mapping_is_parked_and_replayed_when_it_exists(): void
    {
        GateHandler::$open = false;
        $e = $this->envelope('test.gate', 'ORD-GATE-'.self::uid(), [], 1);
        $r = $this->send($e)->json('results.0');
        $this->assertSame(['blocked', 'PRODUCT_UNMAPPED'], [$r['status'], $r['code']]);
        $this->assertTrue(IntException::where('code', 'PRODUCT_UNMAPPED')->where('entity_ref', 'P-TEST')->where('status', 'open')->exists());
        // the subject did not advance: nothing was applied
        $this->assertSame(0, (int) DB::table('int_subject_state')->where('subject', $e['subject'])->value('last_sequence'));

        GateHandler::$open = true;
        $this->assertSame(1, app(InboxService::class)->replayBlocked('PRODUCT_UNMAPPED'));
        $this->assertSame(['processed', $e['subject']], [IntInbox::where('event_id', $e['id'])->value('status'), IntInbox::where('event_id', $e['id'])->first()->result['applied']]);
    }

    public function test_published_events_are_delivered_signed_retried_and_dead_lettered(): void
    {
        $publisher = app(EventPublisher::class);
        $dispatcher = app(Dispatcher::class);
        $subject = 'ORD-PUB-'.self::uid();

        Http::fake(['sales.example.test/*' => Http::response(['results' => [['status' => 'processed']]], 202)]);
        $e1 = $publisher->publish('order.reserved', $subject, ['opsOrder' => 'SO-X']);
        $e2 = $publisher->publish('order.packed', $subject, ['opsOrder' => 'SO-X']);
        $this->assertSame([1, 2, 'routed', $subject], [$e1->sequence, $e2->sequence, $e1->status, $e1->correlation_id]);
        $ids = IntDelivery::whereIn('event_id', [$e1->id, $e2->id])->pluck('id')->all();
        $this->assertCount(2, $ids);
        $this->assertSame(2, $dispatcher->processDue(10, $ids)['sent']);
        Http::assertSent(function (Request $req) use ($e1) {
            $body = $req->body();
            $path = '/api/integration/events';
            $sig = Signature::sign(self::SALES_SECRET, $req->header('X-B2B-Timestamp')[0], 'POST', $path, $body);

            return $req['id'] === $e1->id && $req['sequence'] === 1 && $req['source'] === 'ops' && $req['correlationId'] === $e1->correlation_id
                && $req->header('X-B2B-System')[0] === 'ops' && $req->header('X-B2B-Key-Id')[0] === 'k1' && hash_equals($sig, $req->header('X-B2B-Signature')[0]);
        });
        // an event nobody subscribes to creates no delivery
        $none = $publisher->publish('fleet.internal_only', $subject, []);
        $this->assertSame(0, IntDelivery::where('event_id', $none->id)->count());
    }

    public function test_delivery_failures_back_off_open_the_breaker_and_end_in_the_dead_letter_queue(): void
    {
        $publisher = app(EventPublisher::class);
        $dispatcher = app(Dispatcher::class);
        $subject = 'ORD-FAIL-'.self::uid();

        // receiver down: retried after the back-off, then dead
        $reply = [503, 'down'];
        Http::fake(function () use (&$reply) { // one fake reading a variable: a second Http::fake would not replace the first
            return Http::response($reply[1], $reply[0]);
        });
        $e = $publisher->publish('order.reserved', $subject, []);
        $d = IntDelivery::where('event_id', $e->id)->first();
        $this->assertSame('failed', $dispatcher->attempt($d));
        $d->refresh();
        $this->assertSame([1, 503], [$d->attempts, $d->last_status]);
        $this->assertGreaterThanOrEqual(now()->addSeconds(30)->getTimestamp(), $d->next_attempt_at->getTimestamp());
        $this->assertSame('skipped', $dispatcher->attempt($d), 'not due: nobody may send it now');
        $this->travel(40)->seconds();
        $this->assertSame('failed', $dispatcher->attempt($d->refresh()));
        $this->travel(70)->seconds();
        $this->assertSame('dead', $dispatcher->attempt($d->refresh()));
        $this->assertTrue(IntException::where('code', 'DELIVERY_DEAD')->where('entity_ref', $e->id)->where('severity', 'crit')->exists());

        // a rejection that retrying cannot fix goes dead at once
        Cache::forget('int:breaker:sales');
        $reply = [422, ['code' => 'INVALID']];
        $bad = IntDelivery::where('event_id', $publisher->publish('order.reserved', $subject, [])->id)->first();
        $this->assertSame('dead', $dispatcher->attempt($bad));

        // circuit breaker: 5 consecutive failures → no more calls for a while, deliveries just wait
        Cache::forget('int:breaker:sales');
        $reply = [500, 'down'];
        $many = [];
        for ($i = 0; $i < 6; $i++) {
            $many[] = IntDelivery::where('event_id', $publisher->publish('order.packed', $subject.'-'.$i, [])->id)->first();
        }
        $outcomes = array_map(fn ($x) => $dispatcher->attempt($x), $many);
        $this->assertSame(['failed', 'failed', 'failed', 'failed', 'failed', 'waiting'], $outcomes);
        $this->assertSame('CIRCUIT_OPEN', $many[5]->refresh()->last_error);
        $this->assertSame(0, $many[5]->attempts, 'a breaker wait is not an attempt');
        $this->assertTrue($dispatcher->breakerState('sales')['open']);

        // manual retry of the dead delivery once the receiver is back
        Cache::forget('int:breaker:sales');
        $reply = [202, []];
        $this->assertSame('sent', $dispatcher->retry($this->actor(), $d->id)->status);

        // subscriber not configured: kept pending, explained, never counted as an attempt
        config(['integration.systems.sales.deliver_url' => null]);
        $orphan = IntDelivery::create(['event_id' => $e->id, 'subscriber' => 'sales2', 'status' => 'pending', 'next_attempt_at' => now()]);
        $this->assertSame('waiting', $dispatcher->attempt($orphan));
        $this->assertSame(['pending', 0, 'SUBSCRIBER_NOT_CONFIGURED'], [$orphan->refresh()->status, $orphan->attempts, $orphan->last_error]);
    }

    private function actor(): AuthUser
    {
        return new AuthUser(id: (string) DB::table('users')->where('username', 'admin')->value('id'), username: 'admin', nameAr: 'admin', nameEn: 'admin',
            roles: ['super'], permissions: [], warehouses: [], driverId: null, requestId: 'test');
    }
}
