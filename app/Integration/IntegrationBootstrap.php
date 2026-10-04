<?php

namespace App\Integration;

use App\Integration\Support\Systems;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Idempotent set-up of what the integration layer needs in the access-control tables: its two permissions (granted to
 * super; gm may look) and one service user per connected system (role `integration`). A service user cannot sign in
 * (its password is random and never stored anywhere) — it only exists so that work done on behalf of another system
 * is attributed to that system in audit logs, status history and documents ("svc.sales").
 *
 * Called by the migration (existing databases) and by the seeder (a fresh import replaces the access tables).
 */
final class IntegrationBootstrap
{
    public const PERMISSIONS = ['integration.view' => 'Integration Control Tower — view', 'integration.manage' => 'Integration Control Tower — retry, replay, map, resolve'];

    public const GRANTS = ['super' => ['integration.view', 'integration.manage'], 'gm' => ['integration.view']];

    /** What a connected system may do in OPS through its service user. */
    public const SERVICE_PERMISSIONS = ['customer.manage', 'sales.manage', 'so.reserve', 'so.allocate', 'so.cancel', 'consol.manage'];

    public static function ensure(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles')) {
            return;
        }
        $permId = fn (string $key) => DB::table('permissions')->where('key', $key)->value('id');
        foreach (self::PERMISSIONS as $key => $description) {
            if (! $permId($key)) {
                DB::table('permissions')->insert(['id' => (string) Str::ulid(), 'key' => $key, 'description' => $description]);
            }
        }
        $roleId = function (string $key, ?string $ar = null, ?string $en = null) {
            $id = DB::table('roles')->where('key', $key)->value('id');
            if (! $id && $ar) {
                $id = (string) Str::ulid();
                DB::table('roles')->insert(['id' => $id, 'key' => $key, 'name_ar' => $ar, 'name_en' => $en, 'is_system' => true]);
            }

            return $id;
        };
        $grant = function (?string $role, string $perm) use ($permId) {
            $p = $permId($perm);
            if ($role && $p && ! DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $p)->exists()) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $p]);
            }
        };
        foreach (self::GRANTS as $role => $perms) {
            foreach ($perms as $perm) {
                $grant($roleId($role), $perm);
            }
        }
        $svcRole = $roleId('integration', 'تكامل الأنظمة', 'System integration');
        foreach (self::SERVICE_PERMISSIONS as $perm) {
            $grant($svcRole, $perm);
        }
        foreach (array_keys(Systems::all()) as $code) {
            if ($code === 'scheduler') {
                continue;
            }
            $username = 'svc.'.$code;
            $userId = DB::table('users')->where('username', $username)->value('id');
            if (! $userId) {
                $userId = (string) Str::ulid();
                $name = (string) (Systems::get($code)['name'] ?? $code);
                DB::table('users')->insert([
                    'id' => $userId, 'username' => $username, 'password_hash' => Hash::make(Str::random(64)),
                    'name_ar' => 'تكامل — '.$name, 'name_en' => 'Integration — '.$name, 'initials' => 'IN',
                    'active' => true, 'must_change_password' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if (! DB::table('user_roles')->where('user_id', $userId)->where('role_id', $svcRole)->exists()) {
                DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $svcRole]);
            }
        }
    }

    /** The actor for work done on behalf of $system (its service user), carrying the request / correlation id. */
    public static function actor(string $system, ?string $requestId = null): AuthUser
    {
        $u = DB::table('users')->where('username', 'svc.'.$system)->first();
        if (! $u) {
            self::ensure();
            $u = DB::table('users')->where('username', 'svc.'.$system)->first();
        }
        $perms = DB::table('user_roles')->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->where('user_roles.user_id', $u->id)
            ->pluck('permissions.key')->unique()->values()->all();

        return new AuthUser(id: $u->id, username: $u->username, nameAr: $u->name_ar, nameEn: $u->name_en, roles: ['integration'],
            permissions: $perms, warehouses: [], driverId: null, requestId: $requestId);
    }
}
