<?php

namespace App\Hr\Support;

use App\Models\Notification;
use App\Support\AppError;
use App\Support\AuthUser;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Small things every service of the section needs: dates as the API speaks them, who may see what, who is told. */
final class Hr
{
    /** A date column as the API returns it (`Y-m-d`), or null. */
    public static function day(mixed $d): ?string
    {
        if ($d === null || $d === '') {
            return null;
        }

        return $d instanceof CarbonInterface ? $d->format('Y-m-d') : substr((string) $d, 0, 10);
    }

    /** A moment as the API returns it (ISO-8601), or null. */
    public static function at(mixed $d): ?string
    {
        return $d instanceof CarbonInterface ? $d->toJSON() : ($d ? (string) $d : null);
    }

    /** A date a person sent (already validated as a date) as `Y-m-d`; null stays null. */
    public static function date(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : Carbon::parse((string) $v)->format('Y-m-d');
    }

    /** Whole days from today to the date; negative when it is past. */
    public static function daysTo(mixed $date): ?int
    {
        $day = self::day($date);

        return $day === null ? null : (int) round(Carbon::today()->diffInDays(Carbon::parse($day)->startOfDay(), false));
    }

    /** Trimmed text, '' as null, cut to what the column holds. */
    public static function text(mixed $v, int $max = 250): ?string
    {
        $s = is_scalar($v) ? trim((string) $v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    public static function money(mixed $v): float
    {
        return round((float) $v, 2);
    }

    /**
     * The values of `$new` (column → value) that differ from what the row holds — dates as days, booleans as
     * booleans, the `$money` columns to the cent, everything else as text (a staff number "007" is not 7).
     *
     * @param  string[]  $money
     * @return array<string, mixed>
     */
    public static function diff(Model $row, array $new, array $money = []): array
    {
        $out = [];
        foreach ($new as $column => $value) {
            $current = $row->getAttribute($column);
            if ($current instanceof CarbonInterface) { // a day against a day, a moment against a moment
                $current = is_string($value) && strlen($value) > 10 ? $current->format('Y-m-d H:i:s.v') : $current->format('Y-m-d');
            }
            $same = match (true) {
                in_array($column, $money, true) => number_format((float) $current, 2, '.', '') === number_format((float) $value, 2, '.', ''),
                is_bool($current) || is_bool($value) => (bool) $current === (bool) $value,
                is_float($current) || is_float($value) => abs((float) $current - (float) $value) < 0.00001,
                default => (string) ($current ?? '') === (string) ($value ?? ''),
            };
            if (! $same) {
                $out[$column] = $value;
            }
        }

        return $out;
    }

    /**
     * Refuses a change of what Odoo owns on a row that is Odoo's right now. `$owned` are the columns Odoo holds; the
     * others (links, notes, what OPS adds) stay editable.
     *
     * @param  array<string, mixed>  $changes  column → new value
     * @param  string[]  $owned
     */
    public static function guardSource(Model $row, array $changes, array $owned, string $entity, string $whatAr, string $whatEn): void
    {
        if (! Source::locked($row->getAttribute('source_system'))) {
            return;
        }
        $hit = array_values(array_intersect(array_keys($changes), $owned));
        if ($hit) {
            throw Source::refusal($entity, $whatAr, $whatEn, array_map(fn (string $c) => Str::camel($c), $hit));
        }
    }

    public static function seesPrivate(AuthUser $user): bool
    {
        return $user->can('hr.private');
    }

    /** May read the register of documents and what is due (the private ones need `hr.private` on top). */
    public static function seesDocuments(AuthUser $user): bool
    {
        return $user->can('hr.manage') || $user->can('hr.private');
    }

    public static function needPrivate(AuthUser $user): void
    {
        if (! self::seesPrivate($user)) {
            throw AppError::forbidden('HR_PRIVATE', 'هذا من الملف الشخصي للموظف — يحتاج صلاحية hr.private', 'This belongs to the personal file — it needs hr.private');
        }
    }

    /** @return string[] keys of the roles that hold a permission (who a notification about it goes to) */
    public static function rolesWith(string $permission): array
    {
        return DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.key', $permission)->distinct()->pluck('roles.key')->all();
    }

    /** A notification for the holders of a permission. No activity-feed entry on purpose: the feed is read by other roles too. */
    public static function tellRoles(string $permission, string $ar, string $en, string $entityType, ?string $entityId, ?string $entityNumber): void
    {
        foreach (self::rolesWith($permission) as $role) {
            Notification::create(['role_key' => $role, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => $entityType, 'entity_id' => $entityId, 'entity_number' => $entityNumber]);
        }
    }

    /** A notification for one person. */
    public static function tellUser(?string $userId, string $ar, string $en, string $entityType, ?string $entityId, ?string $entityNumber): void
    {
        if ($userId) {
            Notification::create(['user_id' => $userId, 'text_ar' => $ar, 'text_en' => $en, 'entity_type' => $entityType, 'entity_id' => $entityId, 'entity_number' => $entityNumber]);
        }
    }
}
