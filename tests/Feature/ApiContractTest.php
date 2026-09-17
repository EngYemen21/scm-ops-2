<?php

namespace Tests\Feature;

use Tests\ApiTestCase;

/** Cross-cutting HTTP behaviour that every domain relies on. */
class ApiContractTest extends ApiTestCase
{
    /** The client's warehouse selector sends "all" for "no filter"; it must never filter everything away. */
    public function test_warehouse_all_means_no_warehouse_filter(): void
    {
        foreach (['/api/returns', '/api/inventory/balances', '/api/sales/orders', '/api/inbound/shipments', '/api/transport/trips'] as $url) {
            $plain = $this->expectOk($this->getAs('admin', $url))['total'];
            $all = $this->expectOk($this->getAs('admin', $url.'?warehouse=all'))['total'];
            $this->assertGreaterThan(0, $plain, "{$url} has demo rows");
            $this->assertSame($plain, $all, "{$url}?warehouse=all must equal the unfiltered list");
            $one = $this->expectOk($this->getAs('admin', $url.'?warehouse=JED'))['total'];
            $this->assertLessThanOrEqual($plain, $one);
        }
    }

    public function test_every_response_carries_a_request_id_and_readable_arabic(): void
    {
        $res = $this->getAs('admin', '/api/exceptions?pageSize=1');
        $this->assertNotEmpty($res->headers->get('X-Request-Id'));
        $this->assertStringNotContainsString('\u06', (string) $res->getContent(), 'Arabic is emitted as UTF-8, not \uXXXX escapes');
        $echo = $this->getJson('/api/auth/me', ['X-Request-Id' => 'client-req-1']);
        $this->assertSame('client-req-1', $echo->headers->get('X-Request-Id'));
        $this->assertSame('client-req-1', $echo->json('requestId'));
    }

    public function test_the_single_page_shell_never_answers_for_api_paths(): void
    {
        $this->get('/sales')->assertOk()->assertSee('id="app"', false);
        // an unknown API path answers with the API error contract (JSON), never with the HTML shell — signed in or not
        $anonymous = $this->expectRejected($this->get('/api/nope'), 'HTTP_404', [404]);
        $this->assertSame('NOT_FOUND', $anonymous['category']);
        $this->expectRejected($this->getAs('admin', '/api/nope'), 'HTTP_404', [404]);
    }
}
