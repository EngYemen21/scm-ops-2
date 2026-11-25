<?php

namespace App\Workspace\Services;

use App\Services\Core\AuditService;
use App\Support\AppError;
use App\Support\AuthUser;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use App\Workspace\Support\FolderAccess;
use App\Workspace\Support\Owned;
use App\Workspace\Support\Shape;
use App\Workspace\Support\Ws;
use Illuminate\Support\Facades\DB;

/**
 * The folders of the documents tree, kept by the documents manager (`doc.manage`, on the routes): where a folder
 * stands, what it is called, and who reads / writes in it by role (FolderAccess).
 *
 * A new folder starts with the access of the folder it is made in. A folder is archived, never removed — and only once
 * nothing alive is left in it, so that archiving never hides documents people still use.
 *
 * OWNERSHIP. While the section follows Odoo a folder that came from Odoo is Odoo's: its name, its place and whether
 * people see only their own documents are refused here. WHO READS AND WRITES BY ROLE stays open — Odoo says it with its
 * own groups, which mean nothing in OPS; a folder that was restricted there arrives closed to everybody but the
 * documents manager, who names the OPS roles.
 */
class FoldersService
{
    /** What OPS may set on a folder Odoo owns. */
    private const OWN_KEYS = ['readScope', 'readRoles', 'writeScope', 'writeRoles'];

    public function __construct(private readonly AuditService $audit) {}

    /** The folders the caller may read, flat (the client builds the tree), each with how many documents he sees in it. */
    public function tree(AuthUser $user, bool $withArchived): array
    {
        $access = new FolderAccess($user);
        $shown = $access->readable($withArchived);
        $counts = $access->scopeDocuments(Document::query())->where('archived', false)->toBase()->selectRaw('folder_id, COUNT(*) AS n')->groupBy('folder_id')->pluck('n', 'folder_id');

        return ['folders' => $shown->map(fn (Folder $f) => [
            // a folder whose parent is not in the caller's tree (not readable, or put away) is shown at its root
            'parentHidden' => $f->parent_id !== null && ! $shown->has((string) $f->parent_id),
        ] + Shape::folder($f, $access, (int) ($counts[$f->id] ?? 0)))->values()->all()];
    }

    public function create(AuthUser $user, array $dto): array
    {
        $id = DB::transaction(function () use ($user, $dto) {
            $parent = ! empty($dto['parentId']) ? $this->parent((string) $dto['parentId']) : null;
            $name = Ws::text($dto['name'], 190);
            $this->nameIsFree($name, $parent?->id, null);
            // it starts with the access of the folder it is made in
            $values = self::access($dto) + [
                'read_scope' => $parent?->read_scope ?? 'all', 'read_roles' => $parent?->read_roles, 'write_scope' => $parent?->write_scope ?? 'all', 'write_roles' => $parent?->write_roles,
                'own_read' => (bool) ($dto['ownRead'] ?? $parent?->own_read ?? false), 'own_write' => (bool) ($dto['ownWrite'] ?? $parent?->own_write ?? false),
            ];
            $f = Folder::create($values + [
                'parent_id' => $parent?->id, 'name' => $name, 'description' => Ws::text($dto['description'] ?? null, 2000),
                'sequence' => isset($dto['sequence']) ? (int) $dto['sequence'] : ((int) Folder::where('parent_id', $parent?->id)->max('sequence')) + 10,
                'created_by' => $user->username, 'updated_by' => $user->username,
            ]);
            $this->audit->log($user, ['action' => 'FOLDER.CREATE', 'entityType' => 'WsFolder', 'entityId' => $f->id, 'entityNumber' => $f->name, 'newValue' => $dto]);

            return $f->id;
        });

        return $this->shape($user, $id);
    }

    public function update(AuthUser $user, string $id, array $dto): array
    {
        DB::transaction(function () use ($user, $id, $dto) {
            $f = $this->locked($id);
            if (array_diff(array_keys($dto), self::OWN_KEYS)) {
                Owned::refuse($f, 'folder'); // on a folder of Odoo only the roles that read and write are OPS's
            }
            $values = self::access($dto);
            if (array_key_exists('name', $dto) && Ws::text($dto['name']) !== null) {
                $values['name'] = Ws::text($dto['name'], 190);
            }
            if (array_key_exists('description', $dto)) {
                $values['description'] = Ws::text($dto['description'], 2000);
            }
            if (array_key_exists('sequence', $dto) && $dto['sequence'] !== null) {
                $values['sequence'] = (int) $dto['sequence'];
            }
            foreach (['ownRead' => 'own_read', 'ownWrite' => 'own_write'] as $key => $column) {
                if (array_key_exists($key, $dto) && $dto[$key] !== null) {
                    $values[$column] = (bool) $dto[$key];
                }
            }
            if (array_key_exists('parentId', $dto)) {
                $parent = ! empty($dto['parentId']) ? $this->parent((string) $dto['parentId']) : null;
                // a folder is never put inside itself or inside something that stands under it
                if ($parent && in_array((string) $parent->id, (new FolderAccess($user))->subtree((string) $f->id), true)) {
                    throw AppError::rule('WS_FOLDER_CYCLE', 'لا يُنقل المجلد إلى داخل نفسه أو أحد مجلداته الفرعية', 'A folder cannot be moved into itself or into one of its own sub-folders');
                }
                $values['parent_id'] = $parent?->id;
            }
            if (isset($values['name']) || array_key_exists('parent_id', $values)) {
                $this->nameIsFree($values['name'] ?? $f->name, array_key_exists('parent_id', $values) ? $values['parent_id'] : $f->parent_id, (string) $f->id);
            }
            [$old, $new] = Ws::diff($f, $values);
            if ($new) {
                $f->update($new + ['updated_by' => $user->username]);
                $this->audit->log($user, ['action' => array_intersect(array_keys($new), ['read_scope', 'read_roles', 'write_scope', 'write_roles', 'own_read', 'own_write']) ? 'FOLDER.ACCESS' : 'FOLDER.UPDATE',
                    'entityType' => 'WsFolder', 'entityId' => $f->id, 'entityNumber' => $f->name, 'oldValue' => $old, 'newValue' => $new]);
            }
        });

        return $this->shape($user, $id);
    }

