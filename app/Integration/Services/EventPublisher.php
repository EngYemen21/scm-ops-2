<?php

namespace App\Integration\Services;

use App\Integration\Models\IntDelivery;
use App\Integration\Support\Systems;
use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\DB;

/**
 * Publishes an OPS event for other systems. Transactional outbox: the event row, its per-(source, subject) sequence and
 * one delivery row per subscribing system are written in the caller's transaction, so the event exists if and only if
 * the business change it describes was committed. Delivery happens afterwards (Dispatcher).
 */
class EventPublisher
{
    public const SOURCE = 'ops';

    public const SCHEMA_VERSION = 1;

    /** Events published during this request, attempted right after the response is built (near real-time path). */
    private static array $fresh = [];

    public function publish(string $type, string $subject, array $data, ?string $correlationId = null, ?string $causationId = null): IntegrationEvent
    {
        return DB::transaction(function () use ($type, $subject, $data, $correlationId, $causationId) {
            $seq = $this->nextSequence(self::SOURCE, $subject);
            $event = IntegrationEvent::create([
                'type' => $type, 'payload' => $data, 'source' => self::SOURCE, 'subject' => $subject, 'sequence' => $seq,
                'correlation_id' => $correlationId ?? $subject, 'causation_id' => $causationId, 'schema_version' => self::SCHEMA_VERSION,
                // delivered per subscriber through int_deliveries, not by the legacy single-target outbox run
                'status' => 'routed',
            ]);
            foreach (Systems::subscribersOf($type) as $subscriber) {
                $d = IntDelivery::create(['event_id' => $event->id, 'subscriber' => $subscriber, 'status' => 'pending', 'next_attempt_at' => now()]);
                self::$fresh[] = $d->id;
            }

            return $event;
        });
    }

    /** The full envelope as it travels (docs/integration/ARCHITECTURE.md §6). */
    public static function envelope(IntegrationEvent $e): array
    {
        return [
            'id' => $e->id, 'type' => $e->type, 'source' => $e->source ?? self::SOURCE, 'subject' => $e->subject,
            'sequence' => $e->sequence, 'time' => $e->created_at?->toIso8601ZuluString('millisecond'),
            'schemaVersion' => $e->schema_version ?? self::SCHEMA_VERSION, 'correlationId' => $e->correlation_id,
            'causationId' => $e->causation_id, 'data' => (array) ($e->payload ?? []),
        ];
    }

    /** Delivery ids created in this request (and forgets them). */
    public static function takeFresh(): array
    {
        $ids = self::$fresh;
        self::$fresh = [];

        return $ids;
    }

    private function nextSequence(string $source, string $subject): int
    {
        $row = DB::table('int_sequences')->where('source', $source)->where('subject', $subject)->lockForUpdate()->first();
        if (! $row) {
            DB::table('int_sequences')->insertOrIgnore(['source' => $source, 'subject' => $subject, 'last' => 0]);
            $row = DB::table('int_sequences')->where('source', $source)->where('subject', $subject)->lockForUpdate()->first();
        }
        $next = (int) $row->last + 1;
        DB::table('int_sequences')->where('source', $source)->where('subject', $subject)->update(['last' => $next]);

        return $next;
    }
}
