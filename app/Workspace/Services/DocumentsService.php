<?php

namespace App\Workspace\Services;

use App\Services\Core\AuditService;
use App\Services\Core\NotifyService;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Support\Paging;
use App\Workspace\Models\DocTag;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Models\WsFile;
use App\Workspace\Support\FolderAccess;
use App\Workspace\Support\Owned;
use App\Workspace\Support\People;
use App\Workspace\Support\RecordLinks;
use App\Workspace\Support\Shape;
use App\Workspace\Support\Ws;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Documents: files (and links) kept in folders, found again by folder, tag, owner, kind or the OPS record they belong to.
 *
 * VERSIONS. A document is a name in a folder; its content is a chain of versions (`ws_files`). A new upload becomes the
 * version in force and the old ones stay, each downloadable — nothing is overwritten.
 *
 * NEVER REMOVED. A document is renamed, moved and archived (and restored); there is no deletion.
 *
 * WHO. Reading and writing follow the folder (FolderAccess): whoever may write a folder uploads into it and renames,
 * moves, re-versions and archives its documents (his own only, where the folder says so); the documents manager may
 * act on any document. Every download passes GET /api/workspace/files/{id}, which asks the same question.
 *
 * OWNERSHIP. While the section follows Odoo a document that came from Odoo is Odoo's: every change of it is refused.
 * A document uploaded here is OPS's, also inside a folder that came from Odoo.
 */
class DocumentsService
{
    public const WITH = ['folder:id,name', 'owner:'.People::COLUMNS, 'tags', 'currentFile'];

    public const STATES = ['active', 'archived', 'all'];

    public const TYPES = ['pdf', 'image', 'doc', 'sheet', 'other'];

    private const SORTS = ['updatedAt' => 'updated_at', 'createdAt' => 'created_at', 'name' => 'name', 'size' => 'size', 'number' => 'number'];

    /** The types of a file the list groups under "text document" and "spreadsheet". */
    private const MIMES = [
        'doc' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text', 'text/plain'],
        'sheet' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/csv', 'application/o-spreadsheet'],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly NumberingService $numbering,
        private readonly NotifyService $notify,
        private readonly FilesService $files,
    ) {}

    // ───────────── reading ─────────────

    public function lookups(): array
    {
        return ['follows' => Owned::following(), 'tags' => DocTag::query()->ordered()->get()->map(fn (DocTag $t) => Shape::docTag($t))->all()];
    }

    /** @return array{total:int, folders:int} what the top of the page counts */
    public function summary(AuthUser $user): array
    {
        $access = new FolderAccess($user);

        return ['total' => $access->scopeDocuments(Document::query())->where('archived', false)->count(), 'folders' => $access->readable(false)->count()];
    }

