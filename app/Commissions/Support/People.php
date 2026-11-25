<?php

namespace App\Commissions\Support;

use App\Commissions\Models\CommissionStatement;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The people of the section: who may earn, how a person is named in the JSON (an OPS user, or a name kept from Odoo),
 * who sees whose statements, and how one of them is told.
 */
final class People
{
    /** Service accounts (`svc.<system>`) work on behalf of another system: they never earn a commission. */
    public const SERVICE_PREFIX = 'svc.';

    public const MANAGE = 'commission.manage';

    public const APPROVE = 'commission.approve';

    /** Plans, calculation and approval see every statement; anyone else sees his own. */
    public static function seesAll(AuthUser $user): bool
    {
        return $user->can(self::MANAGE) || $user->can(self::APPROVE);
    }

    /**
     * A step open to several permissions (any of them). The refusal is written to the audit trail like every 403 of the
     * API — call it BEFORE the transaction, so that line is not rolled back with the work it refused.
     */
    public static function needAny(AuthUser $user, string ...$permissions): void
    {
        foreach ($permissions as $p) {
            if ($user->can($p)) {
                return;
            }
        }
        $request = request();
        AuditLog::create([
            'user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
            'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $user->roles),
            'new_value' => 'denied: '.implode('|', $permissions), 'request_id' => $user->requestId,
        ]);
        Log::warning("403 {$user->username} {$request->method()} /{$request->path()} missing=".implode('|', $permissions));
        $roles = implode('،', $user->roles);
        throw AppError::forbidden('FORBIDDEN', "مرفوض — الدور «{$roles}» غير مخوّل لإجراء {$permissions[0]}", "Forbidden — missing permission {$permissions[0]}");
    }

    /**
     * The users a plan, an assignment or a calculation may name: active, not a service account.
     *
     * @return array<int, array{id:string, username:string, nameAr:string, nameEn:string}>
     */
    public static function selectable(): array
    {
        return DB::table('users')->where('active', true)->where('username', 'not like', self::SERVICE_PREFIX.'%')->orderBy('name_ar')->orderBy('id')
            ->get(['id', 'username', 'name_ar', 'name_en'])
            ->map(fn ($u) => ['id' => (string) $u->id, 'username' => (string) $u->username, 'nameAr' => (string) $u->name_ar, 'nameEn' => (string) $u->name_en])->all();
    }

    /** A person who may earn: an existing, active user that is not a service account. */
    public static function earner(string $idOrUsername, string $field): User
    {
        $u = User::where('id', $idOrUsername)->orWhere('username', $idOrUsername)->first();
        if (! $u || ! $u->active || str_starts_with((string) $u->username, self::SERVICE_PREFIX)) {
            throw AppError::validation('COMMISSION_USER_UNKNOWN', 'المستخدم المختار غير موجود أو غير نشط', 'The chosen user does not exist or is not active', [['path' => $field, 'message' => 'المستخدم غير موجود أو غير نشط']]);
        }

        return $u;
    }

    /**
     * @param  array<int, ?string>  $ids
     * @return array<string, object> users by id (id, username, name_ar, name_en)
     */
    public static function byId(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids ? DB::table('users')->whereIn('id', $ids)->get(['id', 'username', 'name_ar', 'name_en'])->keyBy('id')->all() : [];
    }

    /**
     * A person in the JSON: an OPS user, or a name kept from Odoo. `key` is what the lists filter by (the user id, or
     * `name:<the name>`).
     *
     * @return array{key:string, id:?string, username:?string, nameAr:string, nameEn:string, external:bool}|null
     */
    public static function shape(?string $userId, ?object $user, ?string $name): ?array
    {
        if ($userId) {
            return ['key' => $userId, 'id' => $userId, 'username' => $user?->username, 'nameAr' => $user?->name_ar ?? $name ?? '—', 'nameEn' => $user?->name_en ?? $name ?? '—', 'external' => false];
        }
        if ($name === null || $name === '') {
            return null;
        }

        return ['key' => 'name:'.$name, 'id' => null, 'username' => null, 'nameAr' => $name, 'nameEn' => $name, 'external' => true];
    }

    /** The keys of the roles that hold a permission (who is told that something waits for them). @return string[] */
    public static function rolesWith(string $permission): array
    {
        return DB::table('roles as r')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.key', $permission)->where('r.key', '!=', 'super')->orderBy('r.key')->pluck('r.key')->all();
    }

    /** A personal notification (that user's bell) about a statement; never to the person who did the thing. */
    public static function tell(?string $userId, AuthUser $by, CommissionStatement $s, string $ar, string $en): void
    {
        if (! $userId || $userId === $by->id) {
            return;
        }
        Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => 'CommissionStatement', 'entity_id' => $s->id, 'entity_number' => $s->number, 'at' => now()]);
    }
}
