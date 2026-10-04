<?php

namespace App\Integration\Services;

use Illuminate\Support\Facades\Cache;

/**
 * One integration cycle: retry due inbox events, deliver due outbox events. Entry points: `php artisan
 * scm:integration-run` (every minute under a scheduler) and the signed POST /api/v1/ops/heartbeat (Vercel — no
 * scheduler; called by a cron). Overlapping cycles are skipped, never run twice.
 */
class IntegrationRunner
{
    public const LAST_KEY = 'int:last-run';

    public function __construct(private readonly InboxService $inbox, private readonly Dispatcher $dispatcher) {}

    public function run(int $limit = 100, string $trigger = 'manual'): array
    {
        $lock = Cache::lock('int:run', 120);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'another run is in progress'];
        }
        try {
            $t0 = microtime(true);
            $out = ['ran' => true, 'trigger' => $trigger, 'inbox' => $this->inbox->processDue($limit), 'deliveries' => $this->dispatcher->processDue($limit)];
            $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
            $out['at'] = now()->toIso8601ZuluString('millisecond');
            Cache::put(self::LAST_KEY, $out, now()->addDays(7));

            return $out;
        } finally {
            $lock->release();
        }
    }
}