    public function archive(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $f = $this->locked($id);
            Owned::refuse($f, 'folder');
            if ($f->archived) {
                return;
            }
            $documents = Document::where('folder_id', $f->id)->where('archived', false)->count();
            $folders = Folder::where('parent_id', $f->id)->where('archived', false)->count();
            if ($documents + $folders > 0) {
                throw AppError::rule('WS_FOLDER_NOT_EMPTY', "في المجلد {$documents} مستند و{$folders} مجلد فرعي غير مؤرشف — انقلها أو أرشفها أولًا", "The folder still holds {$documents} document(s) and {$folders} sub-folder(s) — move or archive them first",
                    ['documents' => $documents, 'folders' => $folders]);
            }
            $f->update(['archived' => true, 'archived_at' => now(), 'archived_by' => $user->username, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'FOLDER.ARCHIVE', 'entityType' => 'WsFolder', 'entityId' => $f->id, 'entityNumber' => $f->name]);
        });

        return $this->shape($user, $id);
    }

    public function restore(AuthUser $user, string $id): array
    {
        DB::transaction(function () use ($user, $id) {
            $f = $this->locked($id);
            Owned::refuse($f, 'folder');
            if (! $f->archived) {
                return;
            }
            $f->update(['archived' => false, 'archived_at' => null, 'archived_by' => null, 'updated_by' => $user->username]);
            $this->audit->log($user, ['action' => 'FOLDER.RESTORE', 'entityType' => 'WsFolder', 'entityId' => $f->id, 'entityNumber' => $f->name]);
        });

        return $this->shape($user, $id);
    }

    // ───────────── helpers ─────────────

    private function shape(AuthUser $user, string $id): array
    {
        $access = new FolderAccess($user);
        $f = $access->folder($id) ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد غير موجود', 'Folder not found');
        $count = $access->scopeDocuments(Document::query(), [$id])->where('archived', false)->count();

        return Shape::folder($f, $access, $count);
    }

    private function locked(string $id): Folder
    {
        return Folder::where('id', $id)->lockForUpdate()->first() ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد غير موجود', 'Folder not found');
    }

    private function parent(string $id): Folder
    {
        $parent = Folder::find($id) ?? throw AppError::notFound('WS_FOLDER_NOT_FOUND', 'المجلد الأب غير موجود', 'The parent folder does not exist');
        if ($parent->archived) {
            throw AppError::rule('WS_ARCHIVED', 'المجلد الأب مؤرشف — استعده أولًا', 'The parent folder is archived — restore it first');
        }

        return $parent;
    }

    /** Two live folders of the same name do not stand side by side. */
    private function nameIsFree(string $name, ?string $parentId, ?string $exceptId): void
    {
        $taken = Folder::where('name', $name)->where('archived', false)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))->exists();
        if ($taken) {
            throw AppError::conflict('WS_FOLDER_NAME_TAKEN', "يوجد مجلد باسم «{$name}» في المكان نفسه", "A folder named “{$name}” already stands in the same place");
        }
    }

    /** The access keys a payload carries, as columns (roles checked against the roles of the system). @return array<string, mixed> */
    private static function access(array $dto): array
    {
        $out = [];
        $known = null;
        foreach (['read' => ['readScope', 'readRoles'], 'write' => ['writeScope', 'writeRoles']] as $side => [$scopeKey, $rolesKey]) {
            if (! empty($dto[$scopeKey])) {
                $out["{$side}_scope"] = (string) $dto[$scopeKey];
            }
            if (array_key_exists($rolesKey, $dto)) {
                $roles = array_values(array_unique(array_filter(array_map('strval', (array) ($dto[$rolesKey] ?? [])))));
                $known ??= DB::table('roles')->pluck('key')->map(fn ($k) => (string) $k)->all();
                if ($unknown = array_diff($roles, $known)) {
                    throw AppError::validation('WS_ROLE_UNKNOWN', 'دور غير معروف: '.implode('، ', $unknown), 'Unknown role: '.implode(', ', $unknown), [['path' => $rolesKey, 'message' => 'دور غير معروف']]);
                }
                sort($roles);
                $out["{$side}_roles"] = $roles ?: null;
            }
        }

        return $out;
    }
}
