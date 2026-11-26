<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditEvent;
use App\Credit\Models\CreditRequest;
use App\Credit\Support\Privacy;
use App\Services\Core\AuditService;
use App\Support\AuthUser;

/**
 * What is written down when something happens to a credit request:
 *   the request's own timeline (`credit_events`: what the people involved read), and
 *   the system audit trail (AuditService: who changed what, for the auditor).
 * Call it inside the transaction of the change, so neither exists for a change that was rolled back.
 *
 * Both are scrubbed on the way in: a personal value (an identity number, a bank account, an IBAN) is replaced by the
 * fact that one was given — the trail says THAT it changed and who changed it, never what it is.
 */
class CreditLog
{
    public const ENTITY = 'CreditRequest';

    public function __construct(private readonly AuditService $audit) {}

    public function event(CreditRequest $r, string $kind, ?AuthUser $by, ?string $note = null, array $data = []): CreditEvent
    {
        return CreditEvent::create([
            'request_id' => $r->id, 'kind' => $kind, 'stage_id' => $r->stage_id, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'data' => $data ? Privacy::scrub($data) : null, 'user_id' => $by?->id, 'user_name' => $by?->nameAr, 'at' => now(),
        ]);
    }

    public function audit(?AuthUser $by, CreditRequest $r, string $action, mixed $old = null, mixed $new = null): void
    {
        $this->audit->log($by, ['action' => "CREDIT.{$action}", 'entityType' => self::ENTITY, 'entityId' => $r->id, 'entityNumber' => $r->number,
            'oldValue' => is_array($old) ? Privacy::scrub($old) : $old, 'newValue' => is_array($new) ? Privacy::scrub($new) : $new]);
    }

    public function status(?AuthUser $by, CreditRequest $r, ?string $from, string $to, ?string $note = null): void
    {
        $this->audit->status($by, self::ENTITY, $r->id, $r->number, $from, $to, $note);
    }

    /** An audit line about something of the section that is not a request (a stage, a terms version, a setting). */
    public function config(?AuthUser $by, string $action, string $entityType, ?string $entityId, ?string $label, mixed $old = null, mixed $new = null): void
    {
        $this->audit->log($by, ['action' => "CREDIT.{$action}", 'entityType' => $entityType, 'entityId' => $entityId, 'entityNumber' => $label, 'oldValue' => $old, 'newValue' => $new]);
    }
}
