<?php

namespace App\Portal\Support;

use App\Portal\Models\PortalEvent;
use App\Support\AuthUser;
use DateTimeInterface;

/**
 * The timeline of a record of the section (`portal_events`): what happened to it and the notes people leave on it.
 * Write a line inside the transaction of the change it records, so it never tells of something that was rolled back.
 */
final class PortalLog
{
    /**
     * @param  string  $entity  application | request | list | statement | branch
     * @param  array<string, mixed>  $more  note · data (the facts of the event) · origin ('odoo') · at · username
     */
    public static function add(string $entity, string $id, string $kind, ?AuthUser $user, array $more = []): PortalEvent
    {
        $at = $more['at'] ?? null;
        $note = Text::clean($more['note'] ?? null, 5000);

        return PortalEvent::create([
            'entity_type' => $entity, 'entity_id' => $id, 'kind' => $kind, 'note' => $note, 'data' => ! empty($more['data']) ? $more['data'] : null,
            'user_id' => $user?->id, 'username' => $more['username'] ?? $user?->username ?? 'system', 'origin' => $more['origin'] ?? null,
            'at' => $at instanceof DateTimeInterface ? $at : now(),
        ]);
    }

    /** @return array<int, array<string, mixed>> the newest first */
    public static function of(string $entity, string $id, int $limit = 200): array
    {
        return PortalEvent::where('entity_type', $entity)->where('entity_id', $id)->orderByDesc('at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (PortalEvent $e) => [
                'id' => $e->id, 'kind' => $e->kind, 'note' => $e->note, 'data' => $e->data ?: null, 'username' => $e->username, 'origin' => $e->origin, 'at' => $e->at?->toIso8601String(),
            ])->all();
    }
}
