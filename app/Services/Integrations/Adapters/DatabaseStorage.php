<?php

namespace App\Services\Integrations\Adapters;

use App\Models\StoredFile;
use App\Support\AuthUser;

/**
 * Files kept in the database (`stored_files`, base64 text in `content`) — what is used while no object storage is
 * configured. A local folder is not an option: production is serverless, a file written to disk is gone on the next
 * request. The file is really stored (status `stored`) and is served by `GET /integrations/files/{key}`.
 *
 * `configured()` answers false on purpose: it means "an external object-storage provider is configured", which is
 * what the integrations status page reports. Nothing here is pending — the bytes are in the database.
 */
class DatabaseStorage implements ObjectStorageAdapter, ReadableStorage
{
    /** Row status of a file that lives in `stored_files` (an object-storage upload is `uploaded`). */
    public const STATUS = 'stored';

    public const DETAIL_EN = 'Integration pending — object storage is not configured; files are kept in the database';

    public const DETAIL_AR = 'تخزين الملفات الخارجي غير مُفعّل — تُحفظ الملفات في قاعدة البيانات';

    public function configured(): bool
    {
        return false;
    }

    /** The API path that serves a stored key (each segment encoded; the slashes of the key stay path separators). */
    public static function url(string $key): string
    {
        return '/api/integrations/files/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    public function putObject(string $key, string $bytes, string $mime): array
    {
        StoredFile::updateOrCreate(['key' => $key], [
            'mime' => $mime ?: 'application/octet-stream', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'content' => base64_encode($bytes), 'created_by' => AuthUser::currentOrNull()?->username,
        ]);

        return ['status' => self::STATUS, 'key' => $key, 'url' => self::url($key)];
    }

    public function getObject(string $key): ?array
    {
        $file = StoredFile::where('key', $key)->first();
        if (! $file) {
            return null;
        }
        $bytes = (string) base64_decode((string) $file->content, true);

        return ['bytes' => $bytes, 'mime' => $file->mime, 'size' => strlen($bytes)];
    }

    /** There is no provider URL: the file is read through the API, by a signed-in user. */
    public function getSignedUrl(string $key, int $expiresSeconds = 900): ?string
    {
        return null;
    }

    public function probe(int $timeoutMs): array
    {
        return ['ok' => false, 'detail' => self::DETAIL_EN];
    }
}
