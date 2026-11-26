<?php

namespace App\Credit\Support;

use App\Support\AuthUser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people of the credit section: who is asked to decide a stage, and how a person is named.
 *
 * A stage names a ROLE (an OPS role key — what a security group is in Odoo), a USER, both, or nobody. The people who
 * may then decide are the active users of that role and / or that user who ALSO hold the permission the decision
 * needs (`credit.review` or `credit.approve`; the final approval needs `credit.approve`); a stage that names nobody
 * is decided by any holder of that permission. Service accounts (`svc.*`) never decide.
 *
 * The history that came from Odoo also names people who have no OPS user: they are kept by name with their Odoo login
 * (`<x>_login`, lower-case). Such a person IS the OPS user whose e-mail or username equals that login — recognised at
 * reading time, by identity only, never by a name.
 */
class People
{
    public const SERVICE_PREFIX = 'svc.';

    /** @var array<string, array{nameAr:string, nameEn:string, username:string, active:bool}|null> */
    private array $users = [];

    /** @var array<string, string[]> user id → the Odoo logins that are his */
    private array $identities = [];

    /** @var array<string, array{0:string, 1:string}>|null role key → [Arabic, English] */
    private ?array $roles = null;

    /** @return array<int, array{key:string, nameAr:string, nameEn:string}> the roles a stage may name */
    public function roles(): array
    {
        $out = [];
        foreach ($this->roleNames() as $key => [$ar, $en]) {
            if ($key !== 'integration') {
                $out[] = ['key' => $key, 'nameAr' => $ar, 'nameEn' => $en];
            }
        }

        return $out;
    }

    public function roleExists(?string $key): bool
    {
        return $key !== null && $key !== 'integration' && isset($this->roleNames()[$key]);
    }

    /** @return array{key:string, nameAr:string, nameEn:string}|null */
    public function role(?string $key, ?string $kept = null): ?array
    {
        if ($key !== null && $key !== '' && isset($this->roleNames()[$key])) {
            return ['key' => $key, 'nameAr' => $this->roleNames()[$key][0], 'nameEn' => $this->roleNames()[$key][1]];
        }
        if ($kept !== null && $kept !== '') {
            return ['key' => null, 'nameAr' => $kept, 'nameEn' => $kept]; // a security group of Odoo no OPS role answers to yet
        }

        return $key !== null && $key !== '' ? ['key' => $key, 'nameAr' => $key, 'nameEn' => $key] : null;
    }

    /** The role whose key or name is exactly this text (letter case aside) — how a group of Odoo is recognised. */
    public function roleNamed(?string $name): ?string
    {
        $want = self::key($name);
        if ($want === null) {
            return null;
        }
        $found = [];
        foreach ($this->roleNames() as $key => [$ar, $en]) {
            if ($key !== 'integration' && in_array($want, [mb_strtolower($key), mb_strtolower($ar), mb_strtolower($en)], true)) {
                $found[] = $key;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Active users (never a service account) who hold at least one of the permissions — narrowed to a role and / or
     * joined with one named user.
     *
     * @param  string[]  $permissions
     * @return Collection<int, object{id:string, username:string, name_ar:string, name_en:string, email:?string}>
     */
    public function holders(array $permissions, ?string $role = null, ?string $userId = null, bool $nobodyNamedMeansAll = true): Collection
    {
        if ($role === null && $userId === null && ! $nobodyNamedMeansAll) {
            return collect();
        }
        $q = DB::table('users as u')->where('u.active', true)->where('u.username', 'not like', self::SERVICE_PREFIX.'%')
            ->whereExists(fn ($x) => $x->select(DB::raw('1'))->from('user_roles as ur')
                ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->whereColumn('ur.user_id', 'u.id')->whereIn('p.key', $permissions));
        if ($role !== null || $userId !== null) {
            $q->where(function ($w) use ($role, $userId) {
                if ($userId !== null) {
                    $w->orWhere('u.id', $userId);
                }
                if ($role !== null) {
                    $w->orWhereExists(fn ($x) => $x->select(DB::raw('1'))->from('user_roles as ur2')->join('roles as r', 'r.id', '=', 'ur2.role_id')
                        ->whereColumn('ur2.user_id', 'u.id')->where('r.key', $role));
                }
            });
        }

        return $q->orderBy('u.name_ar')->orderBy('u.id')->get(['u.id', 'u.username', 'u.name_ar', 'u.name_en', 'u.email']);
    }

    /** An active user by id or username — the person named in a form. */
    public function find(string $idOrUsername): ?object
    {
        $key = trim($idOrUsername);
        if ($key === '') {
            return null;
        }

        return DB::table('users')->where('active', true)->where('username', 'not like', self::SERVICE_PREFIX.'%')
            ->where(fn ($w) => $w->where('id', $key)->orWhere('username', $key))->first(['id', 'username', 'name_ar', 'name_en', 'email']);
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
    public function present(?string $id, ?string $name = null, ?string $login = null): ?array
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

    public function isMine(AuthUser $user, ?string $id, ?string $login): bool
    {
        if ($id !== null && $id !== '') {
            return strcasecmp($id, $user->id) === 0;
        }

        return $login !== null && $login !== '' && in_array(self::key($login), $this->identities($user), true);
    }

    /**
     * The Odoo logins this user answers to: his e-mail and his username, lower-case — except one another OPS user
     * carries too (then it identifies nobody).
     *
     * @return string[]
     */
    public function identities(AuthUser $user): array
    {
        if (isset($this->identities[$user->id])) {
            return $this->identities[$user->id];
        }
        $row = DB::table('users')->where('id', $user->id)->first(['email', 'username']);
        $own = array_values(array_unique(array_filter([self::key($row?->email), self::key($row?->username)])));
        if ($own) {
            foreach (DB::table('users')->where('id', '!=', $user->id)->where(fn ($w) => $w->whereIn('email', $own)->orWhereIn('username', $own))->get(['email', 'username']) as $other) {
                $own = array_values(array_diff($own, [self::key($other->email), self::key($other->username)]));
            }
        }

        return $this->identities[$user->id] = $own;
    }

    /** A login, an e-mail or a name as it is compared: trimmed, lower-case; null when empty. */
    public static function key(mixed $value): ?string
    {
        $s = is_string($value) ? mb_strtolower(trim($value)) : '';

        return $s === '' ? null : $s;
    }

    /** @return array<string, array{0:string, 1:string}> */
    private function roleNames(): array
    {
        if ($this->roles === null) {
            $this->roles = [];
            foreach (DB::table('roles')->orderBy('key')->get(['key', 'name_ar', 'name_en']) as $r) {
                $this->roles[(string) $r->key] = [(string) $r->name_ar, (string) $r->name_en];
            }
        }

        return $this->roles;
    }
}
