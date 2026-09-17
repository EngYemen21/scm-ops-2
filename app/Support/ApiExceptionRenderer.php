<?php

namespace App\Support;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Maps every exception raised under /api to the single error contract of the API. */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        $requestId = $request->attributes->get('requestId');
        $status = 500;
        $body = ['category' => 'SYSTEM', 'code' => 'INTERNAL', 'message' => 'خطأ غير متوقع في النظام', 'messageEn' => 'Unexpected system error', 'requestId' => $requestId];

        if ($e instanceof AppError) {
            $status = $e->status();
            $body = $e->toBody($requestId);
        } elseif ($e instanceof ValidationException) {
            $status = 400;
            $details = [];
            foreach ($e->errors() as $path => $messages) {
                $details[] = ['path' => $path, 'message' => $messages[0] ?? 'Invalid'];
            }
            $body = ['category' => 'VALIDATION', 'code' => 'INVALID_INPUT', 'message' => 'بيانات غير صالحة', 'messageEn' => 'Invalid input', 'details' => $details, 'requestId' => $requestId];
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
            $body = ['category' => 'NOT_FOUND', 'code' => 'NOT_FOUND', 'message' => 'السجل غير موجود', 'messageEn' => 'Record not found', 'requestId' => $requestId];
        } elseif ($e instanceof UniqueConstraintViolationException) {
            $status = 409;
            $body = ['category' => 'CONFLICT', 'code' => 'DUPLICATE', 'message' => 'سجل مكرر — القيمة موجودة مسبقًا', 'messageEn' => 'Duplicate record', 'requestId' => $requestId];
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $category = match (true) {
                $status === 401 => 'UNAUTHORIZED', $status === 403 => 'FORBIDDEN', $status === 404 => 'NOT_FOUND', $status === 409 => 'CONFLICT',
                $status < 500 => 'VALIDATION', default => 'SYSTEM',
            };
            $message = $e->getMessage() ?: ($status === 404 ? 'المسار غير موجود' : 'طلب غير صالح');
            $body = ['category' => $category, 'code' => 'HTTP_'.$status, 'message' => $message, 'messageEn' => $e->getMessage() ?: 'HTTP '.$status, 'requestId' => $requestId];
        } else {
            Log::error('Unhandled '.$e::class.': '.$e->getMessage().' ['.$requestId.']', ['exception' => $e]);
            if (config('app.debug')) {
                $body['details'] = ['exception' => $e::class, 'error' => $e->getMessage(), 'at' => basename($e->getFile()).':'.$e->getLine()];
            }
        }
        if ($status >= 500) {
            Log::error("{$request->method()} /{$request->path()} -> {$status} [{$requestId}]");
        }

        return response()->json($body, $status);
    }
}
