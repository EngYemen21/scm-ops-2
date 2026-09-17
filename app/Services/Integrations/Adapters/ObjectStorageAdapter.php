<?php

namespace App\Services\Integrations\Adapters;

interface ObjectStorageAdapter
{
    public function configured(): bool;

    /**
     * Stores the raw bytes under $key. Throws only on a real remote failure.
     *
     * @return array{status:string, key?:?string, url?:?string, detail?:?string} status is `uploaded` or `integration_pending`
     */
    public function putObject(string $key, string $bytes, string $mime): array;

    /** Time-limited read URL for a stored object; null when storage is not configured. */
    public function getSignedUrl(string $key, int $expiresSeconds = 900): ?string;

    /**
     * Cheap connectivity probe (HEAD bucket). Must never throw; must respect the timeout.
     *
     * @return array{ok:bool, detail:string}
     */
    public function probe(int $timeoutMs): array;
}
