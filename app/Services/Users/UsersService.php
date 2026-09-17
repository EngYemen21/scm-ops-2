<?php

namespace App\Services\Users;

use App\Models\Permission;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserRole;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** User administration: accounts, their roles / warehouse scope, and the permission set of each role. */
class UsersService
{
    public function __construct(private readonly AuditService $audit) {}

    public function list(): array
    {
        return User::with(['roles.role', 'warehouses.warehouse'])->orderBy('username')->get()->map(fn (User $u) => [
            'id' => $u->id, 'username' => $u->username, 'email' => $u->email, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en, 'initials' => $u->initials,
            'active' => (bool) $u->active, 'lastLoginAt' => $u->last_login_at?->toJSON(),
            'roles' => $u->roles->map(fn ($r) => $r->role?->key)->filter()->values()->all(),
            'warehouses' => $u->warehouses->map(fn ($w) => $w->warehouse?->code)->filter()->values()->all(),
        ])->values()->all();
    }

    public function roles(): array
    {
        return Role::with('permissions.permission')->orderBy('key')->get()->map(fn (Role $r) => [
            'id' => $r->id, 'key' => $r->key, 'nameAr' => $r->name_ar, 'nameEn' => $r->name_en,
            'permissions' => $r->permissions->map(fn ($p) => $p->permission?->key)->filter()->values()->all(),
        ])->values()->all();
    }

    /** @param  array{username:string, password:string, nameAr:string, nameEn:string, email?:?string, roles:string[], warehouses?:?string[]}  $dto */
    public function create(AuthUser $actor, array $dto): array
    {
        $username = mb_strtolower(trim($dto['username']));
        if (User::where('username', $username)->exists()) {
            throw AppError::conflict('USERNAME_TAKEN', 'اسم المستخدم مستخدم', 'Username already exists');
        }
        $roles = Role::whereIn('key', $dto['roles'])->get();
        if ($roles->count() !== count($dto['roles'])) {
            throw AppError::validation('BAD_ROLE', 'دور غير معروف', 'Unknown role');
        }
        $warehouses = ! empty($dto['warehouses']) ? Warehouse::whereIn('code', $dto['warehouses'])->get() : collect();

        return DB::transaction(function () use ($actor, $dto, $username, $roles, $warehouses) {
            $u = User::create([
                'username' => $username, 'password_hash' => Hash::make($dto['password']), 'name_ar' => $dto['nameAr'], 'name_en' => $dto['nameEn'],
                'email' => ($dto['email'] ?? null) ?: null, 'initials' => mb_substr($dto['nameAr'], 0, 1), 'must_change_password' => true,
            ]);
            if ($roles->isNotEmpty()) {
                UserRole::insert($roles->map(fn (Role $r) => ['user_id' => $u->id, 'role_id' => $r->id])->all());
            }
            if ($warehouses->isNotEmpty()) {
                UserWarehouse::insert($warehouses->map(fn (Warehouse $w) => ['user_id' => $u->id, 'warehouse_id' => $w->id])->all());
            }
            $this->audit->log($actor, ['action' => 'USER.CREATE', 'entityType' => 'User', 'entityId' => $u->id, 'entityNumber' => $username,
                'newValue' => ['roles' => $dto['roles'], 'warehouses' => $dto['warehouses'] ?? null]]);

            return ['id' => $u->id, 'username' => $username];
        });
    }

    /** @param  array{nameAr?:string, nameEn?:string, email?:string, active?:bool, roles?:string[], warehouses?:string[], password?:string}  $dto */
    public function update(AuthUser $actor, string $id, array $dto): array
    {
        $u = User::with('roles.role')->find($id) ?? throw AppError::notFound('USER_NOT_FOUND', 'المستخدم غير موجود');
        $data = [];
        foreach (['nameAr' => 'name_ar', 'nameEn' => 'name_en', 'email' => 'email', 'active' => 'active'] as $k => $column) {
            if (isset($dto[$k])) {
                $data[$column] = $dto[$k];
            }
        }
        if (! empty($dto['password'])) {
            $data['password_hash'] = Hash::make($dto['password']);
            $data['must_change_password'] = true;
        }
        DB::transaction(function () use ($actor, $u, $id, $dto, $data) {
            if ($data) {
                $u->update($data);
            }
            if (isset($dto['roles'])) {
                $roles = Role::whereIn('key', $dto['roles'])->get();
                UserRole::where('user_id', $id)->delete();
                if ($roles->isNotEmpty()) {
                    UserRole::insert($roles->map(fn (Role $r) => ['user_id' => $id, 'role_id' => $r->id])->all());
                }
                $this->audit->log($actor, ['action' => 'USER.ROLES', 'entityType' => 'User', 'entityId' => $id, 'entityNumber' => $u->username,
                    'oldValue' => $u->roles->map(fn ($r) => $r->role?->key)->filter()->values()->all(), 'newValue' => $dto['roles']]);
            }
            if (isset($dto['warehouses'])) {
                $warehouses = Warehouse::whereIn('code', $dto['warehouses'])->get();
                UserWarehouse::where('user_id', $id)->delete();
                if ($warehouses->isNotEmpty()) {
                    UserWarehouse::insert($warehouses->map(fn (Warehouse $w) => ['user_id' => $id, 'warehouse_id' => $w->id])->all());
                }
            }
            if (($dto['active'] ?? null) === false) {
                // A deactivated account loses its sessions outright (no rotation grace).
                RefreshToken::where('user_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'expires_at' => now()]);
            }
            $logged = $dto;
            if (array_key_exists('password', $logged)) {
                $logged['password'] = $logged['password'] ? '***' : null;
            }
            $this->audit->log($actor, ['action' => 'USER.UPDATE', 'entityType' => 'User', 'entityId' => $id, 'entityNumber' => $u->username, 'newValue' => $logged]);
        });

        return ['ok' => true];
    }

    /** @param  string[]  $permissions */
    public function setRolePermissions(AuthUser $actor, string $roleKey, array $permissions): array
    {
        $role = Role::with('permissions.permission')->where('key', $roleKey)->first() ?? throw AppError::notFound('ROLE_NOT_FOUND', 'الدور غير موجود');
        if ($roleKey === 'super') {
            throw AppError::validation('SUPER_LOCKED', 'صلاحيات السوبر أدمن ثابتة', 'Super admin permissions are fixed');
        }
        $perms = Permission::whereIn('key', $permissions)->get();
        DB::transaction(function () use ($actor, $role, $roleKey, $permissions, $perms) {
            RolePermission::where('role_id', $role->id)->delete();
            if ($perms->isNotEmpty()) {
                RolePermission::insert($perms->map(fn (Permission $p) => ['role_id' => $role->id, 'permission_id' => $p->id])->all());
            }
            $this->audit->log($actor, ['action' => 'ROLE.PERMISSIONS', 'entityType' => 'Role', 'entityId' => $role->id, 'entityNumber' => $roleKey,
                'oldValue' => $role->permissions->map(fn ($p) => $p->permission?->key)->filter()->values()->all(), 'newValue' => $permissions]);
        });

        return ['ok' => true];
    }
}
