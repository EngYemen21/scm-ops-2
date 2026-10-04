<?php

namespace App\Integration\Services;

use App\Integration\Models\IntDelivery;
use App\Integration\Support\Clock;
use App\Integration\Support\Signature;
use App\Integration\Support\Systems;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers outbox events to subscribing systems (docs/integration/ARCHITECTURE.md §8).
 *
 * - Each delivery is claimed with a short lease (an atomic UPDATE), so concurrent runs never send the same delivery
 *   twice at the same time; the receiver still deduplicates by event id (at-least-once delivery, exactly-once effect).
 * - Failure → exponential back-off from config('integration.backoff'); past the last delay → `dead` + an exception.
 * - A rejection the sender cannot fix by retrying (400/404/409/410/413/422) goes dead at once.
 * - Circuit breaker per subscriber: after N consecutive failures no call is made for a while; deliveries just wait.
 */
class Dispatcher
{
    private const LEASE_SECONDS = 60;

    private const NOT_RETRYABLE = [400, 404, 409, 410, 413, 422];

    public function __construct(private readonly IntegrationExceptions $exceptions, private readonly AuditService $audit) {}

    /** @param string[]|null $onlyIds @return array{attempted:int, sent:int, failed:int, dead:int, waiting:int} */
    public function processDue(int $limit = 50, ?array $onlyIds = null): array
    {
        $stats = ['attempted' => 0, 'sent' => 0, 'failed' => 0, 'dead' => 0, 'waiting' => 0];
        $due = IntDelivery::whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Clock::sql()))
            ->when($onlyIds !== null, fn ($q) => $q->whereIn('id', $onlyIds))
            ->orderBy('next_attempt_at')->orderBy('created_at')->limit(max(1, min($limit, 500)))->get();
        foreach ($due as $d) {
            $outcome = $this->attempt($d);
            if ($outcome === 'skipped') {
                continue;
            }
            $stats['attempted'] += $outcome === 'waiting' ? 0 : 1;
            $stats[$outcome]++;
        }

        return $stats;
    }

    /** One attempt. @return string sent | failed | dead | waiting | skipped (claimed by someone else) */
    public function attempt(IntDelivery $d): string
    {
        // lease: only one runner may work on this delivery now
        $claimed = IntDelivery::whereKey($d->id)->whereIn('status', ['pending', 'failed'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Clock::sql()))
            ->update(['next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS)]);
        if (! $claimed) {
            return 'skipped';
        }
        $d->refresh();
        $system = Systems::get($d->subscriber);
        $key = Systems::signingKey($d->subscriber);
        if (! $system || empty($system['enabled']) || empty($system['deliver_url']) || ! $key) {
            // not reachable as configured: keep it, say why, look again later — never count it as an attempt
            $d->update(['last_error' => 'SUBSCRIBER_NOT_CONFIGURED', 'next_attempt_at' => now()->addMinutes(10)]);

            return 'waiting';
        }
        if ($this->breakerOpen($d->subscriber)) {
            $d->update(['last_error' => 'CIRCUIT_OPEN', 'next_attempt_at' => now()->addSeconds((int) config('integration.breaker_open_seconds', 300))]);

            return 'waiting';
        }
        $event = $d->event;
        if (! $event) {
            $d->update(['status' => 'dead', 'last_error' => 'EVENT_MISSING']);

            return 'dead';
        }
        $body = json_encode(EventPublisher::envelope($event), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $url = (string) $system['deliver_url'];
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $headers = Signature::headers('ops', $key[0], $key[1], 'POST', $path, $body) + [
            'Content-Type' => 'application/json', 'X-Correlation-Id' => (string) $event->correlation_id, 'X-Request-Id' => $d->id,
        ];
        $t0 = microtime(true);
        $status = null;
        $error = null;
        try {
            $res = Http::timeout((int) config('integration.timeout_seconds', 5))->withHeaders($headers)->withBody($body, 'application/json')->post($url);
            $status = $res->status();
            if (! $res->successful()) {
                $error = "HTTP {$status} ".mb_substr(trim((string) $res->body()), 0, 300);
            }
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $attempts = $d->attempts + 1;
        if ($error === null) {
            $d->update(['status' => 'sent', 'attempts' => $attempts, 'last_status' => $status, 'last_error' => null, 'response_ms' => $ms, 'sent_at' => now(), 'next_attempt_at' => null]);
            $this->breakerSuccess($d->subscriber);

            return 'sent';
        }
        $backoff = (array) config('integration.backoff', []);
        $dead = in_array($status, self::NOT_RETRYABLE, true) || $attempts > count($backoff);
        $this->breakerFailure($d->subscriber);
        if ($dead) {
            $d->update(['status' => 'dead', 'attempts' => $attempts, 'last_status' => $status, 'last_error' => $error, 'response_ms' => $ms, 'next_attempt_at' => null]);
            $this->exceptions->raise('DELIVERY_DEAD', "تعذّر تسليم الحدث {$event->type} إلى {$d->subscriber}: {$error}", [
                'system' => $d->subscriber, 'entity' => 'event', 'entityRef' => $event->id, 'correlationId' => $event->correlation_id,
                'eventId' => $event->id, 'severity' => 'crit', 'details' => ['delivery' => $d->id, 'attempts' => $attempts, 'status' => $status],
            ]);
            Log::warning("integration: delivery {$d->id} of {$event->type} to {$d->subscriber} is dead after {$attempts} attempts: {$error}");

            return 'dead';
        }
        $delay = (int) $backoff[$attempts - 1];
        $delay += random_int(0, max(1, intdiv($delay, 10))); // jitter: retries of many deliveries do not arrive together
        $d->update(['status' => 'failed', 'attempts' => $attempts, 'last_status' => $status, 'last_error' => $error, 'response_ms' => $ms, 'next_attempt_at' => now()->addSeconds($delay)]);

        return 'failed';
    }

    /** Manual retry / replay from the Control Tower: back to pending, due now, attempts kept for the record. */
    public function retry(AuthUser $user, string $deliveryId): IntDelivery
    {
        $d = IntDelivery::find($deliveryId) ?? throw AppError::notFound('DELIVERY_NOT_FOUND', 'التسليم غير موجود', 'Delivery not found');
        if ($d->status === 'pending') {
            throw AppError::rule('DELIVERY_PENDING', 'التسليم بانتظار المحاولة أصلًا', 'Delivery is already pending');
        }
        $from = $d->status;
        $d->update(['status' => 'pending', 'next_attempt_at' => now(), 'last_error' => $d->status === 'sent' ? 'replay' : $d->last_error]);
        $this->audit->log($user, ['action' => 'INTEGRATION.DELIVERY_RETRY', 'entityType' => 'int_delivery', 'entityId' => $d->id,
            'entityNumber' => $d->subscriber, 'oldValue' => $from, 'newValue' => 'pending']);
        $this->attempt($d->refresh());

        return $d->refresh();
    }

    public function breakerState(string $subscriber): array
    {
        $s = Cache::get("int:breaker:{$subscriber}", ['fails' => 0, 'openUntil' => null]);

        return ['failures' => (int) $s['fails'], 'open' => $this->breakerOpen($subscriber), 'openUntil' => $s['openUntil'] ? date(DATE_ATOM, $s['openUntil']) : null];
    }

    private function breakerOpen(string $subscriber): bool
    {
        $s = Cache::get("int:breaker:{$subscriber}");

        return $s && ($s['openUntil'] ?? 0) > now()->getTimestamp();
    }

    private function breakerFailure(string $subscriber): void
    {
        $s = Cache::get("int:breaker:{$subscriber}", ['fails' => 0, 'openUntil' => null]);
        $s['fails']++;
        // half-open (the pause is over, this was the trial call): one failure re-opens at once
        $halfOpen = ($s['openUntil'] ?? null) !== null && $s['openUntil'] <= now()->getTimestamp();
        if ($halfOpen || $s['fails'] >= (int) config('integration.breaker_failures', 5)) {
            $s['openUntil'] = now()->getTimestamp() + (int) config('integration.breaker_open_seconds', 300);
            $s['fails'] = 0;
        }
        Cache::put("int:breaker:{$subscriber}", $s, now()->addDay());
    }

    private function breakerSuccess(string $subscriber): void
    {
        Cache::forget("int:breaker:{$subscriber}");
    }
}
