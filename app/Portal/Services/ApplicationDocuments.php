<?php

namespace App\Portal\Services;

use App\Portal\Models\Application;
use App\Portal\Models\ApplicationDocument;
use App\Portal\Support\Owned;
use App\Portal\Support\PortalLog;
use App\Portal\Support\Shape;
use App\Portal\Support\Text;
use App\Services\Core\AuditService;
use App\Services\Integrations\FileStore;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The papers of an onboarding application: commercial registration, VAT certificate, national address, the owner's
 * identity, a bank letter, the chamber-of-commerce certificate.
 *
 * A paper added here is kept by FileStore (the object store when one is configured, the database otherwise) under a
 * key that never leaves the server: it is read back only through the application it belongs to. A paper the company
 * attached in Odoo is listed on the application by the projection; its content is read from the Odoo file archive
 * (`odoo_files`, filled by `scm:odoo-files`) once it is kept there — until then it says so. Linked, never copied.
 *
 * WHAT MAY BE UPLOADED: a PDF or an image, told by the bytes themselves, up to 5 MB. Nothing a browser would run.
 *
 * WHO MAY OPEN WHAT: every reader of the page opens the company's papers; the ones that show a person's identity or a
 * bank account (`ApplicationDocument::PRIVATE_KINDS`) are opened by who decides applications (`portal.manage`).
 *
 * Nothing is deleted: a paper taken off an application stays, marked as removed, with who removed it and why.
 */
class ApplicationDocuments
{
    /** Decoded size cap of one file — the cap of every attachment of the system. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private static ?bool $hasArchive = null;

    public function __construct(private readonly AuditService $audit) {}

    /** @return array<int, array<string, mixed>> the papers of an application, the newest first; removed ones last */
    public function of(Application $a, bool $private): array
    {
        $docs = ApplicationDocument::where('application_id', $a->id)->orderByRaw('removed_at IS NOT NULL')->orderByDesc('created_at')->orderByDesc('id')->limit(200)->get();
        $archived = self::archived($docs->where('status', ApplicationDocument::ODOO)->pluck('external_ref')->all());

        return $docs->map(fn (ApplicationDocument $d) => Shape::document($d, $archived, $private))->all();
    }

    /** The kinds of paper that are on the application now. @return string[] */
    public function kinds(Application $a): array
    {
        return ApplicationDocument::where('application_id', $a->id)->whereNull('removed_at')->distinct()->pluck('kind')->all();
    }

