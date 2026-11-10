<?php

namespace App\Integration\Http;

use App\Integration\Services\Dispatcher;
use App\Integration\Services\EventPublisher;
use App\Integration\Services\IntegrationRunner;
use App\Integration\Support\Systems;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global, terminable.
 *
 * 1. The deliveries created by events published during this request are attempted right after the response is built
 *    (near real-time path). A failure only schedules a retry — it can never affect the user's action, already committed.
 * 2. Hosts without a scheduler (Vercel): a full integration cycle (retries, waiting backorders, the other systems'
 *    cycles, reconciliation) is run opportunistically, at most once per `integration.opportunistic_seconds`, on a
 *    request NOBODY IS WAITING FOR: the notification poll every open screen sends in the background once a minute.
 *    On serverless PHP terminate() finishes before the response reaches the client, and a cycle takes 7–10 s (it also
 *    runs the other system's cycle). Measured in production on 2026-11-09: 7.9 s for the request that carried a cycle
 *    against 0.6 s without one — attached to a user's save it froze that action; attached to a call from another
 *    system it outlived the caller's 5 s timeout. So user actions and system calls never carry a cycle. The tower's
 *    «تشغيل دورة», a scheduler and the signed heartbeat run the same cycle on demand.
 */
class FlushIntegrationEvents
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $ids = EventPublisher::takeFresh();
            if ($ids) {
                app(Dispatcher::class)->processDue(count($ids), $ids);
            }
            if ($this->cycleIsDue($request, $response)) {
                app(IntegrationRunner::class)->run(50, 'opportunistic');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** True for the first successful background notification poll of a signed-in user in each window. */
    private function cycleIsDue(Request $request, Response $response): bool
    {
        $every = (int) config('integration.opportunistic_seconds', 0);
        if ($every <= 0 || ! $request->isMethod('GET') || ! $request->is('api/notifications') || ! $response->isSuccessful()) {
            return false;
        }
        if (! collect(Systems::all())->contains(fn ($s) => ! empty($s['enabled']))) {
            return false;
        }

        return Cache::add('int:opportunistic', 1, $every); // atomic throttle: true only for the first caller in the window
    }
}
