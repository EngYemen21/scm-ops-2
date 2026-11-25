<?php

namespace App\Portal\Support;

use App\Models\AuditLog;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Log;

/**
 * Steps of the section that reach into another module need that module's permission as well: a NEW customer made by
 * approving an application needs `customer.manage`, a quotation made from a favourite list or an order request needs
 * `sales.manage`. The route has already checked `portal.manage`; this is the second key.
 */
final class Access
{
    /**
     * Call it BEFORE the transaction: the refusal is written to the audit trail like every 403 of the API, and that
     * line must not be rolled back with the work it refused.
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
