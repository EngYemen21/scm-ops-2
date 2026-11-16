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

    /**
     * EXISTING permissions a release decided a role now needs (`ensure()` only grants the permissions it has just
     * inserted). Applied once: by the release's migration on a live database, by the seeder on a fresh one — never on
     * every sync, so a grant an administrator removes afterwards stays removed.
     */
    public const GRANTS_2026_11 = [
        'proc' => ['return.create', 'page.returns'],   // purchase returns are raised by procurement
        'sales' => ['so.allocate'],                    // retry the reservation of an order waiting for stock
        'wm' => ['so.allocate'],
        'gm' => ['page.sales'],                        // opens the quotation whose discount he approves (discount.approve); no sales.manage
    ];

    /** @param  array<string, string[]>  $byRole  role key → permission keys. @return int grants added */
    public static function grant(array $byRole): int
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return 0;
        }
        $roles = DB::table('roles')->pluck('id', 'key');
        $perms = DB::table('permissions')->pluck('id', 'key');
        $added = 0;
        foreach ($byRole as $role => $keys) {
            foreach ($keys as $key) {
                if (! isset($roles[$role], $perms[$key])) {
                    continue;
                }
                if (! DB::table('role_permissions')->where('role_id', $roles[$role])->where('permission_id', $perms[$key])->exists()) {
                    DB::table('role_permissions')->insert(['role_id' => $roles[$role], 'permission_id' => $perms[$key]]);
                    $added++;
                }
            }
        }

        return $added;
    }
}
