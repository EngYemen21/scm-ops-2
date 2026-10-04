<?php

use App\Http\Middleware\AuthenticateApi;
use App\Integration\Http\AuthenticateSystem;
use App\Integration\Http\FlushIntegrationEvents;
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

// Serverless hosts (Vercel) give a read-only filesystem except /tmp, and sit behind the platform's own proxy.
$serverless = (bool) ($_SERVER['VERCEL'] ?? $_ENV['VERCEL'] ?? getenv('VERCEL'));

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($serverless): void {
        if ($serverless) {
            $middleware->trustProxies(at: '*'); // only the platform proxy can reach the function: https + client IP come from it
        }
        $middleware->prepend(RequestId::class);
        $middleware->append(FlushIntegrationEvents::class); // terminable: delivers this request's integration events
        $middleware->api(prepend: [NormalizeApiQuery::class]);
        $middleware->alias([
            'auth.api' => AuthenticateApi::class,
            'perm' => RequirePermission::class,
            'idempotent' => Idempotency::class,
            'int.system' => AuthenticateSystem::class,
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

if ($serverless) {
    $storage = '/tmp/storage';
    foreach (['/app', '/framework/cache/data', '/framework/sessions', '/framework/views', '/logs'] as $dir) {
        is_dir($storage.$dir) || @mkdir($storage.$dir, 0777, true);
    }
    $app->useStoragePath($storage);
}

return $app;
