<?php

namespace App\Integration\Services;

use App\Integration\Handlers\Blocked;
use App\Integration\Handlers\EventHandler;
use App\Integration\Handlers\Rejected;
use App\Integration\IntegrationBootstrap;
use App\Integration\Models\IntInbox;
use App\Integration\Support\Clock;
use App\Integration\Support\Systems;
use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives events from other systems (docs/integration/ARCHITECTURE.md §6–§8).
 *
 * accept():  validates the envelope, stores it once (unique event id → a resent event answers `duplicate` and changes
 *            nothing) and processes it immediately.
 * process(): one DB transaction per event — the subject's state row is locked, an event older than the last one applied
 *            for that subject is recorded as `stale` and skipped (out-of-order arrival), else the handler runs and the
 *            subject's sequence advances with it.
 * Outcomes:  processed | stale | blocked (parked until e.g. a mapping exists) | rejected (never retried) |
 *            failed (retried with back-off) | dead (retries exhausted → exception for a human).
 */
class InboxService
{
    public const SUPPORTED_SCHEMA_VERSIONS = [1];

    private const LEASE_SECONDS = 60;

    /** Parked events are looked at again at least this often, even without a trigger. */
    private const BLOCKED_RECHECK_MINUTES = 60;

    public function __construct(private readonly IntegrationExceptions $exceptions, private readonly AuditService $audit) {}

    /** @return array{eventId:?string, status:string, code?:string, message?:string, result?:array} */
    public function accept(string $system, array $env, ?string $requestId = null): array
    {
        $id = is_string($env['id'] ?? null) ? trim($env['id']) : '';
        $problem = $this->validate($system, $env);
        if ($problem) {
            [$code, $message] = $problem;
            $this->exceptions->raise('EVENT_REJECTED', "حدث مرفوض من {$system}: {$message}", [
                'system' => $system, 'entity' => 'event', 'entityRef' => $id ?: null, 'eventId' => $id ?: null,
                'correlationId' => is_string($env['correlationId'] ?? null) ? $env['correlationId'] : null, 'details' => ['code' => $code, 'type' => $env['type'] ?? null],
            ]);

            return ['eventId' => $id ?: null, 'status' => 'rejected', 'code' => $code, 'message' => $message];
        }
        try {
            $row = IntInbox::create([
                'event_id' => $id, 'source' => $system, 'type' => $env['type'], 'subject' => (string) $env['subject'],
                'sequence' => isset($env['sequence']) ? (int) $env['sequence'] : null, 'correlation_id' => $env['correlationId'] ?? $env['subject'],
                'causation_id' => $env['causationId'] ?? null, 'schema_version' => (int) ($env['schemaVersion'] ?? 1),
                'event_time' => isset($env['time']) ? CarbonImmutable::parse($env['time'])->utc() : null,
                'data' => (array) ($env['data'] ?? []), 'status' => 'received', 'attempts' => 0, 'request_id' => $requestId, 'received_at' => now(),
                // if this request dies before processing finishes, the run picks the event up after the lease
                'next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS),
            ]);
        } catch (UniqueConstraintViolationException) {
            $prior = IntInbox::where('event_id', $id)->first();

            return ['eventId' => $id, 'status' => 'duplicate', 'code' => 'DUPLICATE_EVENT', 'message' => 'already received: '.($prior?->status ?? '?')];
        }
        $row = $this->process($row);

        return array_filter(['eventId' => $id, 'status' => $row->status, 'code' => $row->last_code, 'message' => $row->last_error, 'result' => $row->result], fn ($v) => $v !== null);
    }

