<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\AppError;
use App\Support\AuthUser;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Idempotency for mutations. When the client sends `Idempotency-Key`, the first successful response for
 * (user, key, route) is stored and replayed for any retry (double click, browser or network retry). A key reused
 * while the first request is still running gets 409. A failed request releases its key so it can be retried.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('Idempotency-Key');
        $user = AuthUser::currentOrNull();
        if (! $key || ! $user || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }
        $route = mb_substr($request->method().' '.($request->route()?->uri() ?? $request->path()), 0, 250);
        $where = ['user_id' => $user->id, 'key' => mb_substr($key, 0, 250), 'route' => $route];

        $existing = IdempotencyKey::where($where)->first();
        if ($existing) {
            return $this->replay($existing);
        }
        try {
            $record = IdempotencyKey::create($where);
        } catch (UniqueConstraintViolationException) { // lost the race with a concurrent identical request
            return $this->replay(IdempotencyKey::where($where)->firstOrFail());
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();
            throw $e;
        }
        if ($response->getStatusCode() >= 400 || isset($response->exception)) {
            $record->delete();

            return $response;
        }
        $body = $response instanceof JsonResponse ? $response->getData(true) : json_decode((string) $response->getContent(), true);
        $record->update(['response_status' => $response->getStatusCode(), 'response_body' => $body ?? []]);

        return $response;
    }

    private function replay(IdempotencyKey $record): Response
    {
        if ($record->response_status === null) {
            throw AppError::conflict('IDEMPOTENT_IN_PROGRESS', 'الطلب نفسه قيد التنفيذ — انتظر النتيجة', 'Same request in progress');
        }

        return response()->json($record->response_body, $record->response_status)->header('X-Idempotent-Replay', 'true');
    }
}
