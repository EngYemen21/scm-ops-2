<?php

namespace App\Approvals\Services;

use App\Approvals\Models\ApprovalEvent;
use App\Approvals\Models\ApprovalRequest;
use App\Models\Notification;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AuthUser;

/**
 * What is written down when something happens to a request — the same three records every time:
 *   the request's own timeline (`approval_request_events`: what the people involved read),
 *   the system audit trail (AuditService: who changed what, for the auditor),
 *   and a notification to the person who has to act or wants to know (the bell).
 * Call it inside the transaction of the change, so none of the three exists for a change that was rolled back.
 */
class ApprovalLog
{
    public const ENTITY = 'ApprovalRequest';

    public function __construct(private readonly AuditService $audit, private readonly NotifyService $notify) {}

    public function event(ApprovalRequest $r, string $kind, ?AuthUser $by, ?string $note = null, array $meta = []): ApprovalEvent
    {
        return ApprovalEvent::create([
            'request_id' => $r->id, 'kind' => $kind, 'user_id' => $by?->id, 'user_name' => $by?->nameAr, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'meta' => $meta ?: null, 'at' => now(),
        ]);
    }

    public function audit(?AuthUser $by, ApprovalRequest $r, string $action, mixed $old = null, mixed $new = null, ?string $field = null): void
    {
        $this->audit->log($by, ['action' => "APPROVAL.{$action}", 'entityType' => self::ENTITY, 'entityId' => $r->id, 'entityNumber' => $r->number, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
    }

    public function status(?AuthUser $by, ApprovalRequest $r, ?string $from, string $to, ?string $note = null): void
    {
        $this->audit->status($by, self::ENTITY, $r->id, $r->number, $from, $to, $note);
    }

    /** A line in the activity feed of the system (no role is notified: the people concerned are told personally). */
    public function activity(?AuthUser $by, ApprovalRequest $r, string $ar, string $en): void
    {
        $this->notify->activity($by, self::ENTITY, $r->id, $r->number, $ar, $en);
    }

    /**
     * Tells one person. Nobody is told about what he has just done himself.
     *
     * @param  array<int, ?string>  $userIds
     */
    public function tell(array $userIds, ApprovalRequest $r, string $ar, string $en, ?AuthUser $except = null): int
    {
        $sent = 0;
        foreach (array_unique(array_filter($userIds)) as $userId) {
            if ($except && strcasecmp((string) $userId, $except->id) === 0) {
                continue;
            }
            Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => self::ENTITY, 'entity_id' => $r->id, 'entity_number' => $r->number, 'at' => now()]);
            $sent++;
        }

        return $sent;
    }
}
