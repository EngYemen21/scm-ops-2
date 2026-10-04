<?php

namespace Tests\Feature\Integration;

use App\Integration\Support\Signature;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Signed calls to the /api/v1 gateway as another system, and event envelopes. */
trait IntegrationTestHelpers
{
    protected const SALES_SECRET = 'sales-secret-k1-for-tests-only';

    protected const SCHED_SECRET = 'scheduler-secret-for-tests-only';

    protected function enableSystems(array $sales = []): void
    {
        config([
            'integration.systems.sales.enabled' => true,
            'integration.systems.sales.keys' => 'k1:'.self::SALES_SECRET.',k2:rotated-secret-for-tests',
            'integration.systems.sales.deliver_url' => 'https://sales.example.test/api/integration/events',
            'integration.systems.scheduler.enabled' => true,
            'integration.systems.scheduler.keys' => 's1:'.self::SCHED_SECRET,
        ]);
        foreach ($sales as $k => $v) {
            config(["integration.systems.sales.{$k}" => $v]);
        }
    }

    /** A signed request as $system. $tamper may alter the signed parts after signing. */
    protected function signed(string $method, string $uri, mixed $body = null, string $system = 'sales', string $keyId = 'k1', ?string $secret = null, ?int $ts = null, ?callable $tamper = null): TestResponse
    {
        $content = $body === null ? '' : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE));
        $secret ??= $system === 'scheduler' ? self::SCHED_SECRET : self::SALES_SECRET;
        $headers = Signature::headers($system, $keyId, $secret, $method, $uri, $content, $ts);
        if ($tamper) {
            [$headers, $content] = $tamper($headers, $content);
        }
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call($method, $uri, [], [], [], $server, $content);
    }

    protected function envelope(string $type, string $subject, array $data = [], ?int $sequence = null, array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(), 'type' => $type, 'source' => 'sales', 'subject' => $subject, 'sequence' => $sequence,
            'time' => now()->toIso8601ZuluString('millisecond'), 'schemaVersion' => 1, 'correlationId' => $subject, 'data' => $data,
        ], $extra);
    }

    protected function send(array ...$envelopes): TestResponse
    {
        return $this->signed('POST', '/api/v1/events', count($envelopes) === 1 ? $envelopes[0] : $envelopes);
    }
}
