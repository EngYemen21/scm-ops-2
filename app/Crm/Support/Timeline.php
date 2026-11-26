<?php

namespace App\Crm\Support;

use App\Crm\Models\CrmLead;
use App\Crm\Models\CrmLeadEvent;
use App\Support\AuthUser;
use DateTimeInterface;

/**
 * Writes one line of a lead's timeline (`crm_lead_events`). Call it inside the transaction of the change it records,
 * so a line never tells of something that was rolled back.
 */
final class Timeline
{
    /**
     * @param  array<string, mixed>  $more  note · from / to (stage ids) · data (facts of the event) · origin ('odoo') · at · username
     */
    public static function add(CrmLead $lead, string $kind, ?AuthUser $user, array $more = []): CrmLeadEvent
    {
        $at = $more['at'] ?? null;

        return CrmLeadEvent::create([
            'lead_id' => $lead->id, 'kind' => $kind, 'from_stage_id' => $more['from'] ?? null, 'to_stage_id' => $more['to'] ?? null,
            'note' => isset($more['note']) && trim((string) $more['note']) !== '' ? trim((string) $more['note']) : null,
            'data' => ! empty($more['data']) ? $more['data'] : null, 'user_id' => $user?->id, 'username' => $more['username'] ?? $user?->username ?? 'system',
            'origin' => $more['origin'] ?? null, 'at' => $at instanceof DateTimeInterface ? $at : now(),
        ]);
    }
}
