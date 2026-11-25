<?php

namespace App\Credit\Services;

use App\Credit\Models\CreditFile;
use App\Credit\Models\CreditRequest;
use App\Credit\Support\Access;
use App\Credit\Support\Flow;
use App\Credit\Support\Owned;
use App\Credit\Support\Privacy;
use App\Credit\Support\Settings;
use App\Credit\Support\Slots;
use App\Credit\Support\Text;
use App\Services\Integrations\FileStore;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The papers of a credit request: the nine document slots (commercial registration, VAT certificate, national address,
 * chamber certificate, the owner's identity, the signatories' identities, the signing authorisation, a bank statement,
 * anything else) and the signed agreement.
 *
 * WHERE THEY ARE KEPT. Rows of `credit_files` — never the generic `attachments` table, which is listed and served to
 * every signed-in user. The bytes are kept by FileStore (the object store when one is configured, the database
 * otherwise) under a key that never leaves the server. A paper that was attached in Odoo is a row without bytes: its
 * content is the Odoo backup's (`odoo_files`) and is read from there once it is archived — until then it says so.
 *
 * WHO READS THEM. The reviewers, approvers and managers of the section; and the requester, the papers HE uploaded,
 * while his request is open (Privacy::sees). Everybody else sees that a slot is filled — not the file, not its name.
 * Every read of a file is written to the audit trail (who, which request, which slot).
 *
 * WHAT MAY BE UPLOADED. A PDF or a picture, told by the bytes themselves, up to 5 MB.
 *
 * NOTHING IS DELETED. A slot holds one paper (the "other" slot several): a new one replaces the one before, which
 * stays as `replaced`; a paper taken off stays as `removed`, with who did it and why.
 */
class DocumentsService
{
    /** Decoded size cap of one file — the cap of every attachment of the system. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    private static ?bool $hasArchive = null;

    public function __construct(private readonly FileStore $store, private readonly CreditLog $log, private readonly Settings $settings) {}

    /** Validation rules of an uploaded file as the client sends it: `{ fileName, base64 }` (base64 may be a data URL). */
    public static function rules(string $prefix = ''): array
    {
        $maxChars = (int) ceil(self::MAX_BYTES / 3) * 4 + 256;

        return ["{$prefix}fileName" => 'required|string|min:1|max:200', "{$prefix}base64" => "required|string|min:8|max:{$maxChars}"];
    }

    /**
     * The bytes of an upload and what they really are.
     *
     * @param  array{fileName:string, base64:string}  $file
     * @return array{bytes:string, mime:string, ext:string, name:string}
     */
    public static function decode(array $file): array
    {
        $base64 = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', trim((string) $file['base64'])));
        $bytes = base64_decode($base64, true);
        if (! is_string($bytes) || $bytes === '') {
            throw AppError::validation('CREDIT_FILE_EMPTY', 'الملف فارغ أو ترميزه غير صالح', 'The file is empty or its encoding is invalid', [['path' => 'base64', 'message' => 'ملف غير صالح']]);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppError::validation('CREDIT_FILE_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت', 'The file exceeds 5 MB', ['size' => strlen($bytes), 'max' => self::MAX_BYTES]);
        }
        // the type is read from the bytes, never trusted from the name or the header the client sends
        $mime = str_starts_with($bytes, '%PDF-') ? 'application/pdf' : FileStore::sniffImage($bytes);
        if ($mime === null) {
            throw AppError::validation('CREDIT_FILE_TYPE', 'يُقبل ملف PDF أو صورة (PNG، JPEG، WebP) فقط', 'Only a PDF or a picture (PNG, JPEG, WebP) is accepted', [['path' => 'base64', 'message' => 'نوع غير مقبول']]);
        }

        return ['bytes' => $bytes, 'mime' => $mime, 'ext' => self::TYPES[$mime], 'name' => Text::fileName((string) $file['fileName'], self::TYPES[$mime])];
    }

