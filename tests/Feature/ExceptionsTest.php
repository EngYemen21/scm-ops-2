<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\ApiTestCase;

/** Exceptions module + the cross-cutting API behaviour it is the first to exercise (RBAC, idempotency, body-less actions). */
class ExceptionsTest extends ApiTestCase
{
    public function test_list_is_paged_and_carries_sla_info(): void
    {
        $body = $this->expectOk($this->getAs('admin', '/api/exceptions?pageSize=5'));
        $this->assertSame(['items', 'total', 'page', 'pageSize', 'pages'], array_keys($body));
        $this->assertLessThanOrEqual(5, count($body['items']));
        $this->assertArrayHasKey('leftMin', $body['items'][0]['sla']);
        $this->assertArrayHasKey('events', $body['items'][0]);
        $this->assertArrayHasKey('entityNumber', $body['items'][0], 'API JSON is camelCase');
    }

    public function test_raise_ack_resolve_with_bodyless_action_buttons(): void
    {
        $exc = $this->expectOk($this->postAs('wm', '/api/exceptions', ['kind' => 'wrongloc', 'textAr' => 'موقع خاطئ أثناء الاختبار', 'entityNumber' => 'BIN-T']));
        $this->assertSame('open', $exc['status']);
        $this->assertSame('wm', $exc['ownerRole']);
        $this->assertStringStartsWith('EXC-', $exc['number']);

        // UI action buttons POST without any body.
        $ack = $this->expectOk($this->postAs('wm', "/api/exceptions/{$exc['number']}/ack"));
        $this->assertSame('ack', $ack['status']);
        $done = $this->expectOk($this->postAs('wm', "/api/exceptions/{$exc['id']}/resolve", ['note' => 'تم التصحيح']));
        $this->assertSame('resolved', $done['status']);
        $this->assertSame('تم التصحيح', $done['resolution']);

        $this->expectRejected($this->postAs('wm', "/api/exceptions/{$exc['number']}/ack"), 'EXC_CLOSED', [422]);
        $detail = $this->expectOk($this->getAs('wm', "/api/exceptions/{$exc['number']}"));
        $this->assertSame(['open', 'ack', 'resolved'], array_column($detail['events'], 'toStatus'));
    }

    public function test_forbidden_attempt_changes_nothing_and_is_audited(): void
    {
        $before = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $total = $this->expectOk($this->getAs('admin', '/api/exceptions'))['total'];
        $body = $this->expectRejected($this->postAs('sales', '/api/exceptions', ['kind' => 'other', 'textAr' => 'غير مصرح']), 'FORBIDDEN', [403]);
        $this->assertSame('FORBIDDEN', $body['category']);
        $this->assertSame($total, $this->expectOk($this->getAs('admin', '/api/exceptions'))['total']);
        $this->assertSame($before + 1, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
    }

    public function test_idempotency_key_replays_the_first_response(): void
    {
        $key = ['Idempotency-Key' => 'exc-'.self::uid()];
        $first = $this->postAs('wm', '/api/exceptions', ['kind' => 'other', 'textAr' => 'نقر مزدوج'], $key);
        $second = $this->postAs('wm', '/api/exceptions', ['kind' => 'other', 'textAr' => 'نقر مزدوج'], $key);
        $this->assertSame($this->expectOk($first)['number'], $this->expectOk($second)['number'], 'a retry must not create a second document');
        $this->assertSame('true', $second->headers->get('X-Idempotent-Replay'));
    }

    public function test_validation_and_not_found(): void
    {
        $this->expectRejected($this->postAs('wm', '/api/exceptions', ['kind' => 'other']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->getAs('wm', '/api/exceptions/EXC-0000-NOPE'), 'EXC_NOT_FOUND', [404]);
    }
}
