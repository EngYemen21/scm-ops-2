<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global. Every request carries a request id (client-supplied or generated); it is echoed back in X-Request-Id and
 * written to audit rows and error bodies. JSON responses are emitted with readable Arabic (no \uXXXX escapes).
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('requestId', $id);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        if ($response instanceof JsonResponse) {
            $response->setEncodingOptions($response->getEncodingOptions() | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $response;
    }
}
