<?php

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\NormalizeApiQuery;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Support\ApiExceptionRenderer;
use App\Support\AppError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->api(prepend: [NormalizeApiQuery::class]);
        $middleware->alias([
            'auth.api' => AuthenticateApi::class,
            'perm' => RequirePermission::class,
            'idempotent' => Idempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // One error shape for the whole API: { category, code, message, messageEn, details, requestId }.
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiExceptionRenderer::render($e, $request) : null);
        $exceptions->dontReport(AppError::class);
    })->create();
