<?php

namespace Tests;

use App\Services\Core\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Base for API / flow tests. They run against a real MySQL database (scm_ops_test, see phpunit.xml) that is
 * rebuilt and seeded ONCE per test run: flow tests are sequential stories (PR -> PO -> GRN -> ...), so state is
 * meant to carry over inside a test class. Tests must create their own documents and never depend on another
 * class's leftovers; use unique codes (self::uid()).
 */
abstract class ApiTestCase extends TestCase
{
    private static bool $databaseReady = false;

    /** @var array<string,string> username => access token */
    private static array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! self::$databaseReady) {
            Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
            self::$databaseReady = true;
            self::$tokens = [];
        }
        $this->app->make(SettingsService::class)->flush();
    }

    /** Short unique suffix for codes/SKUs created by a test. */
    protected static function uid(): string
    {
        return substr((string) (int) (microtime(true) * 1000), -7);
    }

    protected function token(string $username): string
    {
        if (! isset(self::$tokens[$username])) {
            $res = $this->postJson('/api/auth/login', ['username' => $username, 'password' => env('SEED_PASSWORD')]);
            Assert::assertTrue($res->isSuccessful(), "login failed for {$username}: ".$res->getContent());
            self::$tokens[$username] = $res->json('accessToken');
        }

        return self::$tokens[$username];
    }

    protected function getAs(string $user, string $url): TestResponse
    {
        return $this->getJson($url, ['Authorization' => 'Bearer '.$this->token($user)]);
    }

    protected function postAs(string $user, string $url, array $body = [], array $headers = []): TestResponse
    {
        return $this->postJson($url, $body, $headers + ['Authorization' => 'Bearer '.$this->token($user)]);
    }

    protected function patchAs(string $user, string $url, array $body = []): TestResponse
    {
        return $this->patchJson($url, $body, ['Authorization' => 'Bearer '.$this->token($user)]);
    }

    protected function putAs(string $user, string $url, array $body = []): TestResponse
    {
        return $this->putJson($url, $body, ['Authorization' => 'Bearer '.$this->token($user)]);
    }

    protected function deleteAs(string $user, string $url): TestResponse
    {
        return $this->deleteJson($url, [], ['Authorization' => 'Bearer '.$this->token($user)]);
    }

    /** Asserts 2xx and returns the decoded body. */
    protected function expectOk(TestResponse $res): array
    {
        Assert::assertTrue($res->isSuccessful(), 'expected 2xx but got '.$res->getStatusCode().': '.$res->getContent());

        return (array) $res->json();
    }

    /** Asserts a typed rejection (4xx with the API error body) and, optionally, its machine code. */
    protected function expectRejected(TestResponse $res, ?string $code = null, array $statuses = [400, 403, 404, 409, 422]): array
    {
        Assert::assertContains($res->getStatusCode(), $statuses, 'expected rejection ('.implode('/', $statuses).') but got '.$res->getStatusCode().': '.$res->getContent());
        $body = (array) $res->json();
        Assert::assertArrayHasKey('category', $body, 'error body must follow the API error contract: '.$res->getContent());
        if ($code !== null) {
            Assert::assertSame($code, $body['code'] ?? null, 'unexpected error code: '.$res->getContent());
        }

        return $body;
    }
}