    /**
     * @param  array{folder?:?string, deep?:mixed, state?:?string, tag?:?string, owner?:?string, kind?:?string, type?:?string, refType?:?string, refNumber?:?string, origin?:?string}  $f
     */
    public function list(AuthUser $user, Paging $page, array $f): array
    {
        $access = new FolderAccess($user);
        $within = null;
        if (! empty($f['folder'])) {
            $folder = $access->folder((string) $f['folder']) ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد غير موجود', 'Folder not found');
            if (! $access->canRead($folder)) {
                Ws::deny($user, 'WS_FOLDER_FORBIDDEN', 'لا تملك صلاحية قراءة هذا المجلد', 'You may not read this folder');
            }
            $within = filter_var($f['deep'] ?? false, FILTER_VALIDATE_BOOLEAN) ? $access->subtree((string) $folder->id) : [(string) $folder->id];
        }
        $query = $access->scopeDocuments(Document::query(), $within)->with(self::WITH);
        match ($f['state'] ?? 'active') {
            'archived' => $query->where('archived', true),
            'all' => null,
            default => $query->where('archived', false),
        };
        if (! empty($f['tag'])) {
            $query->whereIn('id', DB::table('ws_document_tags')->where('tag_id', $f['tag'])->select('document_id'));
        }
        if (! empty($f['owner'])) {
            People::filter($query, (string) $f['owner'], 'owner_id', 'owner_name', $user);
        }
        if (! empty($f['kind'])) {
            $query->where('kind', $f['kind']);
        }
        match ($f['type'] ?? null) {
            'pdf' => $query->where('mime', 'application/pdf'),
            'image' => $query->where('mime', 'like', 'image/%'),
            'doc', 'sheet' => $query->whereIn('mime', self::MIMES[$f['type']]),
            'other' => $query->where('kind', 'file')->where(fn (Builder $w) => $w->whereNull('mime')
                ->orWhere(fn (Builder $o) => $o->where('mime', '!=', 'application/pdf')->where('mime', 'not like', 'image/%')->whereNotIn('mime', array_merge(...array_values(self::MIMES))))),
            default => null,
        };
        if (! empty($f['refType'])) {
            $query->where('ref_type', mb_strtolower((string) $f['refType']));
        }
        if (! empty($f['refNumber'])) {
            $query->where('ref_number', (string) $f['refNumber']);
        }
        if (! empty($f['origin'])) {
            $f['origin'] === 'ops' ? $query->whereNull('source_system') : $query->where('source_system', $f['origin']);
        }
        Ws::search($query, ['number', 'name', 'owner_name', 'ref_number', 'description'], $page->q);
        $column = self::SORTS[$page->sort ?? ''] ?? null;
        $column === null ? $query->orderByDesc('updated_at')->orderByDesc('id') : $query->orderBy($column, $page->order)->orderBy('id', $page->order);

        $out = $page->paginate($query);
        $kept = FilesService::archived(array_map(fn (Document $d) => $d->currentFile?->storage === 'odoo' ? $d->currentFile->external_ref : null, $out['items']));
        $out['items'] = array_map(fn (Document $d) => Shape::document($d, $access, $kept), $out['items']);

        return $out;
    }

    public function get(AuthUser $user, string $idOrNumber): array
    {
        $d = $this->find($idOrNumber);
        $access = new FolderAccess($user);
        $this->mustRead($access, $d);
        $d->loadMissing(self::WITH);
        $owned = Owned::bySource($d);
        $writes = $access->canWriteDocument($d);
        $live = ! $d->archived;
        $versions = $d->versions()->get();
        $kept = FilesService::archived($versions->where('storage', 'odoo')->pluck('external_ref')->all());
        $row = Shape::document($d, $access, $kept);

        return $row + [
            'description' => $d->description, 'path' => $access->path((string) $d->folder_id),
            'versions' => $versions->map(fn (WsFile $f) => FilesService::shape($f, false, $kept))->all(),
            'extra' => $d->extra ?: null, 'archivedAt' => Ws::at($d->archived_at), 'archivedBy' => $d->archived_by, 'archiveReason' => $d->archive_reason,
            'can' => [
                'edit' => ! $owned && $writes && $live, 'move' => ! $owned && $writes && $live, 'version' => ! $owned && $writes && $live && $d->kind === 'file',
                'archive' => ! $owned && $writes && $live, 'restore' => ! $owned && $writes && ! $live,
                'download' => $d->kind === 'file' && ($row['file']['available'] ?? false),
            ],
        ];
    }

    // ───────────── writing ─────────────

