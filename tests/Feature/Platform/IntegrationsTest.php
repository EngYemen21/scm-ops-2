<?php

namespace Tests\Feature\Platform;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\IntegrationEvent;
use App\Models\PodAttachment;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\B2bWebhook;
use App\Services\Integrations\Adapters\ErpAdapter;
use App\Services\Integrations\Adapters\HttpErp;
use App\Services\Integrations\Adapters\ObjectStorageAdapter;
use App\Services\Integrations\Adapters\PendingErp;
use App\Services\Integrations\Adapters\S3CompatibleStorage;
use App\Services\Integrations\Adapters\SigV4;
use App\Services\Integrations\IntegrationsService;
use App\Services\Integrations\OutboxService;
use App\Support\AppError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\ApiTestCase;

/** Integration status, outbox and attachments — above all the honesty rule: nothing is ever reported sent / uploaded / connected when it is not. */
class IntegrationsTest extends ApiTestCase
{
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private function fakeErp(array $result): ErpAdapter
    {
        return new class($result) implements ErpAdapter
        {
            public function __construct(private readonly array $result) {}

            public function configured(): bool
            {
                return true;
            }

            public function pushDocument(string $type, array $payload): array
            {
                return $this->result;
            }
        };
    }

    private function fakeStorage(bool $fails): ObjectStorageAdapter
    {
        return new class($fails) implements ObjectStorageAdapter
        {
            public function __construct(private readonly bool $fails) {}

            public function configured(): bool
            {
                return true;
            }

            public function putObject(string $key, string $bytes, string $mime): array
            {
                return $this->fails ? throw new RuntimeException('storage PUT failed: HTTP 500') : ['status' => 'uploaded', 'key' => $key, 'url' => "https://cdn.test/{$key}"];
            }

            public function getSignedUrl(string $key, int $expiresSeconds = 900): ?string
            {
                return null;
            }

            public function probe(int $timeoutMs): array
            {
                return ['ok' => true, 'detail' => 'fake'];
            }
        };
    }

    private function service(?ObjectStorageAdapter $storage = null): IntegrationsService
    {
        return new IntegrationsService(app(AuditService::class), $storage ?? AdapterFactory::storage([]), AdapterFactory::gps([]), AdapterFactory::maps([]), AdapterFactory::messaging([]), AdapterFactory::erp([]), AdapterFactory::b2bWebhook([]));
    }

    // ───────────── status ─────────────
    public function test_status_is_honest_nothing_is_connected(): void
    {
        $rows = $this->expectOk($this->getAs('sales', '/api/integrations/status'));
        $this->assertSame(['b2b', 'storage', 'gps', 'maps', 'whatsapp', 'email', 'erp'], array_column($rows, 'key'));
        foreach ($rows as $row) {
            $this->assertSame(['key', 'nameAr', 'nameEn', 'status', 'detail', 'detailAr'], array_keys($row));
            $this->assertSame('integration_pending', $row['status'], "{$row['key']} must not claim to be connected");
            $this->assertStringContainsString('pending', $row['detail']);
            $this->assertNotEmpty($row['nameAr']);
        }
        $this->assertStringNotContainsString('connected', json_encode($rows));
    }

