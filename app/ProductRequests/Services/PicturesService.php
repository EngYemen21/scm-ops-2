<?php

namespace App\ProductRequests\Services;

use App\ProductRequests\Models\ProductRequest;
use App\ProductRequests\Models\ProductRequestPicture;
use App\ProductRequests\Support\Owned;
use App\Services\Integrations\FileStore;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The pictures of the product a request asks for.
 *
 * A picture added here is an image (PNG, JPEG, WebP or GIF — the type is read from the bytes, never trusted from the
 * name) kept by FileStore under a key that never leaves the server: it is read back only through the request it
 * belongs to, by somebody who may see that request. A picture that was attached in Odoo is listed on the request by
 * the projection; its content is read from the Odoo file archive (`odoo_files`, filled by `scm:odoo-files`) once it is
 * kept there — it is linked, never copied, and until then it says so.
 *
 * Nothing is deleted: a picture taken off a request stays, marked as removed, with who removed it and why.
 */
class PicturesService
{
    /** Decoded size cap of one picture (the form shrinks a phone photo well below it before sending). */
    public const MAX_BYTES = 4 * 1024 * 1024;

    /** Pictures one request may carry. */
    public const MAX_PICTURES = 8;

    private static ?bool $hasArchive = null;

    public function __construct(private readonly RequestsService $requests, private readonly RequestLog $log) {}

