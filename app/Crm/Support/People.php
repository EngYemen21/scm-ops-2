<?php

namespace App\Crm\Support;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** The people of the pipeline: who may own a lead or a to-do, how they are named, and how one of them is told. */
final class People
{
    /** Service accounts (`svc.<system>`) work on behalf of another system: they never own a lead or a to-do. */
    public const SERVICE_PREFIX = 'svc.';

    /**
     * The users who work the pipeline — active holders of `crm.manage` — for the pickers of the forms.
     *
     * @return array<int, array{id:string, username:string, nameAr:string, nameEn:string}>
     */
    public static function salespeople(): array
    {
        return DB::table('users as u')
            ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.key', 'crm.manage')->where('u.active', true)->where('u.username', 'not like', self::SERVICE_PREFIX.'%')
            ->distinct()->orderBy('u.name_ar')->orderBy('u.id')
            ->get(['u.id', 'u.username', 'u.name_ar', 'u.name_en'])
            ->map(fn ($u) => ['id' => (string) $u->id, 'username' => (string) $u->username, 'nameAr' => (string) $u->name_ar, 'nameEn' => (string) $u->name_en])->all();
    }

    /** A person who may be given a lead or a to-do: an existing, active user that is not a service account. */
    public static function assignable(string $idOrUsername, string $field): User
    {
        $u = User::where('id', $idOrUsername)->orWhere('username', $idOrUsername)->first();
        if (! $u || ! $u->active || str_starts_with((string) $u->username, self::SERVICE_PREFIX)) {
            throw AppError::validation('CRM_USER_UNKNOWN', 'المستخدم المختار غير موجود أو غير نشط', 'The chosen user does not exist or is not active', [['path' => $field, 'message' => 'المستخدم غير موجود أو غير نشط']]);
        }

        return $u;
    }

    /** @return array{id:string, username:string, nameAr:string, nameEn:string}|null */
    public static function shape(?User $u): ?array
    {
        return $u ? ['id' => (string) $u->id, 'username' => (string) $u->username, 'nameAr' => (string) $u->name_ar, 'nameEn' => (string) $u->name_en] : null;
    }

    /** A personal notification (the bell of that user) about a lead; never to the person who did the thing. */
    public static function tell(?string $userId, AuthUser $by, string $leadId, string $leadNumber, string $ar, string $en): void
    {
        if (! $userId || $userId === $by->id) {
            return;
        }
        Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => 'CrmLead', 'entity_id' => $leadId, 'entity_number' => $leadNumber, 'at' => now()]);
    }

    /**
     * A step that reaches into another module needs that module's permission as well (a new customer: `customer.manage`,
     * a quotation: `sales.manage`). Call it BEFORE the transaction: the refusal is written to the audit trail like every
     * 403 of the API, and that line must not be rolled back with the work it refused.
     */
    public static function need(AuthUser $user, string $permission, string $whatAr, string $whatEn): void
    {
        if ($user->can($permission)) {
            return;
        }
        $request = request();
        AuditLog::create([
            'user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
            'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $user->roles),
            'new_value' => 'denied: '.$permission, 'request_id' => $user->requestId,
        ]);
        Log::warning("403 {$user->username} {$request->method()} /{$request->path()} missing={$permission}");
        throw AppError::forbidden('FORBIDDEN', "مرفوض — {$whatAr} يحتاج الصلاحية {$permission}", "Forbidden — {$whatEn} needs the permission {$permission}");
    }
}
