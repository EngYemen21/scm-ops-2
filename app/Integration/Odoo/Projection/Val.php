<?php

namespace App\Integration\Odoo\Projection;

use Illuminate\Support\Carbon;

/**
 * Reading the values of a mirrored Odoo record (`odoo_records.data`) the way Odoo writes them: `false` means "empty"
 * for every type but boolean, a many2one is `[id, "label"]`, a many2many is a list of ids, a datetime is UTC text.
 */
final class Val
{
    /** The id of a many2one (`[id, label]`), or of a plain integer reference. */
    public static function id(mixed $v): ?int
    {
        if (is_array($v)) {
            $v = $v[0] ?? null;
        }

        return is_int($v) || (is_string($v) && ctype_digit($v)) ? ((int) $v ?: null) : null;
    }

    /** The label Odoo printed beside the id of a many2one. */
    public static function label(mixed $v): ?string
    {
        return is_array($v) && isset($v[1]) && is_string($v[1]) && trim($v[1]) !== '' ? trim($v[1]) : null;
    }

    /** @return int[] the ids of a many2many / one2many value */
    public static function ids(mixed $v): array
    {
        return is_array($v) ? array_values(array_filter(array_map(fn ($x) => is_int($x) ? $x : null, $v))) : [];
    }

    public static function text(mixed $v, int $max = 250): ?string
    {
        $s = is_string($v) ? trim($v) : (is_int($v) || is_float($v) ? (string) $v : '');

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** The text of an html value (a note, a description): tags dropped, entities decoded, blank lines collapsed. */
    public static function plain(mixed $v, int $max = 4000): ?string
    {
        if (! is_string($v) || trim($v) === '') {
            return null;
        }
        $s = preg_replace('/<(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $v);
        $s = html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = trim(preg_replace("/[ \t]+\n/", "\n", preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]{2,}/', ' ', $s))));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    public static function float(mixed $v): float
    {
        return is_numeric($v) ? (float) $v : 0.0;
    }

    public static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1';
    }

    /** An Odoo date (`Y-m-d`) or the day of a datetime, as `Y-m-d`. */
    public static function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? substr($v, 0, 10) : null;
    }

    /** An Odoo datetime (`Y-m-d H:i:s`, UTC) as a moment. */
    public static function at(mixed $v): ?Carbon
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?/', $v)) {
            return null;
        }
        try {
            return Carbon::parse($v, 'UTC')->setTimezone(config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