    /** A file — or a link — put into a folder the caller may write. */
    public function create(AuthUser $user, array $dto): array
    {
        $access = new FolderAccess($user);
        $folder = $this->writableFolder($access, (string) $dto['folderId']);
        $url = Ws::text($dto['url'] ?? null, 2000);
        $file = ! empty($dto['file']) ? $this->files->decode([$dto['file']])[0] : null;
        if (($file === null) === ($url === null)) {
            throw AppError::validation('WS_DOCUMENT_CONTENT_REQUIRED', 'أرفق ملفًّا أو اكتب رابطًا — أحدهما فقط', 'Attach a file or give a link — one of the two', [['path' => 'file', 'message' => 'ملف أو رابط']]);
        }
        if ($url !== null) {
            $url = self::url($url);
        }
        $id = DB::transaction(function () use ($user, $dto, $folder, $file, $url) {
            [$refType, $refNumber] = RecordLinks::check($dto['refType'] ?? null, $dto['refNumber'] ?? null);
            $d = Document::create([
                'number' => $this->numbering->next('DOC'), 'folder_id' => $folder->id, 'kind' => $file ? 'file' : 'link', 'url' => $url,
                'name' => Ws::text($dto['name'] ?? null, 250) ?? ($file ? $file['name'] : mb_substr((string) preg_replace('#^https?://#i', '', (string) $url), 0, 250)),
                'description' => Ws::text($dto['description'] ?? null), 'owner_id' => $user->id, 'ref_type' => $refType, 'ref_number' => $refNumber,
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            if (! empty($dto['tagIds'])) {
                $d->tags()->sync($this->tagIds((array) $dto['tagIds']));
            }
            if ($file) {
                $this->newVersion($user, $d, $file, null);
            }
            $this->audit->log($user, ['action' => 'DOC.CREATE', 'entityType' => 'WsDocument', 'entityId' => $d->id, 'entityNumber' => $d->number,
                'newValue' => ['folder' => $folder->name, 'name' => $d->name, 'kind' => $d->kind] + array_filter(['url' => $url, 'size' => $file['size'] ?? null, 'mime' => $file['mime'] ?? null, 'ref' => $refNumber])]);
            $this->notify->activity($user, 'WsDocument', $d->id, $d->number, "مستند جديد {$d->number} — {$d->name}", "New document {$d->number} — {$d->name}");

            return $d->id;
        });

        return $this->get($user, $id);
    }

    public function update(AuthUser $user, string $idOrNumber, array $dto): array
    {
        $this->mustWrite($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $dto) {
            $d = $this->locked($idOrNumber);
            Owned::refuse($d, 'document');
            $this->mustBeLive($d);
            $data = [];
            if (array_key_exists('name', $dto) && Ws::text($dto['name']) !== null) {
                $data['name'] = Ws::text($dto['name'], 250);
            }
            if (array_key_exists('description', $dto)) {
                $data['description'] = Ws::text($dto['description']);
            }
            if (array_key_exists('url', $dto) && $d->kind === 'link' && Ws::text($dto['url']) !== null) {
                $data['url'] = self::url((string) $dto['url']);
            }
            if (array_key_exists('refType', $dto) || array_key_exists('refNumber', $dto)) {
                [$data['ref_type'], $data['ref_number']] = RecordLinks::check(array_key_exists('refType', $dto) ? $dto['refType'] : $d->ref_type, array_key_exists('refNumber', $dto) ? $dto['refNumber'] : $d->ref_number);
            }
            [$old, $new] = Ws::diff($d, $data);
            if (array_key_exists('tagIds', $dto)) {
                $tags = collect($this->tagIds((array) $dto['tagIds']))->sort()->values()->all();
                $before = $d->tags()->pluck('ws_doc_tags.id')->map(fn ($x) => (string) $x)->sort()->values()->all();
                if ($before !== $tags) {
                    $d->tags()->sync($tags);
                    [$old['tags'], $new['tags']] = [$before, $tags];
                }
            }
            if (! $new) {
                return $d->id;
            }
            $d->update(array_diff_key($new, ['tags' => 1]) + ['updated_by' => $user->username, 'updated_at' => now()]);
            $this->audit->log($user, ['action' => 'DOC.UPDATE', 'entityType' => 'WsDocument', 'entityId' => $d->id, 'entityNumber' => $d->number, 'oldValue' => $old, 'newValue' => $new]);

            return $d->id;
        });

        return $this->get($user, $id);
    }

    /** To another folder the caller may write. */
    public function move(AuthUser $user, string $idOrNumber, string $folderId): array
    {
        $this->mustWrite($user, $this->find($idOrNumber));
        $target = $this->writableFolder(new FolderAccess($user), $folderId);
        $id = DB::transaction(function () use ($user, $idOrNumber, $target) {
            $d = $this->locked($idOrNumber);
            Owned::refuse($d, 'document');
            $this->mustBeLive($d);
            if ((string) $d->folder_id === (string) $target->id) {
                return $d->id; // it is there already
            }
            $from = Folder::find($d->folder_id);
            $d->update(['folder_id' => $target->id, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'DOC.MOVE', 'entityType' => 'WsDocument', 'entityId' => $d->id, 'entityNumber' => $d->number, 'field' => 'folder', 'oldValue' => $from?->name, 'newValue' => $target->name]);

            return $d->id;
        });

        return $this->get($user, $id);
    }

    /**
     * A new version: it becomes the content in force, the versions before it stay.
     *
     * @param  array{fileName:string, base64:string}  $upload
     */
    public function version(AuthUser $user, string $idOrNumber, array $upload, ?string $note = null): array
    {
        $this->mustWrite($user, $this->find($idOrNumber));
        $file = $this->files->decode([$upload])[0];
        $id = DB::transaction(function () use ($user, $idOrNumber, $file, $note) {
            $d = $this->locked($idOrNumber);
            Owned::refuse($d, 'document');
            $this->mustBeLive($d);
            if ($d->kind !== 'file') {
                throw AppError::rule('WS_DOCUMENT_IS_LINK', 'هذا المستند رابط وليس ملفًّا — يُعدَّل رابطه ولا تُرفع له نسخ', 'This document is a link, not a file — change its address; it takes no versions');
            }
            $row = $this->newVersion($user, $d, $file, $note);
            $this->audit->log($user, ['action' => 'DOC.VERSION', 'entityType' => 'WsDocument', 'entityId' => $d->id, 'entityNumber' => $d->number,
                'newValue' => ['version' => $row->version_no, 'size' => $row->size, 'mime' => $row->mime] + array_filter(['note' => Ws::text($note, 500)])]);

            return $d->id;
        });

        return $this->get($user, $id);
    }

    public function archive(AuthUser $user, string $idOrNumber, ?string $reason = null): array
    {
        return $this->shelve($user, $idOrNumber, true, $reason);
    }

    public function restore(AuthUser $user, string $idOrNumber): array
    {
        return $this->shelve($user, $idOrNumber, false, null);
    }

    private function shelve(AuthUser $user, string $idOrNumber, bool $away, ?string $reason): array
    {
        $this->mustWrite($user, $this->find($idOrNumber));
        $id = DB::transaction(function () use ($user, $idOrNumber, $away, $reason) {
            $d = $this->locked($idOrNumber);
            Owned::refuse($d, 'document');
            if ($d->archived === $away) {
                return $d->id;
            }
            $reason = Ws::text($reason, 1000);
            $d->update(['archived' => $away, 'archived_at' => $away ? now() : null, 'archived_by' => $away ? $user->username : null, 'archive_reason' => $away ? $reason : null, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => $away ? 'DOC.ARCHIVE' : 'DOC.RESTORE', 'entityType' => 'WsDocument', 'entityId' => $d->id, 'entityNumber' => $d->number, 'newValue' => array_filter(['reason' => $reason])]);

            return $d->id;
        });

        return $this->get($user, $id);
    }

    // ───────────── tags (doc.manage on the routes) ─────────────

    public function storeTag(AuthUser $user, array $dto): array
    {
        $tag = DB::transaction(function () use ($user, $dto) {
            $values = $this->tagValues($dto, null);
            $this->tagIsFree($values['name'], $values['group_name'] ?? null, $values['folder_id'] ?? null, null);
            $tag = DocTag::create($values + ['active' => true, 'sequence' => ((int) DocTag::max('sequence')) + 10, 'created_by' => $user->username]);
            $this->audit->log($user, ['action' => 'DOC.TAG.CREATE', 'entityType' => 'WsDocTag', 'entityId' => $tag->id, 'entityNumber' => $tag->name, 'newValue' => $dto]);

            return $tag;
        });

        return Shape::docTag($tag->refresh());
    }

    public function updateTag(AuthUser $user, string $id, array $dto): array
    {
        $tag = DB::transaction(function () use ($user, $id, $dto) {
            $tag = DocTag::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_TAG_NOT_FOUND', 'الوسم غير موجود', 'Tag not found');
            Owned::refuse($tag, 'tag');
            $values = $this->tagValues($dto, $tag);
            if (array_key_exists('active', $dto) && $dto['active'] !== null) {
                $values['active'] = (bool) $dto['active'];
            }
            [$old, $new] = Ws::diff($tag, $values);
            if ($new) {
                if (array_intersect(array_keys($new), ['name', 'group_name', 'folder_id'])) {
                    $this->tagIsFree($values['name'] ?? $tag->name, array_key_exists('group_name', $values) ? $values['group_name'] : $tag->group_name,
                        array_key_exists('folder_id', $values) ? $values['folder_id'] : $tag->folder_id, (string) $tag->id);
                }
                $tag->update($new);
                $this->audit->log($user, ['action' => 'DOC.TAG.UPDATE', 'entityType' => 'WsDocTag', 'entityId' => $tag->id, 'entityNumber' => $tag->name, 'oldValue' => $old, 'newValue' => $new]);
            }

            return $tag;
        });

        return Shape::docTag($tag->refresh());
    }

    /** @return array<string, mixed> */
    private function tagValues(array $dto, ?DocTag $tag): array
    {
        $out = [];
        if (array_key_exists('name', $dto) && Ws::text($dto['name']) !== null) {
            $out['name'] = Ws::text($dto['name'], 120);
        }
        if (array_key_exists('group', $dto)) {
            $out['group_name'] = Ws::text($dto['group'], 120);
        }
        if (array_key_exists('color', $dto) && $dto['color'] !== null) {
            $out['color'] = max(0, min(DocTag::COLORS - 1, (int) $dto['color']));
        }
        if (array_key_exists('folderId', $dto)) {
            $out['folder_id'] = ! empty($dto['folderId'])
                ? (Folder::find((string) $dto['folderId'])?->id ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد غير موجود', 'Folder not found'))
                : null;
        }

        return $out;
    }

    private function tagIsFree(string $name, ?string $group, ?string $folderId, ?string $exceptId): void
    {
        $taken = DocTag::where('name', $name)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($group === null, fn ($q) => $q->whereNull('group_name'), fn ($q) => $q->where('group_name', $group))
            ->when($folderId === null, fn ($q) => $q->whereNull('folder_id'), fn ($q) => $q->where('folder_id', $folderId))->exists();
        if ($taken) {
            throw AppError::conflict('WS_TAG_EXISTS', "الوسم «{$name}» موجود مسبقًا", "The tag “{$name}” already exists");
        }
    }

    // ───────────── helpers ─────────────

    /** Records a decoded file as the next version of the document and puts it in force. */
    private function newVersion(AuthUser $user, Document $d, array $file, ?string $note): WsFile
    {
        $no = ((int) WsFile::where('owner_type', 'document')->where('owner_id', $d->id)->max('version_no')) + 1;
        $before = $d->current_file_id ? WsFile::find($d->current_file_id) : null;
        $row = $this->files->keep($user, 'document', $d->id, $file, $no, $note);
        // a name that ends with the type of the content it had follows the content it has now («عقد.pdf» → «عقد.docx»)
        $name = (string) $d->name;
        $was = $before ? mb_strtolower((string) pathinfo((string) $before->name, PATHINFO_EXTENSION)) : '';
        if ($was !== '' && $was !== $file['ext'] && mb_strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === $was) {
            $name = mb_substr($name, 0, mb_strlen($name) - mb_strlen($was)).$file['ext'];
        }
        $d->update(['current_file_id' => $row->id, 'version' => $no, 'mime' => $row->mime, 'size' => $row->size, 'name' => $name, 'updated_by' => $user->username, 'updated_at' => now()]);

        return $row;
    }

    public function find(string $idOrNumber): Document
    {
        return Document::where('id', $idOrNumber)->orWhere('number', $idOrNumber)->first() ?? throw AppError::notFound('WS_DOCUMENT_NOT_FOUND', 'المستند غير موجود', 'Document not found');
    }

    private function locked(string $idOrNumber): Document
    {
        return Document::where(fn (Builder $w) => $w->where('id', $idOrNumber)->orWhere('number', $idOrNumber))->lockForUpdate()->first()
            ?? throw AppError::notFound('WS_DOCUMENT_NOT_FOUND', 'المستند غير موجود', 'Document not found');
    }

    private function mustRead(FolderAccess $access, Document $d): void
    {
        if (! $access->canReadDocument($d)) {
            Ws::deny($access->user, 'WS_DOCUMENT_FORBIDDEN', 'لا تملك صلاحية قراءة هذا المستند', 'You may not read this document');
        }
    }

    private function mustWrite(AuthUser $user, Document $d): void
    {
        $access = new FolderAccess($user);
        $this->mustRead($access, $d);
        if (! $access->canWriteDocument($d)) {
            Ws::deny($user, 'WS_DOCUMENT_FORBIDDEN', 'يغيّر المستند من يملك صلاحية الكتابة في مجلده', 'A document is changed by whoever may write in its folder');
        }
    }

    private function mustBeLive(Document $d): void
    {
        if ($d->archived) {
            throw AppError::rule('WS_ARCHIVED', 'المستند مؤرشف — استعده أولًا', 'The document is archived — restore it first');
        }
    }

    /** A folder that exists, is alive and may be written by the caller. */
    private function writableFolder(FolderAccess $access, string $id): Folder
    {
        $folder = $access->folder($id) ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد غير موجود', 'Folder not found');
        if (! $access->canWrite($folder)) {
            Ws::deny($access->user, 'WS_FOLDER_FORBIDDEN', 'لا تملك صلاحية الكتابة في هذا المجلد', 'You may not write in this folder');
        }
        if ($folder->archived) {
            throw AppError::rule('WS_ARCHIVED', 'المجلد مؤرشف — لا تُضاف إليه مستندات', 'The folder is archived — nothing can be added to it');
        }

        return $folder;
    }

    /** @param  array<int, mixed>  $ids  @return string[] the ids of tags that exist */
    private function tagIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        $known = DocTag::whereIn('id', $ids)->pluck('id')->map(fn ($x) => (string) $x)->all();
        if (count($known) !== count($ids)) {
            throw AppError::validation('WS_TAG_UNKNOWN', 'أحد الوسوم المختارة غير موجود', 'One of the chosen tags does not exist', [['path' => 'tagIds', 'message' => 'وسم غير موجود']]);
        }

        return $known;
    }

    /** A web address people can open: http or https, nothing else. */
    private static function url(string $url): string
    {
        $url = trim($url);
        // (an address may carry Arabic letters: the scheme, a host and no blank is what is asked for)
        if (! preg_match('#^https?://[^\s/$.?\#][^\s]*$#iu', $url)) {
            throw AppError::validation('WS_URL_INVALID', 'الرابط غير صالح — يبدأ بـ http:// أو https://', 'The link is not valid — it starts with http:// or https://', [['path' => 'url', 'message' => 'رابط غير صالح']]);
        }

        return mb_substr($url, 0, 2000);
    }
}
