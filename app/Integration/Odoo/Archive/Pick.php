<?php

namespace App\Integration\Odoo\Archive;

use App\Integration\Odoo\Projection\Val;

/**
 * Reads a VALUE of the definitions (config/odoo_archive.php) out of one mirrored record:
 *   `field`          the field as Odoo returned it (a many2one stays `[id, label]`)
 *   `a|b`            the first that has a value
 *   `m2o>field`      a field of the record the many2one points at (`$context['via'][m2o][id]` — see ArchiveWriter)
 *   `@display`, `@id` Odoo's display name of the record, its id
 *   `flag:x`         '1' when x is set or positive, otherwise '0'
 *   `code:x`         the first word of x (the code in "201002 Payables")
 *   `id:x`           the id of a many2one, as text
 *   `tmpl:x`         the product (variant) behind the product template x points at
 * and turns what it finds into what a column of the index holds.
 */
final class Pick
{
    /** @param  array{id?:int, display?:?string, via?:array<string, array<int, array>>, variant?:callable}  $context */
    public static function raw(?string $spec, array $data, array $context = []): mixed
    {
        if ($spec === null || $spec === '') {
            return null;
        }
        foreach (explode('|', $spec) as $alternative) {
            $fn = null;
            if (preg_match('/^(flag|code|id|tmpl):(.+)$/', $alternative, $m)) {
                [$fn, $alternative] = [$m[1], $m[2]];
            }
            $value = self::path($alternative, $data, $context);
            $value = match ($fn) {
                'flag' => $value === true || (is_numeric($value) && $value > 0) || (is_string($value) && $value !== '' && ! is_numeric($value)) || (is_array($value) && $value !== []) ? '1' : '0',
                'code' => ($text = self::text($value, 250)) === null ? null : (preg_split('/\s+/u', $text)[0] ?? null),
                'id' => ($id = Val::id($value)) ? (string) $id : null,
                'tmpl' => ($id = Val::id($value)) && isset($context['variant']) ? ($context['variant'])($id) : null,
                default => $value,
            };
            if (self::filled($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function path(string $path, array $data, array $context): mixed
    {
        if ($path === '@display') {
            $name = $context['display'] ?? null;

            // "model.name,12" is what Odoo prints for a record that has no name of its own
            return is_string($name) && ! preg_match('/^[a-z0-9_.]+,\d+$/', $name) ? $name : null;
        }
        if ($path === '@id') {
            return $context['id'] ?? null;
        }
        if (str_contains($path, '>')) {
            [$field, $far] = explode('>', $path, 2);
            $id = Val::id($data[$field] ?? null);

            return $id ? ($context['via'][$field][$id][$far] ?? null) : null;
        }

        return $data[$path] ?? null;
    }

    /** Has Odoo a value there? `false` is "empty" for every type but boolean — and a boolean false tells nothing here. */
    public static function filled(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== [];
    }

    /** The text of a value: the label of a many2one, the words of an html note on one line. */
    public static function text(mixed $value, int $max): ?string
    {
        if (is_array($value)) {
            $value = Val::label($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : null;
        } elseif (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (str_contains($value, '<') && preg_match('/<\/?[a-zA-Z][^>]*>/', $value)) {
            $value = (string) Val::plain($value, $max * 2);
        }
        // one line, no control characters (PostgreSQL refuses a NUL in text)
        $value = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** A day or a moment as Odoo prints it → `Y-m-d H:i:s` (UTC), or null. */
    public static function moment(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/', $value, $m)) {
            return null;
        }
        // a date no calendar has (or one outside what a database stores) is not a date of the document
        if ((int) $m[1] < 1000 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (isset($m[4]) && ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59))) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]} ".(isset($m[4]) ? "{$m[4]}:{$m[5]}:{$m[6]}" : '00:00:00');
    }

    public static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null;
    }

    /** @return array{0:?int, 1:?string} the id and the label of a many2one */
    public static function ref(mixed $value, int $max = 255): array
    {
        $id = Val::id($value);

        return [$id, $id ? self::text(Val::label($value), $max) : null];
    }
}
