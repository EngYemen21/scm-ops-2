<?php

namespace App\Services\Core;

use App\Models\AuditLog;
use App\Models\StatusHistory;
use App\Support\AuthUser;

/**
 * Server-side, append-only audit trail. Call it inside the caller's DB::transaction so an audit row never
 * exists for a rolled-back change (and vice versa). No endpoint edits or deletes audit rows.
 */
class AuditService
{
    /**
     * @param  array{action:string, entityType:string, entityId?:?string, entityNumber?:?string, field?:?string,
     *               oldValue?:mixed, newValue?:mixed, transactionId?:?string}  $entry
     */
    public function log(?AuthUser $user, array $entry): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'username' => $user?->username ?? 'system',
            'action' => $entry['action'],
            'entity_type' => $entry['entityType'],
            'entity_id' => $entry['entityId'] ?? null,
            'entity_number' => $entry['entityNumber'] ?? null,
            'field' => $entry['field'] ?? null,
            'old_value' => self::str($entry['oldValue'] ?? null),
            'new_value' => self::str($entry['newValue'] ?? null),
            'request_id' => $user?->requestId,
            'transaction_id' => $entry['transactionId'] ?? null,
        ]);
    }

    /** Records a status transition on any entity: one audit row + one status_history row. */
    public function status(?AuthUser $user, string $entityType, string $entityId, ?string $entityNumber, ?string $from, string $to, ?string $note = null, ?string $transactionId = null): void
    {
        $this->log($user, [
            'action' => 'STATUS', 'entityType' => $entityType, 'entityId' => $entityId, 'entityNumber' => $entityNumber,
            'field' => 'status', 'oldValue' => $from, 'newValue' => $to, 'transactionId' => $transactionId,
        ]);
        StatusHistory::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'username' => $user?->username ?? 'system',
            'note' => $note,
        ]);
    }

    private static function str(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_substr((string) $text, 0, 2000);
    }
}
