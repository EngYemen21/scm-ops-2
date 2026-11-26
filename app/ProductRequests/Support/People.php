<?php

namespace App\ProductRequests\Support;

use Illuminate\Support\Facades\DB;

/**
 * The people named on requests: who asked, who reviews, who decided. A person is an OPS user (`*_id`) or — for the
 * history that came from Odoo — a name kept as it was, with the login there. Users are read once per answer.
 */
final class People
{
    /** Service accounts are never people of this section. */
    public const SERVICE_PREFIX = 'svc.';

    /** @var array<string, array{id:string, username:string, nameAr:string, nameEn:string, active:bool}> */
    private array $users = [];

    /** @param  array<int, ?string>  $ids */
    public function load(array $ids): self
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => $id === null ? null : (string) $id, $ids), fn ($id) => $id !== null && $id !== '' && ! isset($this->users[$id]))));
        if ($ids) {
            foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'username', 'name_ar', 'name_en', 'active']) as $u) {
                $this->users[(string) $u->id] = ['id' => (string) $u->id, 'username' => (string) $u->username, 'nameAr' => (string) $u->name_ar, 'nameEn' => (string) ($u->name_en ?: $u->name_ar), 'active' => (bool) $u->active];
            }
        }

        return $this;
    }

    /**
     * @return array{id:?string, nameAr:string, nameEn:string, username:?string, external:bool}|null `external`: known by name only (a person of Odoo with no OPS user)
     */
    public function person(?string $id, ?string $name, ?string $login = null): ?array
    {
        if ($id !== null && $id !== '') {
            $this->load([$id]);
            if ($u = $this->users[$id] ?? null) {
                return ['id' => $u['id'], 'nameAr' => $u['nameAr'], 'nameEn' => $u['nameEn'], 'username' => $u['username'], 'external' => false];
            }
        }
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        return ['id' => null, 'nameAr' => $name, 'nameEn' => $name, 'username' => $login, 'external' => true];
    }
}
