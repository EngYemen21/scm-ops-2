<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Support\AppError;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * `perm:po.approve` (several: `perm:a,b` = all required). Server-side RBAC: a denied attempt changes no data,
 * returns 403 and is written to audit_logs as a security event.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = AuthUser::current();
        $missing = array_values(array_filter($permissions, fn ($p) => ! $user->can($p)));
        if ($missing) {
            AuditLog::create([
                'user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
                'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $user->roles),
                'new_value' => 'denied: '.implode(',', $missing), 'request_id' => $user->requestId,
            ]);
            Log::warning("403 {$user->username} {$request->method()} /{$request->path()} missing=".implode(',', $missing));
            $roles = implode('،', $user->roles);
            throw AppError::forbidden('FORBIDDEN', "مرفوض — الدور «{$roles}» غير مخوّل لإجراء {$missing[0]}", "Forbidden — missing permission {$missing[0]}");
        }

        return $next($request);
    }
}
