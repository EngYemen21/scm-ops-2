<?php

namespace Tests\Feature;

use App\Integration\IntegrationBootstrap;
use App\Models\AuditLog;
use App\Models\RefreshToken;
use Tests\ApiTestCase;

/** Sessions: login, refresh-token rotation with its grace window, logout, RBAC denial, error contract. */
class AuthSessionTest extends ApiTestCase
{
    public function test_login_returns_tokens_and_live_permissions(): void
    {
        $body = $this->expectOk($this->postJson('/api/auth/login', ['username' => 'ADMIN ', 'password' => env('SEED_PASSWORD')]));
        $this->assertSame(['accessToken', 'refreshToken', 'expiresIn', 'user'], array_keys($body));
        $this->assertSame('admin', $body['user']['username']);
        $this->assertContains('super', $body['user']['roles']);
        // every permission of the system, plus the integration layer's own (App\Integration\IntegrationBootstrap)
        $this->assertCount(count(config('scm.PERMISSIONS')) + count(IntegrationBootstrap::PERMISSIONS), $body['user']['permissions']);
        $this->assertContains('integration.manage', $body['user']['permissions']);
        $this->assertSame(config('scm.ROLE_NAV.super'), $body['user']['nav']);
    }

    public function test_bad_credentials_are_rejected_and_audited(): void
    {
        $before = AuditLog::where('action', 'SECURITY.LOGIN_FAILED')->count();
        $this->expectRejected($this->postJson('/api/auth/login', ['username' => 'admin', 'password' => 'nope']), 'BAD_CREDENTIALS', [401]);
        $this->assertSame($before + 1, AuditLog::where('action', 'SECURITY.LOGIN_FAILED')->count());
    }

    public function test_missing_fields_use_the_validation_contract(): void
    {
        $body = $this->expectRejected($this->postJson('/api/auth/login', []), 'INVALID_INPUT', [400]);
        $this->assertSame('VALIDATION', $body['category']);
        $this->assertSame(['username', 'password'], array_column($body['details'], 'path'));
        $this->assertNotEmpty($body['requestId']);
    }

    public function test_protected_route_needs_a_token(): void
    {
        $this->expectRejected($this->getJson('/api/auth/me'), 'NO_TOKEN', [401]);
        $this->expectRejected($this->getJson('/api/auth/me', ['Authorization' => 'Bearer not-a-jwt']), 'INVALID_TOKEN', [401]);
    }

    public function test_refresh_rotates_tolerates_one_reuse_in_grace_then_rejects(): void
    {
        $login = $this->expectOk($this->postJson('/api/auth/login', ['username' => 'sales', 'password' => env('SEED_PASSWORD')]));
        $rt1 = $login['refreshToken'];

        $r2 = $this->expectOk($this->postJson('/api/auth/refresh', ['refreshToken' => $rt1]));
        $this->assertNotSame($rt1, $r2['refreshToken']);
        $this->assertSame('sales', $r2['user']['username']);

        // The reply to the first refresh was "lost" (page unload): the client retries with rt1 moments later.
        $r3 = $this->expectOk($this->postJson('/api/auth/refresh', ['refreshToken' => $rt1]));
        $this->expectOk($this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$r3['accessToken']]));
        // The rotated token keeps working too.
        $this->expectOk($this->postJson('/api/auth/refresh', ['refreshToken' => $r2['refreshToken']]));

        // Past the grace window the old token is dead.
        RefreshToken::where('token_hash', hash('sha256', $rt1))->update(['revoked_at' => now()->subMinutes(10)]);
        $this->expectRejected($this->postJson('/api/auth/refresh', ['refreshToken' => $rt1]), 'REFRESH_INVALID', [401]);
    }

    public function test_logout_kills_the_refresh_token_immediately(): void
    {
        $login = $this->expectOk($this->postJson('/api/auth/login', ['username' => 'sales', 'password' => env('SEED_PASSWORD')]));
        $this->expectOk($this->postJson('/api/auth/logout', ['refreshToken' => $login['refreshToken']]));
        $this->expectRejected($this->postJson('/api/auth/refresh', ['refreshToken' => $login['refreshToken']]), 'REFRESH_INVALID', [401]);
    }

    public function test_repeated_failed_logins_are_throttled_per_username(): void
    {
        $name = 'ghost-'.self::uid();
        for ($i = 0; $i < 10; $i++) {
            $this->expectRejected($this->postJson('/api/auth/login', ['username' => $name, 'password' => 'wrong']), 'BAD_CREDENTIALS', [401]);
        }
        $blocked = $this->expectRejected($this->postJson('/api/auth/login', ['username' => $name, 'password' => 'wrong']), 'TOO_MANY_ATTEMPTS', [429]);
        $this->assertNotEmpty($blocked['messageEn']);
        // another user from the same address is not affected
        $this->expectOk($this->postJson('/api/auth/login', ['username' => 'admin', 'password' => config('scm_auth.seed_password')]));
    }

    public function test_unknown_route_uses_the_error_contract(): void
    {
        $body = $this->expectRejected($this->getAs('admin', '/api/does-not-exist'), 'HTTP_404', [404]);
        $this->assertSame('NOT_FOUND', $body['category']);
    }
}
