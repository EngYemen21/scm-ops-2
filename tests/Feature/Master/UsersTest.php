<?php

namespace Tests\Feature\Master;

use App\Models\AuditLog;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\ApiTestCase;

/** User administration: accounts, roles, warehouse scope, role permissions. Everything needs user.manage. */
class UsersTest extends ApiTestCase
{
    private const PASSWORD = 'Spec-Pass-2026';

    private static string $n = '';

    private static array $created = [];

    private static function username(): string
    {
        return 'spec'.(self::$n ?: self::$n = self::uid());
    }

    public function test_only_user_managers_reach_the_module(): void
    {
        $count = User::count();
        $before = AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count();
        $this->expectRejected($this->postAs('worker', '/api/users', ['username' => 'x', 'password' => 'xxxxxxxx', 'nameAr' => 'x', 'nameEn' => 'x', 'roles' => ['super']]), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('wm', '/api/users'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('sales', '/api/users/roles'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->getAs('gm', '/api/users/permissions'), 'FORBIDDEN', [403]);
        $this->expectRejected($this->putAs('proc', '/api/users/roles/proc/permissions', ['permissions' => ['user.manage']]), 'FORBIDDEN', [403]);
        $this->assertSame($count, User::count());
        $this->assertSame($before + 5, AuditLog::where('action', 'SECURITY.UNAUTHORIZED')->count());
    }

    public function test_list_roles_and_permission_catalogue(): void
    {
        $users = $this->expectOk($this->getAs('admin', '/api/users'));
        $names = array_column($users, 'username');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $admin = collect($users)->firstWhere('username', 'admin');
        $this->assertSame(['id', 'username', 'email', 'nameAr', 'nameEn', 'initials', 'active', 'lastLoginAt', 'roles', 'warehouses'], array_keys($admin));
        $this->assertContains('super', $admin['roles']);
        $this->assertStringNotContainsString('password', json_encode($users));

        $roles = collect($this->expectOk($this->getAs('admin', '/api/users/roles')));
        $this->assertSame(['id', 'key', 'nameAr', 'nameEn', 'permissions'], array_keys($roles->first()));
        $this->assertContains('user.manage', $roles->firstWhere('key', 'super')['permissions']);
        $this->assertNotContains('user.manage', $roles->firstWhere('key', 'worker')['permissions']);

        $permissions = $this->expectOk($this->getAs('admin', '/api/users/permissions'));
        $this->assertSame(config('scm.PERMISSIONS'), $permissions);
        $this->assertContains('warehouse.manage', $permissions);
    }

    public function test_create_user_validates_and_hashes_the_password(): void
    {
        $body = ['username' => ' '.strtoupper(self::username()).' ', 'password' => self::PASSWORD, 'nameAr' => 'مستخدم اختبار', 'nameEn' => 'Spec user', 'roles' => ['worker'], 'warehouses' => ['RYD', 'JED']];
        $this->expectRejected($this->postAs('admin', '/api/users', ['password' => 'short'] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/users', ['roles' => []] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/users', ['email' => 'nope'] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/users', ['username' => 'ab'] + $body), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->postAs('admin', '/api/users', ['roles' => ['worker', 'ghost']] + $body), 'BAD_ROLE', [400]);
        $this->assertFalse(User::where('username', self::username())->exists());

        $u = $this->expectOk($this->postAs('admin', '/api/users', $body));
        self::$created = $u;
        $this->assertSame(['id', 'username'], array_keys($u));
        $this->assertSame(self::username(), $u['username'], 'usernames are trimmed and lower-cased');
        $row = User::findOrFail($u['id']);
        $this->assertTrue(Hash::check(self::PASSWORD, $row->password_hash));
        $this->assertTrue($row->must_change_password);
        $this->assertSame('م', $row->initials);
        $listed = collect($this->expectOk($this->getAs('admin', '/api/users')))->firstWhere('id', $u['id']);
        $this->assertSame(['worker'], $listed['roles']);
        $this->assertEqualsCanonicalizing(['RYD', 'JED'], $listed['warehouses']);
        $audit = AuditLog::where('entity_id', $u['id'])->where('action', 'USER.CREATE')->firstOrFail();
        $this->assertStringNotContainsString(self::PASSWORD, (string) $audit->new_value);

        $this->expectRejected($this->postAs('admin', '/api/users', $body), 'USERNAME_TAKEN', [409]);
    }

    public function test_the_new_user_can_sign_in_with_the_roles_given(): void
    {
        $login = $this->postJson('/api/auth/login', ['username' => self::username(), 'password' => self::PASSWORD]);
        $body = $this->expectOk($login);
        $this->assertSame(['worker'], $body['user']['roles']);
        $this->assertTrue($body['user']['mustChangePassword']);
        $this->assertEqualsCanonicalizing(['RYD', 'JED'], array_column($body['user']['warehouses'], 'code'));
    }

    public function test_update_user_roles_scope_password_and_deactivation(): void
    {
        $id = self::$created['id'];
        $this->expectRejected($this->patchAs('admin', '/api/users/nope', ['nameAr' => 'x']), 'USER_NOT_FOUND', [404]);
        $this->expectRejected($this->patchAs('admin', "/api/users/{$id}", ['password' => 'short']), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->patchAs('admin', "/api/users/{$id}", ['roles' => 'worker']), 'INVALID_INPUT', [400]);

        $this->expectOk($this->patchAs('admin', "/api/users/{$id}", ['nameEn' => 'Spec user 2', 'email' => self::username().'@example.com', 'roles' => ['inv', 'sales'], 'warehouses' => ['DMM'], 'password' => self::PASSWORD.'!']));
        $listed = collect($this->expectOk($this->getAs('admin', '/api/users')))->firstWhere('id', $id);
        $this->assertEqualsCanonicalizing(['inv', 'sales'], $listed['roles']);
        $this->assertSame(['DMM'], $listed['warehouses']);
        $this->assertSame('Spec user 2', $listed['nameEn']);
        $this->assertTrue(Hash::check(self::PASSWORD.'!', User::findOrFail($id)->password_hash));
        $roles = AuditLog::where('entity_id', $id)->where('action', 'USER.ROLES')->firstOrFail();
        $this->assertSame('["worker"]', $roles->old_value);
        $update = AuditLog::where('entity_id', $id)->where('action', 'USER.UPDATE')->firstOrFail();
        $this->assertStringContainsString('***', $update->new_value);
        $this->assertStringNotContainsString(self::PASSWORD, $update->new_value);

        // Deactivation ends every session of the account and blocks new ones.
        $session = $this->expectOk($this->postJson('/api/auth/login', ['username' => self::username(), 'password' => self::PASSWORD.'!']));
        $this->assertGreaterThan(0, RefreshToken::where('user_id', $id)->whereNull('revoked_at')->count());
        $this->expectOk($this->patchAs('admin', "/api/users/{$id}", ['active' => false]));
        $this->assertSame(0, RefreshToken::where('user_id', $id)->whereNull('revoked_at')->count());
        $this->expectRejected($this->postJson('/api/auth/refresh', ['refreshToken' => $session['refreshToken']]), 'REFRESH_INVALID', [401]);
        $this->expectRejected($this->postJson('/api/auth/login', ['username' => self::username(), 'password' => self::PASSWORD.'!']), 'BAD_CREDENTIALS', [401]);
        $this->expectRejected($this->getJson('/api/products', ['Authorization' => 'Bearer '.$session['accessToken']]), 'USER_INACTIVE', [401]);
    }

    public function test_role_permissions_can_be_replaced_except_for_super(): void
    {
        $this->expectRejected($this->putAs('admin', '/api/users/roles/super/permissions', ['permissions' => []]), 'SUPER_LOCKED', [400]);
        $this->expectRejected($this->putAs('admin', '/api/users/roles/ghost/permissions', ['permissions' => []]), 'ROLE_NOT_FOUND', [404]);
        $this->expectRejected($this->putAs('admin', '/api/users/roles/auditor/permissions', []), 'INVALID_INPUT', [400]);
        $this->expectRejected($this->putAs('admin', '/api/users/roles/auditor/permissions', ['permissions' => 'audit.view']), 'INVALID_INPUT', [400]);

        $original = collect($this->expectOk($this->getAs('admin', '/api/users/roles')))->firstWhere('key', 'auditor')['permissions'];
        $this->expectOk($this->putAs('admin', '/api/users/roles/auditor/permissions', ['permissions' => ['audit.view', 'page.dash', 'not.a.permission']]));
        $now = collect($this->expectOk($this->getAs('admin', '/api/users/roles')))->firstWhere('key', 'auditor')['permissions'];
        $this->assertEqualsCanonicalizing(['audit.view', 'page.dash'], $now, 'unknown permission keys are ignored');
        $audit = AuditLog::where('action', 'ROLE.PERMISSIONS')->where('entity_number', 'auditor')->latest('at')->firstOrFail();
        $this->assertStringContainsString('page.ledger', $audit->old_value);

        $this->expectOk($this->putAs('admin', '/api/users/roles/auditor/permissions', ['permissions' => []]));
        $this->assertSame([], collect($this->expectOk($this->getAs('admin', '/api/users/roles')))->firstWhere('key', 'auditor')['permissions']);
        $this->expectOk($this->putAs('admin', '/api/users/roles/auditor/permissions', ['permissions' => $original]));
    }

    public function test_permission_changes_apply_to_live_sessions(): void
    {
        // Permissions are read from the database on every request, so a grant works without a new login.
        $inv = collect($this->expectOk($this->getAs('admin', '/api/users/roles')))->firstWhere('key', 'finance')['permissions'];
        $this->expectRejected($this->postAs('finance', '/api/uoms', ['code' => 'fin'.substr(self::username(), -4), 'nameAr' => 'مرفوض']), 'FORBIDDEN', [403]);
        $this->expectOk($this->putAs('admin', '/api/users/roles/finance/permissions', ['permissions' => [...$inv, 'product.manage']]));
        $this->expectOk($this->postAs('finance', '/api/uoms', ['code' => 'fin'.substr(self::username(), -4), 'nameAr' => 'وحدة مالية']));
        $this->expectOk($this->putAs('admin', '/api/users/roles/finance/permissions', ['permissions' => $inv]));
        $this->expectRejected($this->postAs('finance', '/api/uoms', ['code' => 'fi2'.substr(self::username(), -4), 'nameAr' => 'مرفوض']), 'FORBIDDEN', [403]);
    }

    public function test_nobody_can_lock_the_system_out_of_user_management(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $roles = fn () => collect($this->expectOk($this->getAs('admin', '/api/users')))->firstWhere('username', 'admin')['roles'];
        $before = $roles();

        // an administrator cannot deactivate their own account
        $this->expectRejected($this->patchAs('admin', "/api/users/{$admin->id}", ['active' => false]), 'USER_SELF_DEACTIVATE', [422]);
        $this->assertTrue((bool) $admin->fresh()->active);

        // admin is the only active account with user.manage: dropping the role is refused and rolled back
        $this->expectRejected($this->patchAs('admin', "/api/users/{$admin->id}", ['roles' => ['finance']]), 'LAST_USER_ADMIN', [422]);
        $this->assertSame($before, $roles());

        // …while adding a role to yourself stays possible
        $this->expectOk($this->patchAs('admin', "/api/users/{$admin->id}", ['roles' => [...$before, 'finance']]));
        $this->expectOk($this->patchAs('admin', "/api/users/{$admin->id}", ['roles' => $before]));
        $this->assertSame($before, $roles());
    }
}
