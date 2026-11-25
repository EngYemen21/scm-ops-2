<?php

namespace App\Workspace\Support;

use App\Models\Notification;
use App\Models\User;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The people of the workspace: who may be named on a ticket, a project or a task, how a person is written out, and how
 * one of them is told. A person is an OPS user or — for somebody Odoo names who has no OPS user — a name: such a name is
 * shown and can be taken off a list, it is never typed in here (only an OPS user can be chosen).
 */
final class People
{
    /** Service accounts (`svc.<system>`) work on behalf of another system: nobody names them on a record. */
    public const SERVICE_PREFIX = 'svc.';

    public const COLUMNS = 'id,username,name_ar,name_en';

    /**
     * The users who can open the section — active holders of `page.workspace` — for the pickers of the forms.
     *
     * @return array<int, array{id:string, username:string, nameAr:string, nameEn:string}>
     */
    public static function users(): array
    {
        return DB::table('users as u')
            ->join('user_roles as ur', 'ur.user_id', '=', 'u.id')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('p.key', 'page.workspace')->where('u.active', true)->where('u.username', 'not like', self::SERVICE_PREFIX.'%')
            ->distinct()->orderBy('u.name_ar')->orderBy('u.id')
            ->get(['u.id', 'u.username', 'u.name_ar', 'u.name_en'])
            ->map(fn ($u) => ['id' => (string) $u->id, 'username' => (string) $u->username, 'nameAr' => (string) $u->name_ar, 'nameEn' => (string) $u->name_en])->all();
    }

    /** Somebody who may be named on a record: an existing, active user that is not a service account. */
    public static function assignable(string $idOrUsername, string $field): User
    {
        $u = User::where('id', $idOrUsername)->orWhere('username', $idOrUsername)->first();
        if (! $u || ! $u->active || str_starts_with((string) $u->username, self::SERVICE_PREFIX)) {
            throw AppError::validation('WS_USER_UNKNOWN', 'المستخدم المختار غير موجود أو غير نشط', 'The chosen user does not exist or is not active', [['path' => $field, 'message' => 'المستخدم غير موجود أو غير نشط']]);
        }

        return $u;
    }

    /**
     * A person as the client reads it. `key` is what lists filter by and forms send back: the user id, or
     * `name:<the name>` for a name kept from Odoo.
     *
     * @return array{key:string, id:?string, username:?string, nameAr:string, nameEn:string, external:bool}|null
     */
    public static function shape(?string $userId, ?object $user, ?string $name): ?array
    {
        if ($userId) {
            return ['key' => $userId, 'id' => $userId, 'username' => $user?->username, 'nameAr' => (string) ($user?->name_ar ?? $name ?? '—'), 'nameEn' => (string) ($user?->name_en ?? $name ?? '—'), 'external' => false];
        }
        if ($name === null || $name === '') {
            return null;
        }

        return ['key' => 'name:'.$name, 'id' => null, 'username' => null, 'nameAr' => $name, 'nameEn' => $name, 'external' => true];
    }

    /** A row of a people list (a member, an assignee) with its `user` loaded. */
    public static function ofRow(object $row): ?array
    {
        return self::shape($row->user_id ? (string) $row->user_id : null, $row->user_id ? $row->user : null, $row->person_name);
    }

    /** @param  iterable<object>  $rows  @return array<int, array> people in the order of their names */
    public static function ofRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($p = self::ofRow($row)) {
                $out[] = $p;
            }
        }
        usort($out, fn ($a, $b) => [$a['external'], $a['nameAr'], $a['key']] <=> [$b['external'], $b['nameAr'], $b['key']]);

        return $out;
    }

    /**
     * Makes a people list (members of a team or a project, assignees of a task) what the keys say: user ids are added
     * or kept, a `name:<x>` keeps a name the list already holds, everything else leaves the list.
     *
     * @param  class-string<Model>  $class
     * @param  string[]  $keys
     * @return bool whether the list changed
     */
    public static function sync(string $class, string $foreignKey, string $ownerId, array $keys, string $field): bool
    {
        $users = $names = [];
        foreach (array_unique(array_map('strval', $keys)) as $key) {
            if (str_starts_with($key, 'name:')) {
                $names[substr($key, 5)] = true;
            } elseif ($key !== '') {
                $users[(string) self::assignable($key, $field)->id] = true;
            }
        }
        $changed = false;
        foreach ($class::where($foreignKey, $ownerId)->get() as $row) {
            $keep = $row->user_id ? isset($users[(string) $row->user_id]) : isset($names[(string) $row->person_name]);
            if (! $keep) {
                $row->delete();
                $changed = true;
            } elseif ($row->user_id) {
                unset($users[(string) $row->user_id]); // there already
            }
        }
        foreach (array_keys($users) as $userId) {
            $class::create([$foreignKey => $ownerId, 'user_id' => $userId]);
            $changed = true;
        }

        return $changed;
    }

    /** The names of a people list, for the audit trail and the timeline. @return string[] */
    public static function names(string $class, string $foreignKey, string $ownerId): array
    {
        return array_column(self::ofRows($class::with('user:'.self::COLUMNS)->where($foreignKey, $ownerId)->get()), 'nameAr');
    }

    /**
     * A filter by one person: `me`, `none`, a user id, or `name:<x>` for a name kept from Odoo.
     * `$idColumn` / `$nameColumn` are the two columns that hold a single person.
     */
    public static function filter(Builder $query, string $value, string $idColumn, string $nameColumn, AuthUser $user): void
    {
        match (true) {
            $value === 'me' => $query->where($idColumn, $user->id),
            $value === 'none' => $query->whereNull($idColumn)->whereNull($nameColumn),
            str_starts_with($value, 'name:') => $query->whereNull($idColumn)->where($nameColumn, substr($value, 5)),
            default => $query->where($idColumn, $value),
        };
    }

    /** A personal notification (the bell of that user); never to the person who did the thing. */
    public static function tell(?string $userId, ?AuthUser $by, string $entityType, string $entityId, ?string $number, string $ar, string $en): void
    {
        if (! $userId || ($by && $userId === $by->id)) {
            return;
        }
        Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => $entityType, 'entity_id' => $entityId, 'entity_number' => $number, 'at' => now()]);
    }

    /** The roles of the system, for the access lists of folders. @return array<int, array{key:string, nameAr:string, nameEn:string}> */
    public static function roles(): array
    {
        return DB::table('roles')->orderBy('name_ar')->orderBy('key')->get(['key', 'name_ar', 'name_en'])
            ->map(fn ($r) => ['key' => (string) $r->key, 'nameAr' => (string) $r->name_ar, 'nameEn' => (string) $r->name_en])->all();
    }
}
