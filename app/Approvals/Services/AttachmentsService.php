<?php

namespace App\Approvals\Services;

use App\Approvals\Models\ApprovalAttachment;
use App\Approvals\Models\ApprovalCategory;
use App\Approvals\Models\ApprovalRequest;
use App\Services\Integrations\FileStore;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The files of an approval request.
 *
 * A file added here is kept by FileStore (the object store when one is configured, the database otherwise) under a key
 * that never leaves the server: it is read back only through the request it belongs to, by somebody who may see that
 * request. A file that was attached in Odoo is listed on the request by the projection; its content is read from the
 * Odoo file archive (`odoo_files`, filled by `scm:odoo-files`) once it is kept there — until then it says so.
 *
 * Nothing is deleted: a file taken off a request stays, marked as removed, with who removed it and why.
 */
class AttachmentsService
{
    /** Decoded size cap of one file — the cap of every attachment of the system (IntegrationsService). */
    public const MAX_BYTES = 5 * 1024 * 1024;

    private static ?bool $hasArchive = null;

    public function __construct(private readonly RequestsService $requests) {}

    /** @param  array{fileName:string, mime:string, base64:string}  $file  base64 may carry a `data:<mime>;base64,` prefix */
    public function add(AuthUser $u, string $requestId, array $file): array
    {
        $bytes = (string) base64_decode((string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', (string) $file['base64'])), false);
        if ($bytes === '') {
            throw AppError::validation('ATTACHMENT_EMPTY', 'الملف فارغ أو ترميزه غير صالح', 'The file is empty or its encoding is invalid');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppError::validation('ATTACHMENT_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت', 'The file exceeds 5 MB', ['size' => strlen($bytes), 'max' => self::MAX_BYTES]);
        }
        $name = self::safeName((string) $file['fileName']);
        $id = DB::transaction(function () use ($u, $requestId, $file, $bytes, $name) {
            $r = $this->requests->visible($u, $requestId, true);
            $key = 'approvals/'.mb_strtolower($r->number).'/'.(int) (microtime(true) * 1000).'-'.bin2hex(random_bytes(4)).'-'.$name;
            $put = (new FileStore)->put($key, $bytes, (string) $file['mime']);
            if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
                // a file that is not kept anywhere is not recorded as an attachment
                throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الملف — لم يُضف المرفق', 'The file could not be stored — the attachment was not added');
            }
            ApprovalAttachment::create(['request_id' => $r->id, 'file_name' => $name, 'mime' => mb_substr((string) $file['mime'], 0, 150), 'size' => strlen($bytes),
                'storage_key' => $put['key'] ?? $key, 'status' => $put['status'], 'uploaded_by_id' => $u->id, 'uploaded_by' => $u->nameAr]);
            $this->requests->note($u, $r, 'attached', 'ATTACH', ['fileName' => $name, 'size' => strlen($bytes)]);

            return $r->id;
        });

        return $this->requests->get($u, $id);
    }

    public function remove(AuthUser $u, string $requestId, string $attachmentId, ?string $reason = null): array
    {
        $id = DB::transaction(function () use ($u, $requestId, $attachmentId, $reason) {
            $r = $this->requests->visible($u, $requestId, true);
            $a = $this->attachment($r, $attachmentId);
            if ($e = self::blockRemove($u, $r, $a, $this->requests->manages($u), $this->requests->owned($r))) {
                throw $e;
            }
            if ($r->status === 'pending' && ($rules = ApprovalFlow::fields(ApprovalCategory::where('id', $r->category_id)->value('fields')))['document'] === 'required'
                && ! ApprovalAttachment::where('request_id', $r->id)->whereNull('removed_at')->where('id', '!=', $a->id)->exists()) {
                throw AppError::rule('APPROVAL_DOCUMENT_REQUIRED', 'هذا النوع يشترط مرفقًا والطلب بانتظار القرار — أضف المرفق البديل أولًا ثم أزل هذا', 'This category requires an attachment and the request is waiting for a decision — add the replacement first, then remove this one');
            }
            $reason = ($x = trim((string) $reason)) !== '' ? mb_substr($x, 0, 500) : null;
            $a->forceFill(['removed_at' => now(), 'removed_by' => $u->nameAr, 'remove_reason' => $reason])->save();
            $this->requests->note($u, $r, 'detached', 'DETACH', array_filter(['fileName' => $a->file_name, 'reason' => $reason]));

            return $r->id;
        });

        return $this->requests->get($u, $id);
    }

    /**
     * The content of one attachment for somebody who may see its request.
     *
     * @return array{fileName:string, mime:string, size:int, bytes?:string, disk?:string, path?:string} `bytes` for an OPS file, `disk` + `path` for a file of the Odoo archive (streamed)
     */
    public function open(AuthUser $u, string $requestId, string $attachmentId): array
    {
        $r = $this->requests->visible($u, $requestId);
        $a = $this->attachment($r, $attachmentId);
        if ($a->removed_at !== null && ! $this->requests->manages($u)) {
            throw AppError::notFound('APPROVAL_ATTACHMENT_REMOVED', 'أُزيل هذا المرفق من الطلب', 'This attachment was removed from the request');
        }
        $missing = fn () => AppError::notFound('FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        if ($a->status === ApprovalAttachment::ODOO) {
            $f = self::hasArchive() && ctype_digit((string) $a->external_ref)
                ? DB::table('odoo_files')->where('attachment_id', (int) $a->external_ref)->whereNotNull('disk')->whereNotNull('path')->first(['disk', 'path', 'size', 'mimetype']) : null;
            if (! $f) {
                throw AppError::notFound('ODOO_FILE_NOT_ARCHIVED', 'هذا المرفق من أودو ولم يُؤرشف محتواه في نظام العمليات بعد', 'This attachment comes from Odoo and its content is not archived in OPS yet');
            }
            if (! Storage::disk($f->disk)->exists($f->path)) {
                throw AppError::notFound('ODOO_FILE_MISSING', 'ملف المرفق غير موجود على وسيط التخزين', 'The attachment file is missing from the storage disk');
            }

            return ['fileName' => $a->file_name, 'mime' => (string) ($a->mime ?: $f->mimetype ?: 'application/octet-stream'), 'size' => (int) $f->size, 'disk' => $f->disk, 'path' => $f->path];
        }
        if (! $a->storage_key) {
            throw $missing();
        }
        try {
            $file = (new FileStore)->read($a->storage_key, $a->status);
        } catch (Throwable $e) {
            Log::warning("storage read failed for {$a->storage_key}: {$e->getMessage()}");
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الملف من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $file ?? throw $missing();

        return ['fileName' => $a->file_name, 'mime' => (string) ($a->mime ?: $file['mime'] ?: 'application/octet-stream'), 'size' => (int) $file['size'], 'bytes' => $file['bytes']];
    }

    /**
     * Why this user may not take this file off the request (null = he may): the one who added it while the request is
     * still a draft, a manager at any time — and a file of Odoo only once the area is OPS's.
     */
    public static function blockRemove(AuthUser $u, ApprovalRequest $r, ApprovalAttachment $a, bool $manages, bool $owned): ?AppError
    {
        if ($a->removed_at !== null) {
            return AppError::rule('APPROVAL_ATTACHMENT_REMOVED', 'أُزيل هذا المرفق مسبقًا', 'This attachment was already removed');
        }
        if ($a->source_system === 'odoo' && $owned) {
            return AppError::rule('APPROVAL_OWNED_BY_SOURCE', 'هذا المرفق مصدره أودو وما زال الطلب يُدار هناك — يُزال من أودو', 'This attachment comes from Odoo and the request is still managed there — remove it in Odoo');
        }
        if ($manages) {
            return null;
        }
        $own = $a->uploaded_by_id !== null && strcasecmp((string) $a->uploaded_by_id, $u->id) === 0;
        if (! $own) {
            return AppError::forbidden('APPROVAL_ATTACHMENT_NOT_YOURS', 'يزيل المرفق من أضافه أو مدير الاعتمادات', 'An attachment is removed by whoever added it, or by the approvals manager');
        }

        return $r->status === 'draft' ? null
            : AppError::rule('APPROVAL_NOT_DRAFT', 'الطلب أُرسل وهذا المرفق مما اطّلع عليه المعتمدون — يزيله مدير الاعتمادات، أو اسحب الطلب للتعديل', 'The request was sent and the approvers have seen this file — the approvals manager removes it, or recall the request first');
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

    private function attachment(ApprovalRequest $r, string $id): ApprovalAttachment
    {
        return ApprovalAttachment::where('request_id', $r->id)->where('id', $id)->first()
            ?? throw AppError::notFound('APPROVAL_ATTACHMENT_NOT_FOUND', 'المرفق غير موجود على هذا الطلب', 'The attachment is not on this request');
    }

    private static function safeName(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = (string) preg_replace('/[^\w.\-\x{0600}-\x{06FF} ]+/u', '_', $name);
        $name = mb_substr((string) preg_replace('/\s+/u', '_', trim($name)), 0, 120);

        return $name !== '' ? $name : 'file';
    }
}
