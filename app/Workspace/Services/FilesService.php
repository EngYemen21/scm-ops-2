<?php

namespace App\Workspace\Services;

use App\Integration\Odoo\OdooSync;
use App\Services\Core\AuditService;
use App\Services\Integrations\FileStore;
use App\Services\Integrations\IntegrationsService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Workspace\Models\Document;
use App\Workspace\Models\Message;
use App\Workspace\Models\Project;
use App\Workspace\Models\Task;
use App\Workspace\Models\Ticket;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\FolderAccess;
use App\Workspace\Support\Owned;
use App\Workspace\Support\ProjectAccess;
use App\Workspace\Support\TicketAccess;
use App\Workspace\Support\Ws;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The files of the workspace — on a ticket, on a reply or a comment, on a task, and the versions of a document.
 *
 * WHERE THE BYTES ARE. A file added here is kept by FileStore, the mechanism of the proofs of delivery and of every
 * attachment of the system: the object store when one is configured, the database (`stored_files`) otherwise, under a
 * key that never leaves the server. An attachment that came from Odoo is only LINKED: its content is the Odoo backup's
 * (`odoo_files`, written by `scm:odoo-files`) and is streamed from there once it has been archived — until then the
 * file says so. Nothing is copied.
 *
 * WHO READS. Only `GET /api/workspace/files/{id}`, which asks the permission of what the file hangs on: the ticket,
 * the task or the folder of the document. They are NOT rows of the generic `attachments` table, which is served to
 * every signed-in user.
 *
 * WHAT IS ACCEPTED. The size cap of every attachment of the system (5 MB — here for all the files of one request
 * together, so that a request stays within what a server accepts), at most five files, and office papers only: PDF,
 * pictures, Word / Excel / PowerPoint and their open formats, plain text and CSV. The type is read from the bytes
 * themselves, never from the name or from what the browser declares.
 *
 * Nothing is deleted: a file taken off stays on record with who removed it and why.
 */
class FilesService
{
    /** Decoded bytes of all the files of ONE request (the cap of every attachment of the system). */
    public const MAX_BYTES = IntegrationsService::MAX_ATTACHMENT_BYTES;

    public const MAX_FILES = 5;

