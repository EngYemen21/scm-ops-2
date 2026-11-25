<?php

namespace App\Hr\Services;

use App\Hr\Models\Claim;
use App\Hr\Models\ClaimLine;
use App\Hr\Models\Contract;
use App\Hr\Models\Document;
use App\Hr\Models\Employee;
use App\Hr\Models\HrFile;
use App\Hr\Support\ClaimAccess;
use App\Hr\Support\Hr;
use App\Integration\Odoo\Models\OdooFile;
use App\Services\Core\AuditService;
use App\Services\Integrations\FileStore;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The files of the section — scans of documents, receipts, papers of the personal file.
 *
 * They are kept by FileStore (object storage when configured, the database otherwise) under keys of their own and are
 * NOT rows of the generic `attachments` table: that table is listed and served to every signed-in user, and a passport
 * scan is not for everyone. A file is read only through `GET /api/hr/files/{id}`, which asks the permission of what it
 * hangs on: the personal file and contracts need `hr.private`, a document follows its own privacy, a receipt is for
 * the claimant and the reviewers.
 *
 * An attachment that came from Odoo is a row here without bytes (`storage = odoo`): its content is the Odoo backup's
 * (`odoo_files`, written by `scm:odoo-files`) and is read from there when it has been archived.
 */
class FilesService
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** What may be kept: a PDF or a picture, told from the bytes themselves — never from the name or the declared type. */
    public const TYPES = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private readonly FileStore $store, private readonly AuditService $audit) {}

    /** Validation rules of an uploaded file as the client sends it: `{ fileName, mime?, base64 }` (base64 may be a data URL). */
    public static function rules(string $prefix = ''): array
    {
        $maxChars = (int) ceil(self::MAX_BYTES / 3) * 4 + 256;

        return ["{$prefix}fileName" => 'required|string|min:1|max:200', "{$prefix}mime" => 'nullable|string|max:120', "{$prefix}base64" => "required|string|min:8|max:{$maxChars}"];
    }

    /**
     * Keeps a file on what it belongs to. Call inside the caller's transaction, after its permission check.
     *
     * @param  array{fileName:string, base64:string}  $file
     */
    public function store(AuthUser $user, string $ownerType, string $ownerId, array $file): HrFile
    {
        $base64 = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', trim((string) $file['base64'])));
        $bytes = base64_decode($base64, true);
        if (! is_string($bytes) || $bytes === '') {
            throw AppError::validation('HR_FILE_EMPTY', 'الملف فارغ أو ترميزه غير صالح', 'The file is empty or its encoding is invalid');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppError::validation('HR_FILE_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت', 'The file exceeds 5 MB', ['size' => strlen($bytes), 'max' => self::MAX_BYTES]);
        }
        $mime = str_starts_with($bytes, '%PDF-') ? 'application/pdf' : FileStore::sniffImage($bytes);
        if ($mime === null) {
            throw AppError::validation('HR_FILE_TYPE', 'يُقبل ملف PDF أو صورة (PNG / JPEG / WebP) فقط', 'Only a PDF or a picture (PNG / JPEG / WebP) is accepted');
        }
        $name = self::safeName((string) $file['fileName'], self::TYPES[$mime]);
        $key = "hr/{$ownerType}/{$ownerId}/".Str::lower((string) Str::ulid()).'.'.self::TYPES[$mime];
        $put = $this->store->put($key, $bytes, $mime);
        if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
            throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الملف في التخزين — لم يُحفظ المرفق', 'The file could not be stored — nothing was saved');
        }
        $row = HrFile::create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'name' => $name, 'mime' => $mime, 'size' => strlen($bytes), 'storage' => 'ops',
            'storage_key' => $put['key'] ?? $key, 'storage_status' => $put['status'], 'created_by' => $user->username]);
        // the name of a file may itself say whose paper it is: the trail records that a file was added, not its name
        $this->audit->log($user, ['action' => 'HR.FILE.ADD', 'entityType' => 'HrFile', 'entityId' => $row->id, 'newValue' => ['owner' => $ownerType, 'ownerId' => $ownerId, 'mime' => $mime, 'size' => strlen($bytes)]]);

        return $row;
    }

    /** Takes a file off its owner. The row stays (who removed it and when); the list no longer shows it. */
    public function remove(AuthUser $user, HrFile $file): void
    {
        if ($file->removed_at) {
            return;
        }
        $file->update(['removed_at' => now(), 'removed_by' => $user->username]);
        $this->audit->log($user, ['action' => 'HR.FILE.REMOVE', 'entityType' => 'HrFile', 'entityId' => $file->id, 'newValue' => ['owner' => $file->owner_type, 'ownerId' => $file->owner_id]]);
    }

    /** @return Collection<int, HrFile> the files that hang on one record */
    public function of(string $ownerType, string $ownerId): Collection
    {
        return HrFile::where('owner_type', $ownerType)->where('owner_id', $ownerId)->whereNull('removed_at')->orderBy('created_at')->orderBy('id')->get();
    }

    /** @param  string[]  $ownerIds  @return array<string, array<int, array>> owner id → its files, shaped */
    public function shapedFor(string $ownerType, array $ownerIds): array
    {
        $out = [];
        if ($ownerIds) {
            foreach (HrFile::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->whereNull('removed_at')->orderBy('created_at')->orderBy('id')->get() as $f) {
                $out[$f->owner_id][] = self::shape($f);
            }
        }

        return $out;
    }

    public static function shape(HrFile $f): array
    {
        return ['id' => $f->id, 'name' => $f->name, 'mime' => $f->mime, 'size' => (int) $f->size, 'fromOdoo' => $f->storage === 'odoo',
            'createdBy' => $f->created_by, 'createdAt' => Hr::at($f->created_at), 'url' => "/api/hr/files/{$f->id}"];
    }

    /**
     * A file for the person asking, after the permission of its owner was checked.
     *
     * @return array{bytes:string, mime:string, size:int, fileName:string}
     */
    public function read(AuthUser $user, string $id): array
    {
        $missing = fn () => AppError::notFound('HR_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        $file = HrFile::where('id', $id)->whereNull('removed_at')->first() ?? throw $missing();
        $this->authorize($user, $file);
        if ($file->storage === 'odoo') {
            return $this->readOdoo($file);
        }
        try {
            $got = $this->store->read((string) $file->storage_key, $file->storage_status);
        } catch (Throwable $e) {
            Log::warning("hr file {$file->id}: the store did not answer (".class_basename($e).')');
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الملف من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $got ?? throw $missing();

        return ['bytes' => $got['bytes'], 'mime' => $file->mime ?: 'application/octet-stream', 'size' => (int) $got['size'], 'fileName' => $file->name];
    }

    /** The content of an attachment that came from Odoo: kept by the Odoo backup, when it has been archived. */
    private function readOdoo(HrFile $file): array
    {
        $notYet = fn () => AppError::notFound('HR_FILE_NOT_ARCHIVED', 'محتوى هذا المرفق لم يُؤرشَف من أودو بعد — يظهر هنا بعد أرشفة المرفقات', 'The content of this attachment is not archived from Odoo yet');
        if (! Schema::hasTable('odoo_files')) {
            throw $notYet();
        }
        $kept = OdooFile::where('attachment_id', (int) $file->external_ref)->where('status', 'stored')->first() ?? throw $notYet();
        try {
            $bytes = Storage::disk($kept->disk)->get($kept->path);
        } catch (Throwable $e) {
            Log::warning("hr file {$file->id}: the disk of the Odoo backup did not answer (".class_basename($e).')');
            $bytes = null;
        }
        if (! is_string($bytes) || $bytes === '') {
            throw AppError::notFound('HR_FILE_MISSING', 'ملف المرفق غير موجود على وسيط التخزين', 'The attachment file is missing from the storage disk');
        }

        return ['bytes' => $bytes, 'mime' => $file->mime ?: ($kept->mimetype ?: 'application/octet-stream'), 'size' => strlen($bytes), 'fileName' => $file->name];
    }

    /** Who may read a file is decided by what it hangs on. */
    public function authorize(AuthUser $user, HrFile $file): void
    {
        $gone = fn () => AppError::notFound('HR_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        switch ($file->owner_type) {
            case 'employee':
            case 'contract':
                Hr::needPrivate($user);
                ($file->owner_type === 'employee' ? Employee::whereKey($file->owner_id)->exists() : Contract::whereKey($file->owner_id)->exists()) ?: throw $gone();

                return;
            case 'document':
                $doc = Document::find($file->owner_id) ?? throw $gone();
                if (! DocumentsService::canSee($user, $doc)) {
                    throw AppError::forbidden('HR_FORBIDDEN', 'لا تملك صلاحية عرض هذه الوثيقة', 'You may not read this document');
                }

                return;
            case 'claim':
            case 'claim_line':
                $claim = $file->owner_type === 'claim' ? Claim::find($file->owner_id) : Claim::find(ClaimLine::whereKey($file->owner_id)->value('claim_id'));
                $claim ?? throw $gone();
                ClaimAccess::assertSees($user, $claim);

                return;
            default:
                throw $gone();
        }
    }

    /** A file name that is safe to store and to send back in a header: no path, no control characters, the right extension. */
    private static function safeName(string $name, string $ext): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name));
        $base = trim(mb_substr((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name), 0, 150)) ?: 'file';

        return "{$base}.{$ext}";
    }
}
