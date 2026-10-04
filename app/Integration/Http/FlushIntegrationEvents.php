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
 *    cycles, reconciliation) is run opportunistically, at most once per `integration.opportunistic_seconds`, after a
 *    request that changes something or comes from another system — the same idea as the live-tracking sync. With a
 *    scheduler or the signed heartbeat the cycle simply runs more regularly.
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
            if ($this->cycleIsDue($request)) {
                app(IntegrationRunner::class)->run(50, 'opportunistic');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function cycleIsDue(Request $request): bool
    {
        $every = (int) config('integration.opportunistic_seconds', 0);
        if ($every <= 0 || ! $request->is('api/*') || $request->is('api/v1/ops/heartbeat')) {
            return false;
        }
        $relevant = ! $request->isMethodSafe() || $request->is('api/v1/*') || $request->is('api/integration/overview');
        if (! $relevant || ! collect(Systems::all())->contains(fn ($s) => ! empty($s['enabled']))) {
            return false;
        }

        return Cache::add('int:opportunistic', 1, $every); // atomic throttle: true only for the first caller in the window
    }
}
