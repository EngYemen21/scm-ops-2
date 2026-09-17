<?php

namespace App\Services\Core;

use App\Models\ActivityLog;
use App\Models\IntegrationEvent;
use App\Models\Notification;
use App\Support\AuthUser;

/** Activity feed + role notifications + the integration outbox. */
class NotifyService
{
    /** @param  string[]  $notifyRoles  role keys that get a notification for this activity */
    public function activity(?AuthUser $user, string $entityType, ?string $entityId, ?string $entityNumber, string $textAr, ?string $textEn = null, array $notifyRoles = []): void
    {
        ActivityLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_number' => $entityNumber,
            'text_ar' => $textAr,
            'text_en' => $textEn ?? $textAr,
            'user_id' => $user?->id,
            'username' => $user?->username ?? 'system',
        ]);
        foreach ($notifyRoles as $roleKey) {
            Notification::create([
                'role_key' => $roleKey,
                'text_ar' => $textAr,
                'text_en' => $textEn ?? $textAr,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_number' => $entityNumber,
            ]);
        }
    }

    /**
     * Outbox event for B2B / WhatsApp / ERP consumers. Honest status: the row stays `pending` until a real
     * adapter delivers it; nothing here ever reports a delivery that did not happen.
     */
    public function event(string $type, array $payload): void
    {
        IntegrationEvent::create(['type' => $type, 'payload' => $payload]);
    }
}
