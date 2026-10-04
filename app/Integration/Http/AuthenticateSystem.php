<?php

namespace App\Integration\Http;

use App\Integration\Support\Signature;
use App\Integration\Support\Systems;
use App\Support\AppError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `int.system[:scope]` — authenticates another SYSTEM (never a person) on /api/v1 by its HMAC request signature
 * (App\Integration\Support\Signature), then checks the scope, rate limit and body size. The authenticated system code
 * is put on the request as attribute `intSystem`; an `X-Correlation-Id` header is echoed back.
 */
class AuthenticateSystem
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $system = (string) $request->headers->get(Signature::H_SYSTEM, '');
        $keyId = (string) $request->headers->get(Signature::H_KEY, '');
        $ts = (string) $request->headers->get(Signature::H_TIME, '');
        $sig = strtolower((string) $request->headers->get(Signature::H_SIG, ''));
        $deny = function (string $code, string $why) use ($request, $system) {
            Log::warning("integration auth refused ({$code}) system=".($system ?: '-').' '.$request->method().' '.$request->getRequestUri()." ip={$request->ip()}: {$why}");

            return AppError::unauthorized($code, 'توقيع النظام غير صالح', 'System signature rejected: '.$why);
        };
        if ($system === '' || $keyId === '' || $ts === '' || $sig === '') {
            throw $deny('SIGNATURE_MISSING', 'X-B2B-System, X-B2B-Key-Id, X-B2B-Timestamp and X-B2B-Signature are required');
        }
        if (! Systems::get($system) || ! Systems::enabled($system)) {
            throw $deny('SYSTEM_UNKNOWN', 'unknown or disabled system');
        }
        $secret = Systems::keys($system)[$keyId] ?? null;
        if (! $secret) {
            throw $deny('KEY_UNKNOWN', 'unknown key id');
        }
        if (! ctype_digit($ts) || abs(now()->getTimestamp() - (int) $ts) > (int) config('integration.max_skew_seconds', 300)) {
            throw $deny('SIGNATURE_EXPIRED', 'timestamp outside the allowed window');
        }
        $body = (string) $request->getContent();
        if (strlen($body) > (int) config('integration.max_body_bytes', 1048576)) {
            throw AppError::validation('BODY_TOO_LARGE', 'حجم الطلب أكبر من المسموح', 'Request body too large');
        }
        $expected = Signature::sign($secret, $ts, $request->method(), $request->getRequestUri(), $body);
        if (! hash_equals($expected, $sig)) {
            throw $deny('SIGNATURE_INVALID', 'signature does not match');
        }
        if ($scope && ! Systems::hasScope($system, $scope)) {
            throw AppError::forbidden('SCOPE_MISSING', 'النظام غير مصرّح له بهذه العملية', "System {$system} lacks scope {$scope}");
        }
        $limit = (int) (Systems::get($system)['rate_per_minute'] ?? 600);
        if (! RateLimiter::attempt("int:rate:{$system}:{$keyId}", $limit, fn () => true, 60)) {
            throw AppError::rateLimited('RATE_LIMITED', 'تجاوز النظام حد الطلبات', 'Rate limit exceeded', ['retryAfter' => RateLimiter::availableIn("int:rate:{$system}:{$keyId}")]);
        }
        $request->attributes->set('intSystem', $system);
        $request->attributes->set('intKeyId', $keyId);
        $response = $next($request);
        if ($corr = $request->headers->get('X-Correlation-Id')) {
            $response->headers->set('X-Correlation-Id', $corr);
        }

        return $response;
    }
}
