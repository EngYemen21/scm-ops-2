<?php

namespace App\Integration\Http;

use App\Integration\Services\Dispatcher;
use App\Integration\Services\EventPublisher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global, terminable: the deliveries created by events published during this request are attempted right after the
 * response (near real-time path). A failure here only schedules a retry — it can never affect the user's action, which
 * is already committed. The scheduled run / heartbeat repairs anything left.
 */
class FlushIntegrationEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $ids = EventPublisher::takeFresh();
        if (! $ids) {
            return;
        }
        try {
            app(Dispatcher::class)->processDue(count($ids), $ids);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