    /** Runs the handler for one stored event (first time, retry, replay). */
    public function process(IntInbox $row): IntInbox
    {
        // event type → handler (config/integration.php `handlers`); a type without one is rejected and the sender told
        $handlerClass = config('integration.handlers.'.str_replace('.', '_', $row->type));
        if (! $handlerClass) {
            return $this->finish($row, 'rejected', 'NO_HANDLER', "no handler for {$row->type}");
        }
        /** @var EventHandler $handler */
        $handler = app($handlerClass);
        $actor = IntegrationBootstrap::actor($row->source, $row->request_id ?? $row->correlation_id);
        $t0 = microtime(true);
        try {
            [$status, $result] = DB::transaction(function () use ($row, $handler, $actor) {
                DB::table('int_subject_state')->insertOrIgnore(['source' => $row->source, 'subject' => $row->subject, 'last_sequence' => 0]);
                $state = DB::table('int_subject_state')->where('source', $row->source)->where('subject', $row->subject)->lockForUpdate()->first();
                if ($row->sequence !== null && $row->sequence <= (int) $state->last_sequence) {
                    return ['stale', ['appliedSequence' => (int) $state->last_sequence, 'appliedEvent' => $state->last_event_id]];
                }
                $result = $handler->handle($row, $actor);
                DB::table('int_subject_state')->where('source', $row->source)->where('subject', $row->subject)->update([
                    'last_sequence' => max((int) $state->last_sequence, (int) $row->sequence), 'last_event_id' => $row->event_id,
                    'last_type' => $row->type, 'updated_at' => now(),
                ]);

                return ['processed', $result];
            });
            // follow-ups a handler asks for, run AFTER its commit: close exceptions it fixed, replay events it unblocked
            $replay = (array) ($result['_replay'] ?? []);
            $resolve = (array) ($result['_resolve'] ?? []);
            unset($result['_replay'], $result['_resolve']);
            $row = $this->finish($row, $status, null, null, $result, $t0);
            if ($status === 'processed') {
                $this->exceptions->autoResolve('EVENT_DEAD', $row->event_id, 'processed on a later attempt');
                foreach ($resolve as $code => $ref) {
                    $this->exceptions->autoResolve($code, (string) $ref, "fixed by {$row->type} {$row->event_id}");
                }
                foreach ($replay as $code) {
                    $this->replayBlocked($code);
                }
            }

            return $row;
        } catch (Blocked $b) {
            $this->exceptions->raise($b->errorCode, $b->getMessage(), [
                'system' => $row->source, 'entity' => $b->entity, 'entityRef' => $b->entityRef, 'correlationId' => $row->correlation_id,
                'eventId' => $row->event_id, 'details' => $b->details,
            ]);
            $row->attempts++;

            return $this->finish($row, 'blocked', $b->errorCode, $b->getMessage(), null, $t0, now()->addMinutes(self::BLOCKED_RECHECK_MINUTES));
        } catch (Rejected $r) {
            $this->exceptions->raise('EVENT_REJECTED', "حدث {$row->type} ({$row->subject}) مرفوض: {$r->getMessage()}", [
                'system' => $row->source, 'entity' => 'event', 'entityRef' => $row->event_id, 'correlationId' => $row->correlation_id,
                'eventId' => $row->event_id, 'details' => ['code' => $r->errorCode, 'details' => $r->details],
            ]);

            return $this->finish($row, 'rejected', $r->errorCode, $r->getMessage(), null, $t0);
        } catch (Throwable $e) {
            $row->attempts++;
            $backoff = (array) config('integration.backoff', []);
            $code = $e instanceof AppError ? $e->errorCode : 'HANDLER_ERROR';
            $message = mb_substr($e->getMessage(), 0, 500);
            Log::warning("integration: event {$row->event_id} ({$row->type}) attempt {$row->attempts} failed: {$message}");
            if ($row->attempts > count($backoff)) {
                $this->exceptions->raise('EVENT_DEAD', "حدث {$row->type} ({$row->subject}) توقّف بعد {$row->attempts} محاولات: {$message}", [
                    'system' => $row->source, 'entity' => 'event', 'entityRef' => $row->event_id, 'correlationId' => $row->correlation_id,
                    'eventId' => $row->event_id, 'severity' => 'crit', 'details' => ['code' => $code],
                ]);

                return $this->finish($row, 'dead', $code, $message, null, $t0);
            }

            return $this->finish($row, 'failed', $code, $message, null, $t0, now()->addSeconds((int) $backoff[$row->attempts - 1]));
        }
    }

    /** Retries due failures and re-checks parked events. @return array{attempted:int, processed:int, stale:int, blocked:int, failed:int, dead:int, rejected:int} */
    public function processDue(int $limit = 50): array
    {
        $stats = ['attempted' => 0, 'processed' => 0, 'stale' => 0, 'blocked' => 0, 'failed' => 0, 'dead' => 0, 'rejected' => 0];
        $due = IntInbox::whereIn('status', ['failed', 'blocked', 'received'])->where('next_attempt_at', '<=', Clock::sql())
            ->orderBy('next_attempt_at')->limit(max(1, min($limit, 500)))->get();
        foreach ($due as $row) {
            if (! $this->claim($row)) {
                continue;
            }
            $stats['attempted']++;
            $stats[$this->process($row->refresh())->status]++;
        }

        return $stats;
    }

