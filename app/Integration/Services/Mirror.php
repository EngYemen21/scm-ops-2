<?php

namespace App\Integration\Services;

use App\Integration\Models\IntMirror;

/** Keeps the latest copy of another system's master records (read-only; used for mapping and display). */
class Mirror
{
    public function put(string $system, string $entity, string $externalId, ?string $label, array $data, ?string $eventId = null): IntMirror
    {
        $m = IntMirror::firstOrNew(['system' => $system, 'entity' => $entity, 'external_id' => $externalId]);
        $m->fill(['label' => $label !== null ? mb_substr($label, 0, 255) : null, 'data' => $data, 'last_event_id' => $eventId])->save();

        return $m;
    }

    public function get(string $system, string $entity, string $externalId): ?IntMirror
    {
        return IntMirror::where('system', $system)->where('entity', $entity)->where('external_id', $externalId)->first();
    }
}