    public function test_pending_adapters_send_nothing_and_say_so(): void
    {
        Http::fake();
        $svc = $this->service();
        $this->assertSame('integration_pending', $svc->erp->pushDocument('ShipmentDispatched', ['trip' => 'T'])['status']);
        $this->assertSame('integration_pending', $svc->b2b->pushDocument('ShipmentDispatched', [])['status']);
        $this->assertSame('integration_pending', $svc->messaging->sendWhatsApp('+966500000000', 'order_shipped', ['n' => 1])['status']);
        $this->assertSame('integration_pending', $svc->messaging->sendEmail('a@b.test', 'subject', '<p>x</p>')['status']);
        $this->assertSame(['status' => 'integration_pending', 'vehicleCode' => 'V-1'], array_intersect_key($svc->gps->getVehiclePosition('V-1'), ['status' => 1, 'vehicleCode' => 1]));
        $this->assertSame('integration_pending', $svc->gps->getVehicleTemperature('V-1')['status']);
        $this->assertSame('integration_pending', $svc->maps->eta('Riyadh', 'Jeddah')['status']);
        $route = $svc->maps->optimizeRoute([['id' => 'b'], ['id' => 'a'], ['id' => 'c']]);
        $this->assertSame(['integration_pending', ['b', 'a', 'c']], [$route['status'], $route['order']], 'a pending optimiser keeps the input order');
        $this->assertSame('integration_pending', $svc->storage->putObject('k', 'bytes', 'text/plain')['status']);
        $this->assertNull($svc->storage->getSignedUrl('k'));
        Http::assertNothingSent();
    }

