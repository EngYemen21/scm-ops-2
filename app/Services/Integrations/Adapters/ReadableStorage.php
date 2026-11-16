<?php

namespace App\Services\Integrations\Adapters;

/**
 * A storage the server itself can read back, so a stored file is streamed to a signed-in user through
 * `GET /integrations/files/{key}` instead of handing out a provider URL.
 */
interface ReadableStorage
{
    /**
     * The stored object, or null when the key does not exist. Throws only on a real remote failure.
     *
     * @return array{bytes:string, mime:?string, size:int}|null
     */
    public function getObject(string $key): ?array;
}
