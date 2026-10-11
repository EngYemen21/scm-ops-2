<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Keeps the access tables in step with `config/scm.php` when a release introduces new permissions.
 * Only permissions that are missing from the database are inserted, and only *those* receive their default role grants
 * (`ROLE_PERMISSIONS`) — grants an administrator removed or added by hand are never touched.
 */
class PermissionSync
{
    /** @return array{inserted: string[], grants: int} */
    public static function ensure(): array
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return ['inserted' => [], 'grants' => 0];
        }
        $existing = DB::table('permissions')->pluck('id', 'key');
        $inserted = [];
        foreach ((array) config('scm.PERMISSIONS') as $key) {
            if (! isset($existing[$key])) {
                $id = (string) Str::ulid();
                DB::table('permissions')->insert(['id' => $id, 'key' => $key, 'description' => $key]);
                $existing[$key] = $id;
                $inserted[] = $key;
            }
        }
        $grants = 0;
        if ($inserted) {
            $roles = DB::table('roles')->pluck('id', 'key');
            foreach ((array) config('scm.ROLE_PERMISSIONS') as $roleKey => $perms) {
                $roleId = $roles[$roleKey] ?? null;
                if (! $roleId) {
                    continue;
                }
                foreach (array_intersect($perms, $inserted) as $perm) {
                    DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $existing[$perm]]);
                    $grants++;
                }
            }
        }

        return ['inserted' => $inserted, 'grants' => $grants];
    }
}
