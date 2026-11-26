<?php

namespace App\Approvals\Services;

use App\Support\AuthUser;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The people of the approvals section: requesters and approvers.
 *
 * A person is an OPS user (`<x>_id`). The history that came from Odoo also names people who have no OPS user yet: they
 * are kept by name (`<x>_name`) with their Odoo login (`<x>_login`, lower-case). Such a person IS the OPS user whose
 * e-mail or username equals that login — recognised here at reading time, so the day an account is opened for him his
 * requests and the decisions waiting for him are already his, with nothing to migrate. Identity only: never a name.
 */
class People
{
    public const PAGE_PERMISSION = 'page.approvals';

    /** @var array<string, string[]> user id → the logins of Odoo that are his */
    private array $identities = [];

    /** @var array<string, array{nameAr:string, nameEn:string, username:string, active:bool}|null> */
    private array $users = [];

    /**
     * The Odoo logins this user answers to: his e-mail and his username, lower-case — except one another OPS user
     * carries too (then it identifies nobody).
     *
     * @return string[]
     */
    public function identities(AuthUser|string $user): array
    {
        $id = $user instanceof AuthUser ? $user->id : $user;
        if (isset($this->identities[$id])) {
            return $this->identities[$id];
        }
        $row = DB::table('users')->where('id', $id)->first(['email', 'username']);
        $own = array_values(array_unique(array_filter([self::key($row?->email), self::key($row?->username)])));
        if ($own) {
            $taken = DB::table('users')->where('id', '!=', $id)->where(fn ($w) => $w->whereIn('email', $own)->orWhereIn('username', $own))->get(['email', 'username']);
            foreach ($taken as $other) {
                $own = array_values(array_diff($own, [self::key($other->email), self::key($other->username)]));
            }
        }

        return $this->identities[$id] = $own;
    }

    /** Narrows a query (Eloquent or plain) to the rows whose person (`{$prefix}_id` / `{$prefix}_login`) is this user. */
    public function mine(Builder $query, AuthUser $user, string $prefix, ?string $table = null): Builder
    {
        $col = fn (string $suffix) => ($table ? "{$table}." : '')."{$prefix}_{$suffix}";
        $logins = $this->identities($user);

        return $query->where(function ($w) use ($user, $col, $logins) {
            $w->where($col('id'), $user->id);
            if ($logins) {
                $w->orWhere(fn ($x) => $x->whereNull($col('id'))->whereIn($col('login'), $logins));
            }
        });
    }

    public function isMine(AuthUser $user, ?string $id, ?string $login): bool
    {
        if ($id !== null && $id !== '') {
            return strcasecmp($id, $user->id) === 0;
        }

        return $login !== null && $login !== '' && in_array(self::key($login), $this->identities($user), true);
    }

    /** The OPS user a kept person is today: the linked one, otherwise the one user whose e-mail or username is his login. */
    public function userId(?string $id, ?string $login): ?string
    {
        if ($id !== null && $id !== '') {
            return $id;
        }
        $login = self::key($login);
        if ($login === null) {
            return null;
        }
        $ids = DB::table('users')->where('email', $login)->orWhere('username', $login)->limit(2)->pluck('id')->all();

        return count($ids) === 1 ? (string) $ids[0] : null;
    }

    /** Reads the users a page needs in one query. @param  array<int, ?string>  $ids */
    public function load(array $ids): void
    {
        $missing = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null && $id !== '' && ! array_key_exists($id, $this->users))));
        if (! $missing) {
            return;
        }
        $this->users += array_fill_keys($missing, null); // an id nobody carries any more is asked for once, not on every row
        foreach (DB::table('users')->whereIn('id', $missing)->get(['id', 'username', 'name_ar', 'name_en', 'active']) as $u) {
            $this->users[$u->id] = ['nameAr' => $u->name_ar, 'nameEn' => $u->name_en, 'username' => $u->username, 'active' => (bool) $u->active];
        }
    }

    /**
     * A person as the client shows him: the OPS user's names when he is one, otherwise the name that was kept.
     *
     * @return array{id:?string, nameAr:string, nameEn:string, username:?string, linked:bool}|null
     */
    public function present(?string $id, ?string $name, ?string $login = null): ?array
    {
        if (($id === null || $id === '') && ($name === null || $name === '') && ($login === null || $login === '')) {
            return null;
        }
        if ($id !== null && $id !== '') {
            $this->load([$id]);
            if ($u = $this->users[$id] ?? null) {
                return ['id' => $id, 'nameAr' => $u['nameAr'], 'nameEn' => $u['nameEn'], 'username' => $u['username'], 'linked' => true];
            }
        }
        $kept = (string) ($name ?: $login ?: '—');

        return ['id' => $id ?: null, 'nameAr' => $kept, 'nameEn' => $kept, 'username' => null, 'linked' => false];
    }

    /** An active user by id or username — the person named in a form. */
    public function find(string $idOrUsername): ?object
    {
        $key = trim($idOrUsername);
        if ($key === '') {
            return null;
        }

        return DB::table('users')->where('active', true)->where(fn ($w) => $w->where('id', $key)->orWhere('username', $key))->first(['id', 'username', 'name_ar', 'name_en']);
    }

    /** May this user open the approvals section (and so be asked for a decision)? */
    public function canOpen(string $userId): bool
    {
        return $this->openers()->where('users.id', $userId)->exists();
    }

    /**
     * The users who can be named as approvers: active, able to open the section, not a service account.
     *
     * @return array<int, array{id:string, username:string, nameAr:string, nameEn:string}>
     */
    public function approvers(): array
    {
        return $this->openers()->orderBy('users.name_ar')->orderBy('users.id')->get(['users.id', 'users.username', 'users.name_ar', 'users.name_en'])
            ->map(fn ($u) => ['id' => $u->id, 'username' => $u->username, 'nameAr' => $u->name_ar, 'nameEn' => $u->name_en])->all();
    }

    private function openers()
    {
        return DB::table('users')->where('users.active', true)->where('users.username', 'not like', 'svc.%')
            ->whereExists(fn ($q) => $q->select(DB::raw('1'))->from('user_roles')
                ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->whereColumn('user_roles.user_id', 'users.id')->where('permissions.key', self::PAGE_PERMISSION));
    }

    /** A login or an e-mail as it is compared: trimmed, lower-case; null when empty. */
    public static function key(mixed $value): ?string
    {
        $s = is_string($value) ? mb_strtolower(trim($value)) : '';

        return $s === '' ? null : $s;
    }
}
