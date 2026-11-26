<?php

namespace App\ProductRequests\Http;

use App\Models\AuditLog;
use App\Support\AppError;
use App\Support\AuthUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * `AnyPermission::of('preq.create', 'preq.review')` — the caller must hold AT LEAST ONE of the permissions (the shared
 * `perm:a,b` means all of them). Used where one call serves the person who raised a request and the people who review
 * it; the service then decides what each of them may do with THIS request.
 * A refusal is the same 403 as `perm:` and is written to the audit trail as a security event.
 */
class AnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = AuthUser::current();
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }
        AuditLog::create([
            'user_id' => $user->id, 'username' => $user->username, 'action' => 'SECURITY.UNAUTHORIZED', 'entity_type' => 'endpoint',
            'entity_id' => mb_substr($request->method().' /'.$request->path(), 0, 250), 'old_value' => implode(',', $user->roles),
            'new_value' => 'denied: none of '.implode(',', $permissions), 'request_id' => $user->requestId,
        ]);
        Log::warning("403 {$user->username} {$request->method()} /{$request->path()} needs one of ".implode(',', $permissions));
        $roles = implode('،', $user->roles);

        throw AppError::forbidden('FORBIDDEN', "مرفوض — الدور «{$roles}» غير مخوّل لهذا الإجراء", 'Forbidden — needs one of: '.implode(', ', $permissions));
    }

    /** `->middleware(AnyPermission::of('preq.create', 'preq.review'))` */
    public static function of(string ...$permissions): string
    {
        return self::class.':'.implode(',', $permissions);
    }
}