    /** @param  array{fileName:string, base64:string}  $file  @return string the request's id */
    public function add(AuthUser $u, string $requestId, string $slot, array $file): string
    {
        if (! Slots::isDocument($slot)) {
            throw AppError::validation('CREDIT_SLOT_UNKNOWN', 'خانة المستند غير معروفة', 'Unknown document slot', [['path' => 'slot', 'message' => 'unknown slot']]);
        }
        $decoded = self::decode($file);

        return DB::transaction(function () use ($u, $requestId, $slot, $decoded) {
            $r = CreditRequest::locate($requestId, true);
            if ($e = $this->blockAdd($u, $r, $slot)) {
                throw $e;
            }
            $row = $this->keep($u, $r, $slot, $decoded);
            $this->recount($r);
            // what was added, not what it is called: the name of a paper may itself say whose paper it is
            $facts = ['slot' => $slot, 'mime' => $row->mime, 'size' => (int) $row->size];
            $this->log->event($r, 'document_added', $u, null, $facts);
            $this->log->audit($u, $r, 'DOCUMENT.ADD', null, $facts);

            return $r->id;
        });
    }

    /** @return string the request's id */
    public function remove(AuthUser $u, string $requestId, string $fileId, ?string $reason): string
    {
        return DB::transaction(function () use ($u, $requestId, $fileId, $reason) {
            $r = CreditRequest::locate($requestId, true);
            $f = $this->file($r, $fileId);
            if ($e = $this->blockRemove($u, $r, $f)) {
                throw $e;
            }
            $reason = Text::clean($reason, 500);
            $f->forceFill(['status' => CreditFile::REMOVED, 'closed_at' => now(), 'closed_by' => $u->nameAr, 'close_reason' => $reason])->save();
            $this->recount($r);
            $this->log->event($r, 'document_removed', $u, $reason, ['slot' => $f->slot]);
            $this->log->audit($u, $r, 'DOCUMENT.REMOVE', ['slot' => $f->slot], ['reason' => $reason]);

            return $r->id;
        });
    }

    /**
     * Keeps the bytes and the row of one paper; in a single slot it replaces the paper before it. Call inside the
     * caller's transaction, after its permission check.
     *
     * @param  array{bytes:string, mime:string, ext:string, name:string}  $decoded
     */
    public function keep(AuthUser $u, CreditRequest $r, string $slot, array $decoded): CreditFile
    {
        $key = 'credit/'.Str::lower((string) $r->id)."/{$slot}/".Str::lower((string) Str::ulid()).'.'.$decoded['ext'];
        $put = $this->store->put($key, $decoded['bytes'], $decoded['mime']);
        if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
            // a file that is not kept anywhere is not recorded as a paper of the request
            throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الملف في التخزين — لم يُضف المستند', 'The file could not be stored — the document was not added');
        }
        if (! in_array($slot, Slots::MANY, true)) {
            CreditFile::where('request_id', $r->id)->where('slot', $slot)->where('status', CreditFile::CURRENT)
                ->update(['status' => CreditFile::REPLACED, 'closed_at' => now(), 'closed_by' => $u->nameAr]);
        }