    /** @param  array{fileName?:?string, base64:string}  $file  base64 may carry a `data:<mime>;base64,` prefix */
    public function add(AuthUser $u, string $requestId, array $file): array
    {
        $image = FileStore::decodeImage((string) $file['base64'], self::MAX_BYTES, ['ar' => 'الصورة', 'en' => 'The picture'])
            ?? throw AppError::validation('ATTACHMENT_INVALID', 'الصورة فارغة', 'The picture is empty');
        $name = self::safeName((string) ($file['fileName'] ?? ''), $image['ext']);
        $id = DB::transaction(function () use ($u, $requestId, $image, $name) {
            $r = $this->requests->find($u, $requestId, true);
            if ($e = $this->requests->blockPicture($u, $r)) {
                throw $e;
            }
            if (ProductRequestPicture::where('request_id', $r->id)->whereNull('removed_at')->count() >= self::MAX_PICTURES) {
                throw AppError::rule('PREQ_TOO_MANY_PICTURES', 'بلغ الطلب الحد الأقصى من الصور ('.self::MAX_PICTURES.') — أزل صورة قبل إضافة أخرى', 'The request already carries '.self::MAX_PICTURES.' pictures — remove one first');
            }
            $key = 'product-requests/'.mb_strtolower($r->number).'/'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(4)).'.'.$image['ext'];
            $put = (new FileStore)->put($key, $image['bytes'], $image['mime']);
            if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
                // a picture that is not kept anywhere is not recorded
                throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الصورة — لم تُضف', 'The picture could not be stored — it was not added');
            }
            ProductRequestPicture::create(['request_id' => $r->id, 'file_name' => $name, 'mime' => $image['mime'], 'size' => $image['size'],
                'storage_key' => $put['key'] ?? $key, 'status' => $put['status'], 'uploaded_by_id' => $u->id, 'uploaded_by' => $u->nameAr]);
            $this->log->event($r, 'picture_added', $u, null, ['fileName' => $name, 'size' => $image['size']]);
            $this->log->audit($u, $r, 'PICTURE_ADD', null, ['fileName' => $name, 'size' => $image['size'], 'mime' => $image['mime']]);

            return $r->id;
        });

        return $this->requests->get($u, $id);
    }

    public function remove(AuthUser $u, string $requestId, string $pictureId, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($u, $requestId, $pictureId, $reason) {
            $r = $this->requests->find($u, $requestId, true);
            $p = $this->picture($r, $pictureId);
            if ($p->removed_at !== null) {
                throw AppError::rule('PREQ_PICTURE_REMOVED', 'أُزيلت هذه الصورة مسبقًا', 'This picture was already removed');
            }
            Owned::refusePicture($r, $p);
            $own = $p->uploaded_by_id !== null && strcasecmp((string) $p->uploaded_by_id, $u->id) === 0;
            if (! $this->requests->reviews($u) && ! ($own && $r->open())) {
                throw $own
                    ? AppError::rule('PREQ_CLOSED', 'أُغلق الطلب — يزيل الصورة مراجع الأصناف', 'The request is closed — a reviewer removes the picture')
                    : AppError::forbidden('PREQ_PICTURE_NOT_YOURS', 'يزيل الصورة من أضافها أو مراجع الأصناف', 'A picture is removed by whoever added it, or by a reviewer');
            }
            $reason = ($x = trim((string) $reason)) !== '' ? mb_substr($x, 0, 500) : null;
            $p->forceFill(['removed_at' => now(), 'removed_by' => $u->nameAr, 'remove_reason' => $reason])->save();
            $this->log->event($r, 'picture_removed', $u, $reason, ['fileName' => $p->file_name]);
            $this->log->audit($u, $r, 'PICTURE_REMOVE', ['fileName' => $p->file_name], array_filter(['reason' => $reason]));

            return $r->id;
        });

        return $this->requests->get($u, $id);
    }

    /**
     * The content of one picture for somebody who may see its request.
     *
     * @return array{fileName:string, mime:string, size:int, bytes?:string, disk?:string, path?:string} `bytes` for an OPS picture, `disk` + `path` for one of the Odoo archive (streamed)
     */
    public function open(AuthUser $u, string $requestId, string $pictureId): array
    {
        $r = $this->requests->find($u, $requestId);
        $p = $this->picture($r, $pictureId);
        if ($p->removed_at !== null && ! $this->requests->reviews($u)) {
            throw AppError::notFound('PREQ_PICTURE_REMOVED', 'أُزيلت هذه الصورة من الطلب', 'This picture was removed from the request');
        }
        $missing = fn () => AppError::notFound('FILE_NOT_FOUND', 'ملف الصورة غير موجود', 'The picture file was not found');
        if ($p->status === ProductRequestPicture::ODOO) {
            $f = self::hasArchive() && ctype_digit((string) $p->external_ref)
                ? DB::table('odoo_files')->where('attachment_id', (int) $p->external_ref)->where('status', 'stored')->whereNotNull('disk')->whereNotNull('path')->first(['disk', 'path', 'size', 'mimetype']) : null;
            if (! $f) {
                throw AppError::notFound('ODOO_FILE_NOT_ARCHIVED', 'هذه الصورة من أودو ولم يُؤرشف محتواها في نظام العمليات بعد', 'This picture comes from Odoo and its content is not archived in OPS yet');
            }
            if (! Storage::disk($f->disk)->exists($f->path)) {
                throw AppError::notFound('ODOO_FILE_MISSING', 'ملف الصورة غير موجود على وسيط التخزين', 'The picture file is missing from the storage disk');
            }

            return ['fileName' => $p->file_name, 'mime' => (string) ($p->mime ?: $f->mimetype ?: 'application/octet-stream'), 'size' => (int) $f->size, 'disk' => $f->disk, 'path' => $f->path];
        }
        if (! $p->storage_key) {
            throw $missing();
        }
        try {
            $file = (new FileStore)->read($p->storage_key, $p->status);
        } catch (Throwable $e) {
            Log::warning("storage read failed for {$p->storage_key}: {$e->getMessage()}");
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الصورة من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $file ?? throw $missing();

        return ['fileName' => $p->file_name, 'mime' => (string) ($p->mime ?: $file['mime'] ?: 'application/octet-stream'), 'size' => (int) $file['size'], 'bytes' => $file['bytes']];
    }

    /**
     * Which of these Odoo attachments (ids, as text) have their content in the Odoo file archive.
     *
     * @param  array<int, ?string>  $refs
     * @return array<string, true>
     */
    public static function archived(array $refs): array
    {
        $ids = array_values(array_filter(array_map(fn ($ref) => ctype_digit((string) $ref) ? (int) $ref : null, $refs)));
        if (! $ids || ! self::hasArchive()) {
            return [];
        }

        return DB::table('odoo_files')->whereIn('attachment_id', $ids)->where('status', 'stored')->whereNotNull('disk')->whereNotNull('path')->pluck('attachment_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /** The Odoo file archive is another part of the system (the backup): it may not be installed where this runs. */
    private static function hasArchive(): bool
    {
        return self::$hasArchive ??= Schema::hasTable('odoo_files');
    }

    private function picture(ProductRequest $r, string $id): ProductRequestPicture
    {
        return ProductRequestPicture::where('request_id', $r->id)->where('id', $id)->first()
            ?? throw AppError::notFound('PREQ_PICTURE_NOT_FOUND', 'الصورة غير موجودة على هذا الطلب', 'The picture is not on this request');
    }

    /** A file name that is safe to hand back in a header, with the extension of what the bytes really are. */
    private static function safeName(string $name, string $ext): string
    {
        $name = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', trim($name));
        $name = (string) preg_replace('/[^\w.\-\x{0600}-\x{06FF} ]+/u', '_', (string) preg_replace('/[\\\\\/]+/u', '_', $name));
        $name = mb_substr((string) preg_replace('/\s+/u', '_', trim($name)), 0, 110);

        return ($name !== '' ? $name : 'picture').'.'.$ext;
    }
}