    public function test_a_configured_but_unreachable_storage_reports_error_not_connected(): void
    {
        $bad = AdapterFactory::storage(['OBJECT_STORAGE_ENDPOINT' => 'http://127.0.0.1:9', 'OBJECT_STORAGE_BUCKET' => 'b', 'OBJECT_STORAGE_ACCESS_KEY' => 'a', 'OBJECT_STORAGE_SECRET_KEY' => 's']);
        $this->assertInstanceOf(S3CompatibleStorage::class, $bad);
        $started = microtime(true);
        $storage = collect($this->service($bad)->status())->firstWhere('key', 'storage');
        $this->assertSame('error', $storage['status']);
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_adapter_factory_selects_real_adapters_only_when_configured(): void
    {
        $this->assertInstanceOf(PendingErp::class, AdapterFactory::erp(['ERP_BASE_URL' => '  ']));
        $this->assertInstanceOf(HttpErp::class, AdapterFactory::erp(['ERP_BASE_URL' => 'https://erp.test/api']));
        $this->assertInstanceOf(B2bWebhook::class, AdapterFactory::b2bWebhook(['B2B_WEBHOOK_URL' => 'https://b2b.test/hook']));
        $this->assertFalse(AdapterFactory::storageConfigured(['OBJECT_STORAGE_ENDPOINT' => 'https://s3.test']), 'storage needs endpoint + bucket + both keys');
        $messaging = AdapterFactory::messaging(['SMTP_URL' => 'smtp://u:p@mail.test:587']);
        $this->assertSame([false, true], [$messaging->whatsappConfigured(), $messaging->emailConfigured()]);

        // A real adapter reports exactly what the remote side answered.
        Http::fake(['erp.test/*' => Http::response(['id' => 'DOC-7'], 201), 'b2b.test/*' => Http::response('maintenance', 503)]);
        $this->assertSame(['status' => 'ok', 'reference' => 'DOC-7'], AdapterFactory::erp(['ERP_BASE_URL' => 'https://erp.test/api/', 'ERP_TOKEN' => 't'])->pushDocument('PO_SENT', ['po' => 'PO-1']));
        Http::assertSent(fn ($request) => $request->url() === 'https://erp.test/api/documents/PO_SENT' && $request['type'] === 'PO_SENT' && $request['source'] === 'scm-ops' && $request->hasHeader('Authorization', 'Bearer t'));
        $failed = AdapterFactory::b2bWebhook(['B2B_WEBHOOK_URL' => 'https://b2b.test/hook'])->pushDocument('PO_SENT', []);
        $this->assertSame(['error', 'HTTP 503 maintenance'], [$failed['status'], $failed['detail']]);
    }

    public function test_sigv4_matches_the_aws_documented_presigned_url(): void
    {
        // Example from the AWS S3 "Authenticating Requests: Using Query Parameters" documentation.
        $signer = new SigV4('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 's3');
        $url = $signer->presignUrl('GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, Carbon::parse('2013-05-24T00:00:00Z'));
        $this->assertStringEndsWith('X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
        $this->assertSame('a%20b/%D8%B5.png', SigV4::uriEncode('a b/ص.png', false));
    }

    // ───────────── outbox ─────────────
    public function test_outbox_lists_events_and_keeps_them_pending_when_nothing_is_configured(): void
    {
        $type = 'TEST_'.self::uid().'_ShipmentDispatched';
        app(NotifyService::class)->event($type, ['trip' => 'TRP-TEST']);
        app(NotifyService::class)->event($type, ['trip' => 'TRP-TEST-2']);

        $page = $this->expectOk($this->getAs('sales', "/api/integrations/outbox?status=pending&type={$type}&pageSize=1"));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($page));
        $this->assertSame([2, 2, 1], [$page['total'], $page['pages'], count($page['items'])]);
        // the event envelope of the integration layer was added after the original fields (additive change)
        $this->assertSame(['id', 'type', 'payload', 'status', 'attempts', 'lastError', 'createdAt', 'sentAt', 'source', 'subject', 'sequence', 'correlationId', 'causationId', 'schemaVersion'], array_keys($page['items'][0]));
        $this->assertSame(['trip' => 'TRP-TEST-2'], $page['items'][0]['payload'], 'newest first');
        $this->assertSame(2, $this->expectOk($this->getAs('sales', '/api/integrations/outbox?q='.substr($type, 0, 12)))['total'], 'q is a contains-match on the type');
        $this->assertSame(0, $this->expectOk($this->getAs('sales', "/api/integrations/outbox?status=sent&type={$type}"))['total']);
        $this->expectRejected($this->getAs('sales', '/api/integrations/outbox?status=delivered'), 'INVALID_INPUT', [400]);

        // Running the outbox is an administrator action; a denied run changes nothing.
        $this->expectRejected($this->postAs('sales', '/api/integrations/outbox/run'), 'FORBIDDEN', [403]);
        $this->assertSame(2, IntegrationEvent::where('type', $type)->whereNull('last_error')->count());

        // Body-less action. Nothing is configured → nothing is sent, the events stay pending and say why.
        $run = $this->expectOk($this->postAs('admin', '/api/integrations/outbox/run'));
        $this->assertSame(['target', 'processed', 'sent', 'failed', 'pending'], array_keys($run));
        $this->assertNull($run['target']);
        $this->assertSame([0, 0], [$run['sent'], $run['failed']]);
        $this->assertSame($run['processed'], $run['pending']);
        $this->assertGreaterThanOrEqual(2, $run['pending']);
        foreach (IntegrationEvent::where('type', $type)->get() as $event) {
            $this->assertSame(['pending', 'integration_pending', 0], [$event->status, $event->last_error, $event->attempts]);
            $this->assertNull($event->sent_at);
        }
        $this->assertSame(0, IntegrationEvent::where('status', 'sent')->count(), 'nothing may ever be marked sent without a provider');
        $this->assertSame(1, $this->expectOk($this->postAs('admin', '/api/integrations/outbox/run', ['limit' => 1]))['processed']);
        $this->expectRejected($this->postAs('admin', '/api/integrations/outbox/run', ['limit' => 0]), 'INVALID_INPUT', [400]);
    }

    public function test_outbox_marks_sent_or_failed_only_from_the_adapters_answer_and_retry_requeues(): void
    {
        $uid = self::uid();
        $sentEvent = IntegrationEvent::create(['type' => "TEST_{$uid}_DeliveryCompleted", 'payload' => ['pod' => 1]]);
        $viaB2b = new OutboxService($this->fakeErp(['status' => 'error', 'detail' => 'erp should not be used']), $this->fakeErp(['status' => 'ok', 'reference' => 'B2B-1']));
        $this->assertSame('b2b', $viaB2b->target()['kind'], 'the B2B webhook wins over the ERP');
        $this->assertSame(['target' => 'b2b', 'processed' => 1, 'sent' => 1, 'failed' => 0, 'pending' => 0], $viaB2b->processPending(50, [$sentEvent->id]));
        $sentEvent->refresh();
        $this->assertSame(['sent', 1, 'ref:B2B-1'], [$sentEvent->status, $sentEvent->attempts, $sentEvent->last_error]);
        $this->assertNotNull($sentEvent->sent_at);

        $failEvent = IntegrationEvent::create(['type' => "TEST_{$uid}_DeliveryFailed", 'payload' => []]);
        $erpOnly = new OutboxService($this->fakeErp(['status' => 'error', 'detail' => 'HTTP 503']), new PendingErp);
        $this->assertSame('erp', $erpOnly->target()['kind']);
        $erpOnly->processPending(50, [$failEvent->id]);
        $failEvent->refresh();
        $this->assertSame(['failed', 1, 'HTTP 503'], [$failEvent->status, $failEvent->attempts, $failEvent->last_error]);
        $this->assertNull($failEvent->sent_at);

        // Retry over HTTP: permission, re-queue, no-op on a non-failed event, null for an unknown id.
        $this->expectRejected($this->postAs('sales', "/api/integrations/outbox/{$failEvent->id}/retry"), 'FORBIDDEN', [403]);
        $this->assertSame('failed', $failEvent->refresh()->status);
        $retried = $this->expectOk($this->postAs('admin', "/api/integrations/outbox/{$failEvent->id}/retry"));
        $this->assertSame(['pending', null, 1], [$retried['status'], $retried['lastError'], $retried['attempts']]);
        $again = $this->expectOk($this->postAs('admin', "/api/integrations/outbox/{$sentEvent->id}/retry"));
        $this->assertSame('sent', $again['status'], 'a delivered event is never re-queued');
        $unknown = $this->postAs('admin', '/api/integrations/outbox/does-not-exist/retry');
        $unknown->assertOk();
        $this->assertSame('null', trim($unknown->getContent()));

        IntegrationEvent::whereIn('id', [$sentEvent->id, $failEvent->id])->delete();
    }

    // ───────────── attachments ─────────────
    public function test_attachment_without_storage_is_integration_pending_with_no_url(): void
    {
        $entityId = 'E'.self::uid();
        $row = $this->expectOk($this->postAs('sales', '/api/integrations/attachments', ['entityType' => 'TestEntity', 'entityId' => $entityId, 'fileName' => 'proof.png', 'mime' => 'image/png', 'base64' => self::PNG_1PX]));
        $this->assertSame(['id', 'entityType', 'entityId', 'fileName', 'mime', 'size', 'storageKey', 'url', 'status', 'createdAt'], array_keys($row));
        $this->assertSame('integration_pending', $row['status']);
        $this->assertNull($row['storageKey']);
        $this->assertNull($row['url']);
        $this->assertSame(strlen(base64_decode(self::PNG_1PX)), $row['size']);

        $dataUrl = $this->expectOk($this->postAs('sales', '/api/integrations/attachments', ['entityType' => 'TestEntity', 'entityId' => $entityId, 'fileName' => 'a/../b صورة.png', 'mime' => 'image/png', 'base64' => 'data:image/png;base64,'.self::PNG_1PX]));
        $this->assertSame($row['size'], $dataUrl['size']);
        $this->assertStringNotContainsString('/', $dataUrl['fileName']);
        $this->assertStringContainsString('صورة', $dataUrl['fileName']);

        $list = $this->expectOk($this->getAs('wm', "/api/integrations/attachments?entityType=TestEntity&entityId={$entityId}"));
        $this->assertSame([$dataUrl['id'], $row['id']], array_column($list, 'id'), 'newest first');
        $audit = AuditLog::where('action', 'ATTACHMENT.ADD')->where('entity_id', $entityId)->latest('at')->first();
        $this->assertSame('sales', $audit->username);
        $this->assertStringContainsString('integration_pending', $audit->new_value);

        $this->expectRejected($this->getAs('wm', '/api/integrations/attachments?entityType=TestEntity'), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/integrations/attachments', ['entityType' => 'TestEntity', 'entityId' => $entityId, 'fileName' => 'x.bin', 'mime' => 'not a mime', 'base64' => self::PNG_1PX]), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('sales', '/api/integrations/attachments', ['entityType' => 'TestEntity', 'entityId' => $entityId, 'fileName' => 'x.bin', 'mime' => 'application/octet-stream', 'base64' => '!!!!']), 'ATTACHMENT_EMPTY', [400]);
        $this->assertSame(2, Attachment::where('entity_id', $entityId)->count());
    }

    public function test_oversized_upload_is_rejected_and_a_storage_failure_saves_nothing(): void
    {
        $entityId = 'E'.self::uid();
        try {
            $this->service()->attach(null, 'TestEntity', $entityId, ['fileName' => 'x.bin', 'mime' => 'application/octet-stream', 'base64' => base64_encode(str_repeat("\0", IntegrationsService::MAX_ATTACHMENT_BYTES + 1))]);
            $this->fail('expected ATTACHMENT_TOO_LARGE');
        } catch (AppError $e) {
            $this->assertSame(['ATTACHMENT_TOO_LARGE', 400], [$e->errorCode, $e->status()]);
        }

        // With a working provider the row is `uploaded` with its key and URL…
        $row = $this->service($this->fakeStorage(false))->attach(null, 'TestEntity', $entityId, ['fileName' => 'doc.png', 'mime' => 'image/png', 'base64' => self::PNG_1PX]);
        $this->assertSame('uploaded', $row->status);
        $this->assertStringStartsWith("testentity/{$entityId}/", $row->storage_key);
        $this->assertStringStartsWith('https://cdn.test/', $row->url);

        // …and a remote failure is a business error that leaves no attachment behind.
        try {
            $this->service($this->fakeStorage(true))->attach(null, 'TestEntity', $entityId, ['fileName' => 'doc.png', 'mime' => 'image/png', 'base64' => self::PNG_1PX]);
            $this->fail('expected STORAGE_UPLOAD_FAILED');
        } catch (AppError $e) {
            $this->assertSame(['STORAGE_UPLOAD_FAILED', 422], [$e->errorCode, $e->status()]);
        }
        $this->assertSame(1, Attachment::where('entity_id', $entityId)->count());
    }

    public function test_pod_attachments_need_delivery_execute_and_stay_pending(): void
    {
        $file = ['kind' => 'signature', 'fileName' => 'sign.png', 'mime' => 'image/png', 'base64' => self::PNG_1PX];
        $before = PodAttachment::count();
        $this->expectRejected($this->postAs('sales', '/api/integrations/pods/POD-800/attachments', $file), 'FORBIDDEN', [403]);
        $this->assertSame($before, PodAttachment::count());

        $row = $this->expectOk($this->postAs('driver', '/api/integrations/pods/POD-800/attachments', $file));
        $this->assertSame(['signature', 'integration_pending', null, null], [$row['kind'], $row['status'], $row['storageKey'], $row['url']]);
        $list = $this->expectOk($this->getAs('sales', '/api/integrations/pods/POD-800/attachments'));
        $this->assertContains($row['id'], array_column($list, 'id'));
        $this->assertSame('POD-800', AuditLog::where('action', 'POD.ATTACHMENT')->latest('at')->first()->entity_number);

        $this->expectRejected($this->postAs('driver', '/api/integrations/pods/POD-NOPE/attachments', $file), 'POD_NOT_FOUND', [404]);
        $this->expectRejected($this->getAs('driver', '/api/integrations/pods/POD-NOPE/attachments'), 'POD_NOT_FOUND', [404]);
        $this->expectRejected($this->postAs('driver', '/api/integrations/pods/POD-800/attachments', ['kind' => 'video'] + $file), 'INVALID_INPUT', [400]);
    }
}