    /** @param  array{kind:string, fileName:string, mime?:?string, base64:string}  $file  base64 may carry a `data:<mime>;base64,` prefix */
    public function add(AuthUser $user, string $applicationId, array $file): string
    {
        $bytes = (string) base64_decode((string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', (string) $file['base64'])), false);
        if ($bytes === '') {
            throw AppError::validation('ATTACHMENT_EMPTY', 'الملف فارغ أو ترميزه غير صالح', 'The file is empty or its encoding is invalid', [['path' => 'base64', 'message' => 'ملف غير صالح']]);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppError::validation('ATTACHMENT_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت', 'The file exceeds 5 MB', ['size' => strlen($bytes), 'max' => self::MAX_BYTES]);
        }
        // the type is read from the bytes, never trusted from the name or the header the client sends
        $mime = str_starts_with($bytes, '%PDF-') ? 'application/pdf' : FileStore::sniffImage($bytes);
        if ($mime === null) {
            throw AppError::validation('ATTACHMENT_INVALID', 'يُقبل ملف PDF أو صورة (PNG، JPEG، WebP)', 'Only a PDF or an image (PNG, JPEG, WebP) is accepted', [['path' => 'base64', 'message' => 'نوع غير مقبول']]);
        }
        $name = Text::fileName((string) $file['fileName']);

        return DB::transaction(function () use ($user, $applicationId, $file, $bytes, $mime, $name) {
            $a = ApplicationsService::locked($applicationId);
            $key = 'portal/applications/'.mb_strtolower($a->id).'/'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(4)).'-'.$name;
            $put = (new FileStore)->put($key, $bytes, $mime);
            if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
                // a file that is not kept anywhere is not recorded as a paper of the application
                throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الملف — لم يُضف المستند', 'The file could not be stored — the document was not added');
            }
            $d = ApplicationDocument::create(['application_id' => $a->id, 'kind' => $file['kind'], 'file_name' => $name, 'mime' => $mime, 'size' => strlen($bytes),
                'storage_key' => $put['key'] ?? $key, 'status' => $put['status'], 'uploaded_by_id' => $user->id, 'uploaded_by' => $user->username]);
            $this->recount($a);
            PortalLog::add('application', $a->id, 'document_added', $user, ['data' => ['kind' => $d->kind, 'fileName' => $name, 'size' => strlen($bytes)]]);
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.DOCUMENT', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number,
                'newValue' => ['kind' => $d->kind, 'fileName' => $name, 'size' => strlen($bytes)]]);

            return $a->id;
        });
    }

    public function remove(AuthUser $user, string $applicationId, string $documentId, ?string $reason): string
    {
        return DB::transaction(function () use ($user, $applicationId, $documentId, $reason) {
            $a = ApplicationsService::locked($applicationId);
            $d = $this->document($a, $documentId);
            if ($d->removed_at !== null) {
                throw AppError::rule('PORTAL_DOCUMENT_REMOVED', 'أُزيل هذا المستند مسبقًا', 'This document was already removed');
            }
            if ($d->source_system === Owned::SYSTEM && Owned::bySource($a)) {
                throw AppError::rule('PORTAL_OWNED_BY_SOURCE', 'هذا المستند مصدره أودو وما زال الطلب يُدار هناك — يُزال من أودو', 'This document comes from Odoo and the application is still managed there — remove it in Odoo',
                    ['entity' => 'document', 'sourceSystem' => $d->source_system, 'externalRef' => $d->external_ref]);
            }
            $reason = Text::clean($reason, 500);
            $d->forceFill(['removed_at' => now(), 'removed_by' => $user->username, 'remove_reason' => $reason])->save();
            $this->recount($a);
            PortalLog::add('application', $a->id, 'document_removed', $user, ['note' => $reason, 'data' => ['kind' => $d->kind, 'fileName' => $d->file_name]]);
            $this->audit->log($user, ['action' => 'PORTAL.APPLICATION.DOCUMENT_REMOVE', 'entityType' => 'PortalApplication', 'entityId' => $a->id, 'entityNumber' => $a->number,
                'oldValue' => ['kind' => $d->kind, 'fileName' => $d->file_name], 'newValue' => ['reason' => $reason]]);

            return $a->id;
        });
    }

    /**
     * The content of one paper for somebody who may open it.
     *
     * @return array{fileName:string, mime:string, size:int, bytes?:string, disk?:string, path?:string} `bytes` for an OPS file, `disk` + `path` for a file of the Odoo archive (streamed)
     */
    public function open(AuthUser $user, string $applicationId, string $documentId): array
    {
        $a = Application::where(fn ($w) => $w->where('id', $applicationId)->orWhere('number', $applicationId))->first()
            ?? throw AppError::notFound('PORTAL_APPLICATION_NOT_FOUND', 'طلب فتح الحساب غير موجود', 'Application not found');
        $d = $this->document($a, $documentId);
        $decides = $user->can('portal.manage');
        if (in_array($d->kind, ApplicationDocument::PRIVATE_KINDS, true) && ! $decides) {
            throw AppError::forbidden('PORTAL_DOCUMENT_PRIVATE', 'هذا المستند من أوراق الهوية أو الحساب البنكي — يفتحه من يبتّ في الطلبات', 'This document shows a person\'s identity or a bank account — it is opened by who decides applications');
        }
        if ($d->removed_at !== null && ! $decides) {
            throw AppError::notFound('PORTAL_DOCUMENT_REMOVED', 'أُزيل هذا المستند من الطلب', 'This document was removed from the application');
        }
        $missing = fn () => AppError::notFound('FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        if ($d->status === ApplicationDocument::ODOO) {
            $f = self::hasArchive() && ctype_digit((string) $d->external_ref)
                ? DB::table('odoo_files')->where('attachment_id', (int) $d->external_ref)->whereNotNull('disk')->whereNotNull('path')->first(['disk', 'path', 'size', 'mimetype']) : null;
            if (! $f) {
                throw AppError::notFound('ODOO_FILE_NOT_ARCHIVED', 'هذا المستند من أودو ولم يُؤرشف محتواه في نظام العمليات بعد', 'This document comes from Odoo and its content is not archived in OPS yet');
            }
            if (! Storage::disk($f->disk)->exists($f->path)) {
                throw AppError::notFound('ODOO_FILE_MISSING', 'ملف المستند غير موجود على وسيط التخزين', 'The document file is missing from the storage disk');
            }

            return ['fileName' => $d->file_name, 'mime' => (string) ($d->mime ?: $f->mimetype ?: 'application/octet-stream'), 'size' => (int) $f->size, 'disk' => $f->disk, 'path' => $f->path];
        }
        if (! $d->storage_key) {
            throw $missing();
        }
        try {
            $file = (new FileStore)->read($d->storage_key, $d->status);
        } catch (Throwable $e) {
            Log::warning("storage read failed for {$d->storage_key}: {$e->getMessage()}");
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الملف من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $file ?? throw $missing();

        return ['fileName' => $d->file_name, 'mime' => (string) ($d->mime ?: $file['mime'] ?: 'application/octet-stream'), 'size' => (int) $file['size'], 'bytes' => $file['bytes']];
    }

    /** Keeps `documents_count` equal to the papers that are on the application. */
    public function recount(Application $a): void
    {
        $n = ApplicationDocument::where('application_id', $a->id)->whereNull('removed_at')->count();
        if ($a->documents_count !== $n) {
            $a->update(['documents_count' => $n]);
        }
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

        return DB::table('odoo_files')->whereIn('attachment_id', $ids)->whereNotNull('disk')->whereNotNull('path')->pluck('attachment_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /** The Odoo file archive is another part of the system (the backup): it may not be installed where this runs. */
    private static function hasArchive(): bool
    {
        return self::$hasArchive ??= Schema::hasTable('odoo_files');
    }

    private function document(Application $a, string $id): ApplicationDocument
    {
        return ApplicationDocument::where('application_id', $a->id)->where('id', $id)->first()
            ?? throw AppError::notFound('PORTAL_DOCUMENT_NOT_FOUND', 'المستند غير موجود على هذا الطلب', 'The document is not on this application');
    }
}
