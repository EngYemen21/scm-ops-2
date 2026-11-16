<?php

namespace App\Services\Integrations\Adapters;

/**
 * Stores nothing, reports `integration_pending`. Never pretends the file was saved. No longer selected by
 * AdapterFactory (DatabaseStorage is the default since 2026-11); kept for callers that want an explicit no-op store.
 */
class PendingStorage implements ObjectStorageAdapter
{
    public function configured(): bool
    {
        return false;
    }

    public function putObject(string $key, string $bytes, string $mime): array
    {
        return Pending::result();
    }

    public function getSignedUrl(string $key, int $expiresSeconds = 900): ?string
    {
        return null;
    }

    public function probe(int $timeoutMs): array
    {
        return ['ok' => false, 'detail' => Pending::DETAIL_EN];
    }
}
