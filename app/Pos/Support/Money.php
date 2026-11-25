<?php

namespace App\Pos\Support;

/**
 * Exact money for the counter: every amount is held as an integer number of halalas while it is computed, so
 * "rounded once" really means once and totals, VAT and payments reconcile to the halala. Percentages travel as basis
 * points (15% = 1500). Only integer arithmetic below.
 */
final class Money
{
    /** An amount (string, int or float, at most what a decimal column holds) in halalas, half away from zero. */
    public static function cents(mixed $v): int
    {
        $s = is_string($v) ? trim($v) : sprintf('%.6F', (float) $v);
        if ($s === '' || ! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $s, $m)) {
            return 0;
        }
        $digits = str_pad($m[3] ?? '', 3, '0');
        $cents = ((int) ($m[2] === '' ? '0' : $m[2])) * 100 + (int) substr($digits, 0, 2);
        if ((int) $digits[2] >= 5) {
            $cents++;
        }

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** A percentage (e.g. "15", 12.5) in basis points. */
    public static function bp(mixed $pct): int
    {
        return self::cents($pct);
    }

    /** Halalas as the decimal text the API and the database speak ("1234.50"). */
    public static function str(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $a = abs($cents);

        return $sign.intdiv($a, 100).'.'.str_pad((string) ($a % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function float(int $cents): float
    {
        return $cents / 100;
    }

    /** a × b ÷ c rounded half away from zero (c > 0). */
    public static function mulDiv(int $a, int $b, int $c): int
    {
        $n = $a * $b;
        $q = intdiv($n, $c);
        $r = $n - $q * $c;
        if (abs($r) * 2 >= $c) {
            $q += $n >= 0 ? 1 : -1;
        }

        return $q;
    }

    /** The net of a line: qty × unit price less the discount (basis points), rounded once. */
    public static function lineNet(int $qty, int $unitCents, int $discountBp): int
    {
        return self::mulDiv($qty * $unitCents, 10000 - $discountBp, 10000);
    }

    /** VAT of a net amount at a rate in basis points, rounded once. */
    public static function vat(int $netCents, int $rateBp): int
    {
        return self::mulDiv($netCents, $rateBp, 10000);
    }
}
