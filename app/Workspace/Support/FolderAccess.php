<?php

namespace App\Workspace\Support;

use App\Support\AuthUser;
use App\Workspace\Models\Document;
use App\Workspace\Models\Folder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who reads and who writes in the documents tree — said by role, folder by folder.
 *
 *   WRITES a folder (upload, new version, rename, move, archive its documents): the documents manager (`doc.manage`),
 *          everybody who opens the section when `write_scope = all`, otherwise the roles of `write_roles`.
 *   READS  a folder: whoever writes it, everybody when `read_scope = all`, otherwise the roles of `read_roles`.
 *          A list of roles that is empty names nobody: the manager alone.
 *   `own_write` narrows a writer to the documents he owns; `own_read` narrows to his own documents everybody who does
 *   not write the whole folder. The documents manager is never narrowed.
 *
 * A folder stands on its own: reading a folder does not need reading its parent (the tree then shows it at the root).
 * The folders of an installation are a few dozen: they are read once per request and the rest is arithmetic.
 */
final class FolderAccess
{
    /** @var Collection<string, Folder>|null */
    private ?Collection $all = null;

    public function __construct(public readonly AuthUser $user) {}

    public function manages(): bool
    {
        return $this->user->can('doc.manage');
    }

    /** @return Collection<string, Folder> every folder, by id */
    public function all(): Collection
    {
        return $this->all ??= Folder::query()->orderBy('sequence')->orderBy('name')->orderBy('id')->get()->keyBy(fn (Folder $f) => (string) $f->id);
    }

    public function folder(?string $id): ?Folder
    {
        return $id === null ? null : $this->all()->get($id);
    }

    public function canWrite(Folder $f): bool
    {
        return $this->manages() || $f->write_scope === 'all' || $this->hasRole((array) $f->write_roles);
    }

    public function canRead(Folder $f): bool
    {
        return $this->canWrite($f) || $f->read_scope === 'all' || $this->hasRole((array) $f->read_roles);
    }

    /** Does the user see every document of the folder (and not only his own)? */
    public function seesAll(Folder $f): bool
    {
        return $this->manages() || ! $f->own_read || ($this->canWrite($f) && ! $f->own_write);
    }

    /** @return Collection<string, Folder> the folders the user may read */
    public function readable(bool $withArchived = true): Collection
    {
        return $this->all()->filter(fn (Folder $f) => ($withArchived || ! $f->archived) && $this->canRead($f));
    }

    public function canReadDocument(Document $d): bool
    {
        $f = $this->folder((string) $d->folder_id);

        return $f !== null && $this->canRead($f) && ($this->seesAll($f) || $this->owns($d));
    }

    public function canWriteDocument(Document $d): bool
    {
        $f = $this->folder((string) $d->folder_id);

        return $f !== null && ($this->manages() || ($this->canWrite($f) && (! $f->own_write || $this->owns($d))));
    }

    /**
     * Narrows a query of documents to the ones the user may read, optionally inside some folders only.
     *
     * @param  string[]|null  $within  folder ids (null = everywhere)
     */
    public function scopeDocuments(Builder $query, ?array $within = null): Builder
    {
        $whole = $own = [];
        foreach ($this->readable() as $id => $f) {
            if ($within !== null && ! in_array($id, $within, true)) {
                continue;
            }
            if ($this->seesAll($f)) {
                $whole[] = $id;
            } else {
                $own[] = $id;
            }
        }

        return $query->where(fn (Builder $w) => $w->whereIn('folder_id', $whole)
            ->orWhere(fn (Builder $o) => $o->whereIn('folder_id', $own)->where('owner_id', $this->user->id)));
    }

    /** @return string[] the folder and everything under it */
    public function subtree(string $id): array
    {
        $children = [];
        foreach ($this->all() as $fid => $f) {
            $children[(string) ($f->parent_id ?? '')][] = $fid;
        }
        $out = [];
        $stack = [$id];
        while ($stack) {
            $at = array_pop($stack);
            if (isset($out[$at])) {
                continue; // a loop in the data never hangs the walk
            }
            $out[$at] = true;
            foreach ($children[$at] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($out);
    }

    /** From the root down to the folder: the readable ones by name. @return array<int, array{id:string, name:string}> */
    public function path(?string $id): array
    {
        $out = [];
        $seen = [];
        while ($id !== null && ! isset($seen[$id]) && ($f = $this->folder($id))) {
            $seen[$id] = true;
            if ($this->canRead($f)) {
                array_unshift($out, ['id' => $id, 'name' => $f->name]);
            }
            $id = $f->parent_id !== null ? (string) $f->parent_id : null;
        }

        return $out;
    }

    private function owns(Document $d): bool
    {
        return $d->owner_id !== null && strcasecmp((string) $d->owner_id, $this->user->id) === 0;
    }

    /** @param  array<int, mixed>  $roles */
    private function hasRole(array $roles): bool
    {
        return count(array_intersect(array_map('strval', $roles), $this->user->roles)) > 0;
    }
}
