<?php

namespace App\Integration\Services;

use App\Integration\Support\Signature;
use App\Integration\Support\Systems;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One integration cycle: retry due inbox events, deliver due outbox events. Entry points: `php artisan
 * scm:integration-run` (every minute under a scheduler) and the signed POST /api/v1/ops/heartbeat (Vercel — no
 * scheduler; called by a cron). Overlapping cycles are skipped, never run twice.
 */
class IntegrationRunner
{
    public const LAST_KEY = 'int:last-run';

    public function __construct(private readonly InboxService $inbox, private readonly Dispatcher $dispatcher, private readonly OrderIntakeService $orders) {}

    public function run(int $limit = 100, string $trigger = 'manual'): array
    {
        $lock = Cache::lock('int:run', 120);
        if (! $lock->get()) {
            return ['ran' => false, 'reason' => 'another run is in progress'];
        }
        try {
            $t0 = microtime(true);
            $out = ['ran' => true, 'trigger' => $trigger, 'inbox' => $this->inbox->processDue($limit),
                // orders from other systems waiting for stock: completed first-come-first-served when it is there
                'backordersCompleted' => $this->orders->topUp(null, $limit)];
            $out['deliveries'] = $this->dispatcher->processDue($limit); // includes the events the steps above produced
            $out['systems'] = $this->triggerSystemCycles(); // one scheduler drives every connected system
            $out['reconciliation'] = app(ReconciliationService::class)->runIfDue();
            $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
            $out['at'] = now()->toIso8601ZuluString('millisecond');
            Cache::put(self::LAST_KEY, $out, now()->addDays(7));

            return $out;
        } finally {
            $lock->release();
        }
    }

    /** Signed POST to each enabled system's cycle_url. @return array<string, array{status:?int, error?:string}> */
    private function triggerSystemCycles(): array
    {
        $out = [];
        foreach (Systems::all() as $code => $s) {
            $url = $s['cycle_url'] ?? null;
            $key = Systems::signingKey((string) $code);
            if (empty($s['enabled']) || ! $url || ! $key) {
                continue;
            }
            $parts = parse_url($url);
            $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
            try {
                $res = Http::timeout((int) config('integration.timeout_seconds', 5) * 4)
                    ->withHeaders(Signature::headers('ops', $key[0], $key[1], 'POST', $path, '') + ['Accept' => 'application/json'])
                    ->withBody('', 'application/json')->post($url);
                $out[$code] = ['status' => $res->status()] + ($res->successful() ? [] : ['error' => mb_substr((string) $res->body(), 0, 200)]);
            } catch (Throwable $e) {
                $out[$code] = ['status' => null, 'error' => mb_substr($e->getMessage(), 0, 200)];
            }
        }

        return $out;
    }
}
