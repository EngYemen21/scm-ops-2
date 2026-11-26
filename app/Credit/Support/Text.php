<?php

namespace App\Credit\Support;

use DateTimeInterface;

/** Small text helpers the services of the section share. */
final class Text
{
    /** Trimmed text, or null when nothing is left. */
    public static function clean(mixed $v, int $max = 250): ?string
    {
        $s = is_string($v) || is_int($v) || is_float($v) ? trim((string) $v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /** A search word as a LIKE pattern: what the person typed is text, not a pattern. */
    public static function like(string $q): string
    {
        return '%'.addcslashes(trim($q), '\\%_').'%';
    }

    /** A decimal as the API gives every amount: a string with two places; null stays null. */
    public static function money(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : number_format((float) $v, 2, '.', '');
    }

    /** Hours as people set them on a stage: "8", "1.5"; null stays null. */
    public static function hours(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : round((float) $v, 2);
    }

    public static function at(?DateTimeInterface $at): ?string
    {
        return $at?->toJSON();
    }

    /** A file name that is safe to store and to send back in a header: no path, no control characters, the right extension. */
    public static function fileName(string $name, string $ext): string
    {
        $name = (string) preg_replace('/[\\\\\/]+/u', '_', $name);
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|:*?]+/u', '', $name));
        $base = trim(mb_substr((string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name), 0, 150)) ?: 'file';

        return "{$base}.{$ext}";
    }

    /**
     * An IBAN as it is kept: upper-case, no spaces. Null when the text is not a valid IBAN (ISO 13616: two letters, two
     * check digits, up to 30 letters or digits, and the whole number leaves 1 when divided by 97).
     */
    public static function iban(string $v): ?string
    {
        $iban = mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $v));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return null;
        }
        $moved = substr($iban, 4).substr($iban, 0, 4);
        $rest = 0;
        foreach (str_split($moved) as $ch) {
            $n = ctype_digit($ch) ? $ch : (string) (ord($ch) - 55); // A = 10 … Z = 35
            foreach (str_split($n) as $digit) {
                $rest = ($rest * 10 + (int) $digit) % 97;
            }
        }

        return $rest === 1 ? $iban : null;
    }
}
