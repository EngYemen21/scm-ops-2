<?php

namespace App\Services\Integrations;

use App\Services\Integrations\Adapters\AdapterFactory;
use App\Services\Integrations\Adapters\DatabaseStorage;
use App\Services\Integrations\Adapters\ObjectStorageAdapter;
use App\Services\Integrations\Adapters\Pending;
use App\Services\Integrations\Adapters\ReadableStorage;
use App\Support\AppError;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where files are kept: the object store when one is configured, the database otherwise (AdapterFactory::storage).
 * A stored file says where it lives by the status of its row — `uploaded` (object store) or `stored` (database) — so
 * a file written before an object store was connected stays readable afterwards.
 *
 * Used by the proof of delivery (signature / photo) and by the generic attachments.
 */
class FileStore
{
    /** Proof-of-delivery limits (decoded bytes). The driver app compresses the photo before sending it. */
    public const SIGNATURE_MAX_BYTES = 400 * 1024;

    public const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

    /** Row statuses that mean "the bytes are kept somewhere and can be read back". */
    public const KEPT = ['uploaded', DatabaseStorage::STATUS];

    /** What a browser may render in place; anything else is offered as a download. */
    public const INLINE_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'application/pdf'];

    private const IMAGE_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** @param  ?ObjectStorageAdapter  $fixed  a given adapter (tests, IntegrationsService); otherwise what the environment configures */
    public function __construct(private readonly ?ObjectStorageAdapter $fixed = null) {}

    /**
     * Resolved on every use, not once at construction: a long-lived instance (a queued worker, a cached controller)
     * must follow the configuration as it is now.
     */
    public function storage(): ObjectStorageAdapter
    {
        return $this->fixed ?? AdapterFactory::storage();
    }

    /**
     * A signature / photo as the driver app sends it (a data URL, or bare base64) → the image bytes. The type is read
     * from the bytes themselves, never trusted from the prefix: only PNG / JPEG / WebP / GIF pass.
     *
     * @param  array{ar:string, en:string}  $label  what the file is, for the messages
     * @return array{bytes:string, mime:string, ext:string, size:int}|null null when nothing was sent
     */
    public static function decodeImage(?string $input, int $maxBytes, array $label): ?array
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        $base64 = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', $input));
        $tooLarge = fn (int $size) => AppError::validation('ATTACHMENT_TOO_LARGE',
            "حجم {$label['ar']} يتجاوز الحد المسموح (".self::kb($maxBytes).' ك.ب)', "{$label['en']} exceeds the limit (".self::kb($maxBytes).' KB)',
            ['size' => $size, 'max' => $maxBytes]);
        if (intdiv(strlen($base64), 4) * 3 - 2 > $maxBytes) { // refuse before decoding megabytes of text
            throw $tooLarge(intdiv(strlen($base64), 4) * 3);
        }
        $bytes = base64_decode($base64, true);
        $mime = is_string($bytes) && $bytes !== '' ? self::sniffImage($bytes) : null;
        if ($mime === null) {
            throw AppError::validation('ATTACHMENT_INVALID', "{$label['ar']} ليس صورة صالحة (PNG أو JPEG)", "{$label['en']} is not a valid image (PNG or JPEG)");
        }
        if (strlen($bytes) > $maxBytes) {
            throw $tooLarge(strlen($bytes));
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => self::IMAGE_EXTENSIONS[$mime], 'size' => strlen($bytes)];
    }

    /** Image type from the file signature (no fileinfo extension needed — the serverless runtime has none). */
    public static function sniffImage(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /**
     * Keeps the bytes and says where: `uploaded` (object store) or `stored` (database).
     *
     * `$neverLose`: a failing object store does not fail the caller — the file goes to the database instead (a proof
     * of delivery is taken once, at the customer's door; it cannot be asked for again). Without it a remote failure
     * is the business error STORAGE_UPLOAD_FAILED and nothing is kept.
     *
     * @return array{status:string, key?:?string, url?:?string, detail?:?string}
     */
    public function put(string $key, string $bytes, string $mime, bool $neverLose = false): array
    {
        $storage = $this->storage();
        try {
            $put = $storage->putObject($key, $bytes, $mime);
        } catch (Throwable $e) {
            Log::warning("storage upload failed for {$key}: {$e->getMessage()}");
            if (! $neverLose || $storage instanceof DatabaseStorage) {
                throw AppError::rule('STORAGE_UPLOAD_FAILED', 'فشل رفع الملف إلى التخزين — لم يُحفظ المرفق', 'Storage upload failed — attachment not saved', ['detail' => Pending::describeError($e)]);
            }
            $put = ['status' => Pending::STATUS];
        }
        if ($neverLose && ! in_array($put['status'] ?? null, self::KEPT, true)) {
            $put = (new DatabaseStorage)->putObject($key, $bytes, $mime);
        }

        return $put;
    }

    /**
     * Reads a kept file back. `$status` is the status of its attachment row (where it lives).
     *
     * @return array{bytes:string, mime:?string, size:int}|null
     */
    public function read(string $key, ?string $status = null): ?array
    {
        if ($status !== 'uploaded') {
            $file = (new DatabaseStorage)->getObject($key);
            if ($file || $status === DatabaseStorage::STATUS) {
                return $file;
            }
        }

        $storage = $this->storage();

        return $storage instanceof ReadableStorage ? $storage->getObject($key) : null;
    }

    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024);
    }
}
