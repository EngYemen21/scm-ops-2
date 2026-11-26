<?php

namespace App\Portal\Support;

/** Small text helpers the services of the section share. */
final class Text
{
    /** Trimmed text, or null when nothing is left. */
    public static function clean(mixed $v, int $max = 250): ?string
    {
        $s = is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** The digits of a number people type with spaces, dashes or letters around it (a VAT or registration number). */
    public static function digits(mixed $v, int $max = 40): ?string
    {
        $s = is_string($v) || is_int($v) ? substr((string) preg_replace('/\D+/', '', (string) $v), 0, $max) : '';

        return $s === '' ? null : $s;
    }

    /** A search word as a LIKE pattern: what the person typed is text, not a pattern. */
    public static function like(string $q): string
    {
        return '%'.addcslashes(trim($q), '\\%_').'%';
    }

    /** A name without the differences that do not make it another name: letter case, doubled and outer spaces. */
    public static function nameKey(?string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $name)));
    }

    /** All but the last four characters hidden — an identity number shown to somebody who does not decide on it. */
    public static function masked(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $n = mb_strlen($v);

        return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', $n - 4).mb_substr($v, -4);
    }

    /** A decimal as the API gives every amount: a string with a fixed number of places. */
    public static function money(mixed $v, int $places = 2): string
    {
        return number_format((float) $v, $places, '.', '');
    }

    /** A quantity without the zeros that say nothing: "5", "2.5", "0.125". */
    public static function qty(mixed $v): string
    {
        $s = number_format((float) $v, 3, '.', '');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** A file name that is safe to store and to offer back: no path, no control characters. */
    public static function fileName(string $name): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = (string) preg_replace('/[^\w.\-\x{0600}-\x{06FF} ]+/u', '_', $name);
        $name = mb_substr((string) preg_replace('/\s+/u', '_', trim($name)), 0, 120);

        return $name !== '' ? $name : 'file';
    }
}