        return CreditFile::create(['request_id' => $r->id, 'slot' => $slot, 'name' => $decoded['name'], 'mime' => $decoded['mime'], 'size' => strlen($decoded['bytes']),
            'sha256' => hash('sha256', $decoded['bytes']), 'storage' => 'ops', 'storage_key' => $put['key'] ?? $key, 'storage_status' => $put['status'], 'status' => CreditFile::CURRENT,
            'uploaded_by_id' => $u->id, 'uploaded_by' => $u->nameAr]);
    }

    /**
     * The content of one paper for somebody who may read it.
     *
     * @return array{bytes:string, mime:string, size:int, fileName:string}
     */
    public function open(AuthUser $u, string $requestId, string $fileId): array
    {
        $r = CreditRequest::locate($requestId);
        $f = $this->file($r, $fileId);
        if (! Privacy::sees($u, $r, $f->uploaded_by_id)) {
            Privacy::refuse($u, $r, 'document');
        }
        if ($f->status !== CreditFile::CURRENT && ! Privacy::privileged($u)) {
            throw AppError::notFound('CREDIT_FILE_CLOSED', 'هذا المستند استُبدل أو أُزيل من الطلب', 'This document was replaced or removed from the request');
        }
        $out = $f->fromOdoo() ? $this->readOdoo($f) : $this->readOwn($f);
        $this->log->audit($u, $r, 'FILE.READ', null, ['slot' => $f->slot]);

        return $out;
    }

    /** The required papers that are not on the request. @return string[] slots */
    public function missing(CreditRequest $r): array
    {
        $have = CreditFile::where('request_id', $r->id)->where('status', CreditFile::CURRENT)->distinct()->pluck('slot')->all();

        return array_values(array_diff($this->settings->requiredSlots(), $have));
    }

    /** Keeps `attachments_complete` true exactly while every required paper is there (a request of Odoo keeps Odoo's word). */
    public function recount(CreditRequest $r): void
    {
        if (Owned::bySource($r)) {
            return;
        }
        $complete = $this->missing($r) === [];
        if ((bool) $r->attachments_complete !== $complete) {
            $r->forceFill(['attachments_complete' => $complete])->save();
        }
    }

    /**
     * The nine slots of a request with what each holds, as this user may see it.
     *
     * @param  Collection<int, CreditFile>  $files  every file row of the request
     * @return array<int, array<string, mixed>>
     */
    public function slots(AuthUser $u, CreditRequest $r, Collection $files): array
    {
        $required = $this->settings->requiredSlots();
        $privileged = Privacy::privileged($u);
        $archived = self::archived($files->where('storage', 'odoo')->pluck('attachment_ref')->all());
        $out = [];
        foreach (Slots::DOCUMENTS as $slot => [$ar, $en]) {
            $mine = $files->where('slot', $slot)->filter(fn (CreditFile $f) => $f->status === CreditFile::CURRENT || $privileged)->values();
            $out[] = [
                'slot' => $slot, 'labelAr' => $ar, 'labelEn' => $en, 'required' => in_array($slot, $required, true), 'many' => in_array($slot, Slots::MANY, true),
                'filled' => $mine->contains(fn (CreditFile $f) => $f->status === CreditFile::CURRENT),
                'files' => $mine->map(fn (CreditFile $f) => $this->shape($u, $r, $f, $archived))->all(),
            ];
        }

        return $out;
    }

    /** @param  array<string, true>  $archived  the Odoo attachments whose content is kept */
    public function shape(AuthUser $u, CreditRequest $r, CreditFile $f, array $archived = []): array
    {
        $sees = Privacy::sees($u, $r, $f->uploaded_by_id) && ($f->status === CreditFile::CURRENT || Privacy::privileged($u));

        return [
            'id' => $f->id, 'slot' => $f->slot, 'mime' => $f->mime, 'size' => (int) $f->size, 'status' => $f->status, 'source' => $f->fromOdoo() ? 'odoo' : 'ops',
            // the content can be read back: kept by OPS, or by the Odoo backup once the attachment is archived
            'available' => $f->fromOdoo() ? ($f->attachment_ref !== null && isset($archived[(string) $f->attachment_ref])) : $f->storage_key !== null,
            'uploadedBy' => $f->uploaded_by, 'uploadedAt' => Text::at($f->created_at), 'closedAt' => Text::at($f->closed_at), 'closedBy' => $f->closed_by, 'closeReason' => $f->close_reason,
            'name' => $sees ? $f->name : null, 'canOpen' => $sees, 'canRemove' => $this->blockRemove($u, $r, $f) === null,
            'url' => $sees ? "/api/credit/requests/{$r->id}/documents/{$f->id}" : null,
        ];
    }

    /** Why this user may not add a paper to this slot now (null = he may). */
    public function blockAdd(AuthUser $u, CreditRequest $r, string $slot): ?AppError
    {
        // on a request Odoo still manages the nine papers are Odoo's; an extra paper is OPS's own
        if (Owned::bySource($r) && $slot !== 'other') {
            return Owned::error($r, 'file');
        }
        if (Flow::isFinal($r->state)) {
            return Access::closed($r);
        }
        if (Privacy::privileged($u)) {
            return null;
        }
        if (! Privacy::isRequester($u, $r)) {
            return Access::notYours($r);
        }

        return $this->requesterMayStillEdit($r);
    }

    /** Why this user may not take this paper off the request now (null = he may). */
    public function blockRemove(AuthUser $u, CreditRequest $r, CreditFile $f): ?AppError
    {
        if ($f->status !== CreditFile::CURRENT) {
            return AppError::rule('CREDIT_FILE_CLOSED', 'هذا المستند استُبدل أو أُزيل مسبقًا', 'This document was already replaced or removed');
        }
        if ($f->source_system === Owned::SYSTEM && Owned::bySource($r)) {
            return Owned::error($r, 'file');
        }
        if ($f->slot === Slots::SIGNED_AGREEMENT) {
            return AppError::rule('CREDIT_SIGNED_AGREEMENT_KEPT', 'الاتفاقية الموقَّعة لا تُزال: هي سند التوقيع', 'The signed agreement is never taken off: it is the record of the signature');
        }
        if (Flow::isFinal($r->state)) {
            return Access::closed($r);
        }
        if (Privacy::privileged($u)) {
            return null;
        }
        if ($f->uploaded_by_id === null || strcasecmp((string) $f->uploaded_by_id, $u->id) !== 0) {
            return AppError::forbidden('CREDIT_FILE_NOT_YOURS', 'يزيل المستند من رفعه أو مراجعو الائتمان', 'A document is removed by whoever uploaded it, or by the credit reviewers');
        }

        return $this->requesterMayStillEdit($r);
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

        return DB::table('odoo_files')->whereIn('attachment_id', $ids)->where('status', 'stored')->pluck('attachment_id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /** The requester completes his request while it is his (draft, changes requested) or stands in a stage that does not lock it. */
    private function requesterMayStillEdit(CreditRequest $r): ?AppError
    {
        if (in_array($r->state, Flow::EDITABLE, true)) {
            return null;
        }
        $stage = $r->stage;

        return $stage?->lock_request_fields
            ? AppError::rule('CREDIT_FIELDS_LOCKED', "مستندات الطلب {$r->number} مقفلة في مرحلة «{$stage->name_ar}» — يضيفها المراجعون، أو يُعاد الطلب للتعديل",
                "The documents of request {$r->number} are locked in stage \"{$stage->name_en}\" — the reviewers add them, or the request is sent back for changes", ['stage' => $stage->code])
            : null;
    }

    private function file(CreditRequest $r, string $id): CreditFile
    {
        return CreditFile::where('request_id', $r->id)->where('id', $id)->first()
            ?? throw AppError::notFound('CREDIT_FILE_NOT_FOUND', 'المستند غير موجود على هذا الطلب', 'The document is not on this request');
    }

    private function readOwn(CreditFile $f): array
    {
        $missing = fn () => AppError::notFound('CREDIT_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        if (! $f->storage_key) {
            throw $missing();
        }
        try {
            $got = $this->store->read((string) $f->storage_key, $f->storage_status);
        } catch (Throwable $e) {
            Log::warning("credit file {$f->id}: the store did not answer (".class_basename($e).')'); // the id only: a key or a name could say whose paper it is
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الملف من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $got ?? throw $missing();

        return ['bytes' => $got['bytes'], 'mime' => $f->mime ?: 'application/octet-stream', 'size' => (int) $got['size'], 'fileName' => (string) $f->name];
    }

    /** The content of a paper that was attached in Odoo: kept by the Odoo backup, when it has been archived. */
    private function readOdoo(CreditFile $f): array
    {
        $notYet = fn () => AppError::notFound('CREDIT_FILE_NOT_ARCHIVED', 'هذا المستند مرفوع في أودو ولم يُؤرشَف محتواه في نظام العمليات بعد', 'This document was attached in Odoo and its content is not archived in OPS yet');
        if (! self::hasArchive() || ! ctype_digit((string) $f->attachment_ref)) {
            throw $notYet();
        }
        $kept = DB::table('odoo_files')->where('attachment_id', (int) $f->attachment_ref)->where('status', 'stored')->first(['disk', 'path', 'mimetype']) ?? throw $notYet();
        try {
            $bytes = Storage::disk($kept->disk)->get($kept->path);
        } catch (Throwable $e) {
            Log::warning("credit file {$f->id}: the disk of the Odoo backup did not answer (".class_basename($e).')');
            $bytes = null;
        }
        if (! is_string($bytes) || $bytes === '') {
            throw AppError::notFound('CREDIT_FILE_MISSING', 'ملف المستند غير موجود على وسيط التخزين', 'The document file is missing from the storage disk');
        }

        return ['bytes' => $bytes, 'mime' => $f->mime ?: ($kept->mimetype ?: 'application/octet-stream'), 'size' => strlen($bytes), 'fileName' => (string) $f->name];
    }

    /** The Odoo file archive is another part of the system (the backup): it may not be installed where this runs. */
    private static function hasArchive(): bool
    {
        return self::$hasArchive ??= Schema::hasTable('odoo_files');
    }
}
