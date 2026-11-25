<?php

namespace App\Tax\Support;

use InvalidArgumentException;

/**
 * Money of the tax section is counted in halalas (whole numbers): a figure is read from its decimal text exactly,
 * added and compared as an integer, and written back as a decimal text with two places. A percentage is applied once,
 * rounded half away from zero, on the amount it belongs to — never on a figure that was already rounded for display.
 */
final class Money
{
    /** A decimal text ("1234.50"), an integer of riyals or a float → halalas. More than two places are rounded half away from zero. */
    public static function minor(mixed $v): int
    {
        if ($v === null || $v === '' || $v === false) {
            return 0;
        }
        if (is_int($v)) {
            return $v * 100;
        }
        // a float carries binary noise (0.285 is 0.28499999…): four places first, then the rounding of the third
        $s = is_float($v) ? number_format($v, 4, '.', '') : trim((string) $v);
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("not a decimal amount: {$s}");
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $minor = (int) ($m[2] === '' ? '0' : $m[2]) * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** Halalas → the decimal text of the API and of the database ("-0.05", "1234.50"). */
    public static function text(int $minor): string
    {
        $abs = abs($minor);

        return ($minor < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** `$ratePct` percent of an amount in halalas, rounded once, half away from zero. The rate is a decimal text ("15", "2.5"). */
    public static function percent(int $minor, string|int|float $ratePct): int
    {
        $rate = is_float($ratePct) ? number_format($ratePct, 4, '.', '') : (string) $ratePct;
        $exact = bcdiv(bcmul((string) $minor, $rate, 8), '100', 8);

        return (int) bcadd($exact, bccomp($exact, '0', 8) < 0 ? '-0.5' : '0.5', 0);
    }

    /** A rate as the text people and documents read: "15", "2.5", "0". */
    public static function rate(mixed $ratePct): string
    {
        $s = is_float($ratePct) ? number_format($ratePct, 4, '.', '') : trim((string) ($ratePct ?? '0'));
        if (! is_numeric($s)) {
            return '0';
        }
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    /** Two rates are the same rate (15 = 15.0000). */
    public static function sameRate(mixed $a, mixed $b): bool
    {
        return bccomp(self::rate($a), self::rate($b), 4) === 0;
    }
}
