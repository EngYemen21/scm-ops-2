<?php

namespace App\Integration\Services;

use App\Integration\Models\IntExternalRef;
use App\Support\AppError;

/**
 * Identity links between systems. A link is one-to-one in both directions (unique indexes), so one Sales product can
 * never point at two OPS products, nor two Sales products at one. Matching by similar names is never done here.
 */
class ExternalRefs
{
    public function internalId(string $system, string $entity, string $externalId): ?string
    {
        return IntExternalRef::where('system', $system)->where('entity', $entity)->where('external_id', $externalId)->value('internal_id');
    }

    public function externalId(string $system, string $entity, string $internalId): ?string
    {
        return IntExternalRef::where('system', $system)->where('entity', $entity)->where('internal_id', $internalId)->value('external_id');
    }

    /** @param string[] $externalIds @return array<string,string> external → internal (unmapped ids absent) */
    public function internalIds(string $system, string $entity, array $externalIds): array
    {
        if (! $externalIds) {
            return [];
        }

        return IntExternalRef::where('system', $system)->where('entity', $entity)->whereIn('external_id', array_values(array_unique($externalIds)))
            ->pluck('internal_id', 'external_id')->all();
    }

    public function link(string $system, string $entity, string $externalId, string $internalId, ?string $internalCode = null, ?array $meta = null, ?string $by = null): IntExternalRef
    {
        $existing = IntExternalRef::where('system', $system)->where('entity', $entity)
            ->where(fn ($q) => $q->where('external_id', $externalId)->orWhere('internal_id', $internalId))->get();
        foreach ($existing as $ref) {
            if ($ref->external_id === $externalId && $ref->internal_id === $internalId) {
                $ref->update(['internal_code' => $internalCode ?? $ref->internal_code, 'meta' => $meta ?? $ref->meta]);

                return $ref;
            }
            throw AppError::conflict('REF_ALREADY_LINKED',
                "الربط موجود مسبقًا: {$ref->external_id} ↔ ".($ref->internal_code ?? $ref->internal_id),
                "Already linked: {$ref->external_id} ↔ ".($ref->internal_code ?? $ref->internal_id));
        }

        return IntExternalRef::create(['system' => $system, 'entity' => $entity, 'external_id' => $externalId, 'internal_id' => $internalId,
            'internal_code' => $internalCode, 'meta' => $meta, 'created_by' => $by]);
    }

    public function unlink(string $system, string $entity, string $externalId): bool
    {
        return IntExternalRef::where('system', $system)->where('entity', $entity)->where('external_id', $externalId)->delete() > 0;
    }
}