    /** extension → the type the file is recorded and served as */
    public const TYPES = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation',
        'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel', 'ppt' => 'application/vnd.ms-powerpoint',
        'txt' => 'text/plain', 'csv' => 'text/csv',
    ];

    /** What the bytes can be told to be → the extensions such a file may carry. */
    private const FAMILIES = [
        'zip' => ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'],
        'ole' => ['doc', 'xls', 'ppt'],
        'text' => ['txt', 'csv'],
    ];

    private const IMAGE_EXT = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    private static ?bool $hasArchive = null;

    public function __construct(private readonly FileStore $store, private readonly AuditService $audit) {}

    // ───────────── taking files in ─────────────

    /** Validation rules of the files of a request as the client sends them: `[{ fileName, mime?, base64 }]`. */
    public static function listRules(string $key = 'files'): array
    {
        return [$key => 'sometimes|nullable|array|max:'.self::MAX_FILES] + self::rules("{$key}.*.");
    }

    /** Validation rules of one file (`base64` may be a data URL). `$with`: the key the file hangs under when it is optional. */
    public static function rules(string $prefix = '', ?string $with = null): array
    {
        $maxChars = (int) ceil(self::MAX_BYTES / 3) * 4 + 256;
        $required = $with ? "required_with:{$with}" : 'required';

        return ["{$prefix}fileName" => "{$required}|string|min:1|max:200", "{$prefix}mime" => 'nullable|string|max:150', "{$prefix}base64" => "{$required}|string|min:4|max:{$maxChars}"];
    }

    /** @return array{maxBytes:int, maxFiles:int, extensions:string[]} what the client tells people before they pick a file */
    public static function limits(): array
    {
        return ['maxBytes' => self::MAX_BYTES, 'maxFiles' => self::MAX_FILES, 'extensions' => array_keys(self::TYPES)];
    }

    /**
     * Reads the files of a request: decodes them, applies the caps and tells each type from its bytes. Call it BEFORE
     * the transaction — a refusal then costs nothing.
     *
     * @param  array<int, array{fileName:string, base64:string}>|null  $uploads
     * @return array<int, array{name:string, mime:string, ext:string, bytes:string, size:int}>
     */
    public function decode(?array $uploads): array
    {
        $uploads = array_values($uploads ?? []);
        if (count($uploads) > self::MAX_FILES) {
            throw AppError::validation('WS_FILES_TOO_MANY', 'الحد '.self::MAX_FILES.' ملفات في الطلب الواحد', 'At most '.self::MAX_FILES.' files per request', ['max' => self::MAX_FILES]);
        }
        $out = [];
        $total = 0;
        foreach ($uploads as $i => $upload) {
            $name = (string) ($upload['fileName'] ?? '');
            $base64 = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', trim((string) ($upload['base64'] ?? ''))));
            $bytes = base64_decode($base64, true);
            if (! is_string($bytes) || $bytes === '') {
                throw AppError::validation('WS_FILE_EMPTY', "الملف «{$name}» فارغ أو ترميزه غير صالح", "The file “{$name}” is empty or its encoding is invalid", [['path' => "files.{$i}", 'message' => 'ملف فارغ']]);
            }
            $total += strlen($bytes);
            if ($total > self::MAX_BYTES) {
                throw AppError::validation('WS_FILE_TOO_LARGE', 'مجموع حجم الملفات في الطلب الواحد يتجاوز 5 ميجابايت', 'The files of one request exceed 5 MB in total', ['size' => $total, 'max' => self::MAX_BYTES]);
            }
            [$ext, $mime] = self::typeOf($bytes, $name) ?? throw AppError::validation('WS_FILE_TYPE',
                "نوع الملف «{$name}» غير مقبول — المقبول: PDF، صور، Word، Excel، PowerPoint، نص، CSV", "The type of “{$name}” is not accepted — accepted: PDF, pictures, Word, Excel, PowerPoint, text, CSV",
                ['accepted' => array_keys(self::TYPES)]);
            $out[] = ['name' => self::safeName($name, $ext), 'mime' => $mime, 'ext' => $ext, 'bytes' => $bytes, 'size' => strlen($bytes)];
        }

        return $out;
    }

    /**
     * What a file is, told from its bytes; its name only chooses among the formats that share a signature
     * (a .docx and a .xlsx are both zip archives). Null = not an accepted paper. @return array{0:string, 1:string}|null [extension, type]
     */
    public static function typeOf(string $bytes, string $name): ?array
    {
        $ext = mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($bytes, '%PDF-')) {
            return ['pdf', self::TYPES['pdf']];
        }
        if ($image = FileStore::sniffImage($bytes)) {
            return [self::IMAGE_EXT[$image], $image];
        }
        $family = match (true) {
            str_starts_with($bytes, "PK\x03\x04") => 'zip',
            str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => 'ole',
            // plain text in any encoding people export in (UTF-8, Windows-1256…): no byte that only a binary file carries
            preg_match('/[\x00-\x08\x0E-\x1A\x1C-\x1F]/', substr($bytes, 0, 8192)) === 0 => 'text',
            default => null,
        };

        return $family !== null && in_array($ext, self::FAMILIES[$family], true) ? [$ext, self::TYPES[$ext]] : null;
    }

    /**
     * Keeps one decoded file on what it belongs to. Call inside the caller's transaction, after its permission check.
     *
     * @param  array{name:string, mime:string, ext:string, bytes:string, size:int}  $file
     */
    public function keep(AuthUser $user, string $ownerType, string $ownerId, array $file, ?int $versionNo = null, ?string $note = null): WsFile
    {
        $key = "workspace/{$ownerType}/".Str::lower($ownerId).'/'.Str::lower((string) Str::ulid()).'.'.$file['ext'];
        $put = $this->store->put($key, $file['bytes'], $file['mime']);
        if (! in_array($put['status'] ?? null, FileStore::KEPT, true)) {
            // a file that is not kept anywhere is not recorded as a file
            throw AppError::rule('STORAGE_UPLOAD_FAILED', 'تعذّر حفظ الملف في التخزين — لم يُحفظ شيء', 'The file could not be stored — nothing was saved');
        }
        $row = WsFile::create(['owner_type' => $ownerType, 'owner_id' => $ownerId, 'version_no' => $versionNo, 'note' => Ws::text($note, 500), 'name' => $file['name'], 'mime' => $file['mime'],
            'size' => $file['size'], 'storage' => 'ops', 'storage_key' => $put['key'] ?? $key, 'storage_status' => $put['status'], 'created_by' => $user->username, 'created_by_id' => $user->id]);
        $this->audit->log($user, ['action' => 'WS.FILE.ADD', 'entityType' => 'WsFile', 'entityId' => $row->id, 'entityNumber' => $row->name,
            'newValue' => ['owner' => $ownerType, 'ownerId' => $ownerId, 'mime' => $row->mime, 'size' => $row->size] + ($versionNo ? ['version' => $versionNo] : [])]);

        return $row;
    }

    /** @param  array<int, array>  $files  decoded files  @return WsFile[] */
    public function keepAll(AuthUser $user, string $ownerType, string $ownerId, array $files): array
    {
        return array_map(fn (array $file) => $this->keep($user, $ownerType, $ownerId, $file), $files);
    }

    // ───────────── listing ─────────────

    /**
     * The files that hang on some records of one kind, shaped. `$removable` says whether this caller may take a file off.
     *
     * @param  string[]  $ownerIds
     * @param  callable(WsFile): bool  $removable
     * @return array<string, array<int, array>> owner id → its files, the oldest first
     */
    public function of(string $ownerType, array $ownerIds, callable $removable): array
    {
        if (! $ownerIds) {
            return [];
        }
        $rows = WsFile::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->whereNull('removed_at')->orderBy('created_at')->orderBy('id')->get();
        $kept = self::archived($rows->where('storage', 'odoo')->pluck('external_ref')->all());
        $out = [];
        foreach ($rows as $f) {
            $out[(string) $f->owner_id][] = self::shape($f, $removable($f), $kept);
        }

        return $out;
    }

    /**
     * @param  array<string, true>|null  $kept  the Odoo attachments whose content the backup holds (asked for when null)
     */
    public static function shape(WsFile $f, bool $removable = false, ?array $kept = null): array
    {
        $odoo = $f->storage === 'odoo';

        return [
            'id' => $f->id, 'name' => $f->name, 'mime' => $f->mime, 'size' => (int) $f->size, 'fromOdoo' => $odoo,
            'available' => ! $odoo || isset(($kept ?? self::archived([$f->external_ref]))[(string) $f->external_ref]),
            'versionNo' => $f->version_no, 'note' => $f->note, 'createdBy' => $f->created_by, 'createdAt' => Ws::at($f->created_at),
            'url' => "/api/workspace/files/{$f->id}", 'removable' => $removable,
        ];
    }

    /**
     * Which of these Odoo attachments (ids, as text) have their content in the Odoo backup.
     *
     * @param  array<int, ?string>  $refs
     * @return array<string, true>
     */
    public static function archived(array $refs): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($ref) => ctype_digit((string) $ref) ? (int) $ref : null, $refs))));
        if (! $ids || ! self::hasArchive()) {
            return [];
        }

        return DB::table('odoo_files')->whereIn('attachment_id', $ids)->where('status', 'stored')->whereNotNull('disk')->whereNotNull('path')->pluck('attachment_id')
            ->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /** May this caller take the file off? The one who added it or the manager of its tool — never a version, never what Odoo owns. */
    public static function removableBy(AuthUser $user, WsFile $f, bool $manages): bool
    {
        return $f->owner_type !== 'document' && ! ($f->source_system !== null && Owned::following())
            && ($manages || ($f->created_by_id !== null && strcasecmp((string) $f->created_by_id, $user->id) === 0));
    }

    // ───────────── reading and removing ─────────────

    /**
     * The content of one file for somebody who may see what it hangs on.
     *
     * @return array{fileName:string, mime:string, size:int, bytes?:string, disk?:string, path?:string} `bytes` for a file kept by OPS, `disk` + `path` for a file of the Odoo backup (streamed)
     */
    public function open(AuthUser $user, string $id): array
    {
        $file = $this->find($id);
        $this->authorize($user, $file);
        $name = $this->downloadName($file);
        if ($file->storage === 'odoo') {
            return ['fileName' => $name] + $this->openOdoo($user, $file);
        }
        $missing = fn () => AppError::notFound('WS_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        if (! $file->storage_key) {
            throw $missing();
        }
        try {
            $got = $this->store->read((string) $file->storage_key, $file->storage_status);
        } catch (Throwable $e) {
            Log::warning("workspace file {$file->id}: the store did not answer (".class_basename($e).')');
            throw AppError::unavailable('STORAGE_UNAVAILABLE', 'تعذّر قراءة الملف من التخزين — حاول بعد قليل', 'The file store did not answer — try again shortly');
        }
        $got ?? throw $missing();

        return ['fileName' => $name, 'mime' => (string) ($file->mime ?: $got['mime'] ?: 'application/octet-stream'), 'size' => (int) $got['size'], 'bytes' => $got['bytes']];
    }

    /**
     * The name a file is saved under. A version of a document carries the name people gave the DOCUMENT (it may have
     * been renamed since the upload), with the extension of the file itself and — for an older version — its number.
     */
    private function downloadName(WsFile $file): string
    {
        if ($file->owner_type !== 'document' || ! ($document = Document::find($file->owner_id))) {
            return $file->name;
        }
        $ext = mb_strtolower((string) pathinfo($file->name, PATHINFO_EXTENSION));
        $base = trim((string) preg_replace('/[\\\\\/\x00-\x1F\x7F"<>|:*?]+/u', '_', (string) $document->name)) ?: 'document';
        // the name may end with the type of one of its versions: the file says its own
        $named = mb_strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        if ($named !== '' && isset(self::TYPES[$named]) && mb_strlen($base) > mb_strlen($named) + 1) {
            $base = mb_substr($base, 0, mb_strlen($base) - mb_strlen($named) - 1);
        }
        $version = (string) $file->id !== (string) $document->current_file_id && $file->version_no ? " (v{$file->version_no})" : '';

        return $base.$version.($ext !== '' ? ".{$ext}" : '');
    }

    /** The content of an attachment that came from Odoo: the backup's, when it has been archived — and under the backup's own privacy rule. */
    private function openOdoo(AuthUser $user, WsFile $file): array
    {
        $kept = self::hasArchive() && ctype_digit((string) $file->external_ref)
            ? DB::table('odoo_files')->where('attachment_id', (int) $file->external_ref)->where('status', 'stored')->whereNotNull('disk')->whereNotNull('path')->first(['disk', 'path', 'size', 'mimetype', 'res_model'])
            : null;
        if (! $kept) {
            throw AppError::notFound('WS_FILE_NOT_ARCHIVED', 'هذا الملف مصدره أودو ولم يُؤرشف محتواه في نظام العمليات بعد — يظهر التنزيل حين تُؤرشف المرفقات', 'This file comes from Odoo and its content is not archived in OPS yet');
        }
        // a file that hangs on people's records in Odoo keeps the rule of the backup: it needs odoo.private
        if ($kept->res_model && OdooSync::isRestricted((string) $kept->res_model) && ! $user->can('odoo.private')) {
            Ws::deny($user, 'ODOO_PRIVATE', 'هذا المرفق من ملفات الأشخاص — عرضه يحتاج صلاحية odoo.private', 'This attachment belongs to people\'s files — reading it needs odoo.private');
        }
        if (! Storage::disk($kept->disk)->exists($kept->path)) {
            throw AppError::notFound('WS_FILE_MISSING', 'ملف المرفق غير موجود على وسيط التخزين', 'The attachment file is missing from the storage disk');
        }

        return ['fileName' => $file->name, 'mime' => (string) ($file->mime ?: $kept->mimetype ?: 'application/octet-stream'), 'size' => (int) $kept->size, 'disk' => (string) $kept->disk, 'path' => (string) $kept->path];
    }

    /** Takes a file off what it hangs on. The row stays (who removed it, when and why); the lists no longer show it. */
    public function remove(AuthUser $user, string $id, ?string $reason = null): void
    {
        $file = $this->find($id);
        $manages = $this->authorize($user, $file);
        if ($file->owner_type === 'document') {
            throw AppError::rule('WS_FILE_IS_VERSION', 'نسخة المستند لا تُزال — المستند يحتفظ بكل نسخه؛ أرشف المستند إن لم يعد مطلوبًا', 'A version of a document is not removed — a document keeps every version; archive the document instead');
        }
        Owned::refuse($file, 'file');
        if (! self::removableBy($user, $file, $manages)) {
            Ws::deny($user, 'WS_FILE_FORBIDDEN', 'يزيل الملف من أضافه أو مدير هذا القسم', 'A file is removed by whoever added it, or by the manager of this tool');
        }
        DB::transaction(function () use ($user, $file, $reason) {
            $file->forceFill(['removed_at' => now(), 'removed_by' => $user->username, 'remove_reason' => Ws::text($reason, 500)])->save();
            $this->audit->log($user, ['action' => 'WS.FILE.REMOVE', 'entityType' => 'WsFile', 'entityId' => $file->id, 'entityNumber' => $file->name,
                'newValue' => array_filter(['owner' => $file->owner_type, 'ownerId' => $file->owner_id, 'reason' => Ws::text($reason, 500)])]);
        });
    }

    /**
     * Who may read a file is decided by what it hangs on. Refuses (403, audited) or returns whether the caller manages
     * the tool the file belongs to.
     */
    private function authorize(AuthUser $user, WsFile $file): bool
    {
        $gone = fn () => AppError::notFound('WS_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
        [$type, $ownerId] = [$file->owner_type, (string) $file->owner_id];
        if ($type === 'message') {
            $message = Message::where('id', $ownerId)->whereNull('removed_at')->first() ?? throw $gone();
            [$type, $ownerId] = [$message->thread_type, (string) $message->thread_id];
        }
        switch ($type) {
            case 'ticket':
                $ticket = Ticket::find($ownerId) ?? throw $gone();
                $access = new TicketAccess($user);
                if (! $access->sees($ticket)) {
                    Ws::deny($user, 'WS_TICKET_FORBIDDEN', 'لا تملك صلاحية عرض هذه التذكرة', 'You may not see this ticket');
                }

                return $access->manages();
            case 'task':
                $task = Task::find($ownerId) ?? throw $gone();
                $access = new ProjectAccess($user);
                if (! $access->seesTask($task)) {
                    Ws::deny($user, 'WS_TASK_FORBIDDEN', 'لا تملك صلاحية عرض هذه المهمة', 'You may not see this task');
                }
                $project = $task->project_id ? Project::find($task->project_id) : null;

                return $project ? $access->configuresProject($project) : false;
            case 'document':
                $document = Document::find($ownerId) ?? throw $gone();
                $access = new FolderAccess($user);
                if (! $access->canReadDocument($document)) {
                    Ws::deny($user, 'WS_DOCUMENT_FORBIDDEN', 'لا تملك صلاحية قراءة هذا المستند', 'You may not read this document');
                }

                return $access->manages();
            default:
                throw $gone();
        }
    }

    private function find(string $id): WsFile
    {
        return WsFile::where('id', $id)->whereNull('removed_at')->first() ?? throw AppError::notFound('WS_FILE_NOT_FOUND', 'الملف غير موجود', 'File not found');
    }

    /** The Odoo file archive is another part of the system (the backup): it may not be installed where this runs. */
    private static function hasArchive(): bool
    {
        return self::$hasArchive ??= Schema::hasTable('odoo_files');
    }

    /** A file name that is safe to store and to send back in a header: no path, no control characters, the right extension. */
    private static function safeName(string $name, string $ext): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name));
        $base = trim(mb_substr((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name), 0, 180)) ?: 'file';

        return "{$base}.{$ext}";
    }
}