    /** Re-runs the events parked for $code (e.g. after a product got mapped). @return int how many were processed now */
    public function replayBlocked(string $code): int
    {
        $n = 0;
        foreach (IntInbox::where('status', 'blocked')->where('last_code', $code)->orderBy('received_at')->limit(500)->get() as $row) {
            if ($this->claim($row, true)) {
                $n += $this->process($row->refresh())->status === 'processed' ? 1 : 0;
            }
        }

        return $n;
    }

    /** Manual replay from the Control Tower. A replay still respects ordering: it applies only if nothing newer was. */
    public function replay(AuthUser $user, string $id): IntInbox
    {
        $row = IntInbox::find($id) ?? throw AppError::notFound('INBOX_EVENT_NOT_FOUND', 'الحدث غير موجود', 'Event not found');
        $from = $row->status;
        $this->audit->log($user, ['action' => 'INTEGRATION.EVENT_REPLAY', 'entityType' => 'int_inbox', 'entityId' => $row->id,
            'entityNumber' => $row->type.' '.$row->subject, 'oldValue' => $from, 'newValue' => 'replay']);

        return $this->process($row);
    }

    private function claim(IntInbox $row, bool $ignoreSchedule = false): bool
    {
        return IntInbox::whereKey($row->id)->where('status', $row->status)
            ->when(! $ignoreSchedule, fn ($q) => $q->where('next_attempt_at', '<=', Clock::sql()))
            ->update(['next_attempt_at' => now()->addSeconds(self::LEASE_SECONDS)]) === 1;
    }

    private function finish(IntInbox $row, string $status, ?string $code, ?string $error, ?array $result = null, ?float $t0 = null, $next = null): IntInbox
    {
        $row->update([
            'status' => $status, 'attempts' => $row->attempts, 'last_code' => $code, 'last_error' => $error, 'result' => $result,
            'duration_ms' => $t0 ? (int) round((microtime(true) - $t0) * 1000) : null, 'next_attempt_at' => $next,
            'processed_at' => in_array($status, ['processed', 'stale'], true) ? now() : $row->processed_at,
        ]);
        $this->audit->log(null, ['action' => 'INTEGRATION.EVENT_'.strtoupper($status), 'entityType' => 'int_inbox', 'entityId' => $row->id,
            'entityNumber' => "{$row->source}:{$row->type}:{$row->subject}", 'newValue' => array_filter([
                'eventId' => $row->event_id, 'correlationId' => $row->correlation_id, 'sequence' => $row->sequence, 'code' => $code,
            ], fn ($v) => $v !== null)]);

        return $row;
    }

    /** @return array{0:string,1:string}|null [code, message] when the envelope is unusable */
    private function validate(string $system, array $env): ?array
    {
        $str = fn ($k, $max) => is_string($env[$k] ?? null) && trim($env[$k]) !== '' && mb_strlen($env[$k]) <= $max;
        return match (true) {
            ! $str('id', 64) => ['INVALID_ENVELOPE', 'id is required (≤64 chars)'],
            ! $str('type', 80) => ['INVALID_ENVELOPE', 'type is required'],
            ($env['source'] ?? null) !== $system => ['SOURCE_MISMATCH', 'source must be the authenticated system ('.$system.')'],
            ! $str('subject', 120) => ['INVALID_ENVELOPE', 'subject is required (≤120 chars)'],
            isset($env['sequence']) && (! is_int($env['sequence']) || $env['sequence'] < 1) => ['INVALID_ENVELOPE', 'sequence must be a positive integer'],
            isset($env['correlationId']) && ! $str('correlationId', 120) => ['INVALID_ENVELOPE', 'correlationId must be a string ≤120 chars'],
            ! in_array((int) ($env['schemaVersion'] ?? 1), self::SUPPORTED_SCHEMA_VERSIONS, true) => ['UNSUPPORTED_SCHEMA', 'schemaVersion not supported'],
            isset($env['time']) && ! $this->isTime($env['time']) => ['INVALID_ENVELOPE', 'time must be an ISO-8601 timestamp'],
            isset($env['data']) && ! is_array($env['data']) => ['INVALID_ENVELOPE', 'data must be an object'],
            ! Systems::mayEmit($system, $env['type']) => ['EVENT_TYPE_NOT_ALLOWED', $system.' may not send '.$env['type']],
            default => null,
        };
    }

    private function isTime(mixed $v): bool
    {
        if (! is_string($v)) {
            return false;
        }
        try {
            CarbonImmutable::parse($v);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
