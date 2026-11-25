<?php

namespace App\ProductRequests\Services;

use App\Models\Notification;
use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestEvent;
use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;

/**
 * What is written down when something happens to a request — the same records every time:
 *   the request's own history (`product_request_events`: what the people involved read),
 *   the system audit trail (AuditService: who changed what, for the auditor),
 *   and a notification to whoever has to act or wants to know (the bell).
 * Call it inside the transaction of the change, so none of them exists for a change that was rolled back.
 */
class RequestLog
{
    public const ENTITY = 'ProductRequest';

    public const REVIEW_PERMISSION = 'preq.review';

    public function __construct(private readonly AuditService $audit, private readonly NotifyService $notify) {}

    /** One line of the request's history. `$from` / `$to` are given when the line tells a change of status. */
    public function event(ProductRequest $r, string $kind, ?AuthUser $by, ?string $note = null, array $meta = [], ?string $from = null, ?string $to = null): ProductRequestEvent
    {
        return ProductRequestEvent::create([
            'request_id' => $r->id, 'kind' => $kind, 'from_status' => $from, 'to_status' => $to, 'user_id' => $by?->id, 'user_name' => $by?->nameAr,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'meta' => $meta ?: null, 'at' => now(),
        ]);
    }

    public function audit(?AuthUser $by, ProductRequest $r, string $action, mixed $old = null, mixed $new = null, ?string $field = null): void
    {
        $this->audit->log($by, ['action' => "PREQ.{$action}", 'entityType' => self::ENTITY, 'entityId' => $r->id, 'entityNumber' => $r->number, 'field' => $field, 'oldValue' => $old, 'newValue' => $new]);
    }

    /** The audit trail's own record of a change of status (audit row + status history). */
    public function status(?AuthUser $by, ProductRequest $r, ?string $from, string $to, ?string $note = null): void
    {
        $this->audit->status($by, self::ENTITY, $r->id, $r->number, $from, $to, $note);
    }

    /** A line in the activity feed of the system (nobody is notified by it: the people concerned are told below). */
    public function activity(?AuthUser $by, ProductRequest $r, string $ar, string $en): void
    {
        $this->notify->activity($by, self::ENTITY, $r->id, $r->number, $ar, $en);
    }

    /** Tells the reviewers: one notification per role that holds `preq.review` (the bell shows a role's notifications to its holders). */
    public function tellReviewers(ProductRequest $r, string $ar, string $en): int
    {
        $roles = DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.key', self::REVIEW_PERMISSION)->distinct()->pluck('roles.key')->all();
        foreach ($roles as $role) {
            Notification::create(['role_key' => $role, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => self::ENTITY, 'entity_id' => $r->id, 'entity_number' => $r->number, 'at' => now()]);
        }

        return count($roles);
    }

    /** Tells one person. Nobody is told about what he has just done himself. */
    public function tell(?string $userId, ProductRequest $r, string $ar, string $en, ?AuthUser $except = null): bool
    {
        if ($userId === null || $userId === '' || ($except && strcasecmp($userId, $except->id) === 0)) {
            return false;
        }
        Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => self::ENTITY, 'entity_id' => $r->id, 'entity_number' => $r->number, 'at' => now()]);

        return true;
    }
}
