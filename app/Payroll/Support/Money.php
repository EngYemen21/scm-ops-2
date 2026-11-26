<?php

namespace App\Payroll\Support;

/**
 * Pay is computed in whole halalas (integers), never in floating point: a figure is rounded ONCE, half away from zero,
 * at the moment it becomes a payslip line, and every total is a sum of integers — so a payslip's lines add up to its
 * net exactly and a run's journal balances by construction.
 */
final class Money
{
    /** A decimal amount (as the database or a person gives it) in halalas. */
    public static function h(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Halalas as the decimal string the API and the database carry (`-1234.50`). */
    public static function s(int $halalas): string
    {
        return ($halalas < 0 ? '-' : '').intdiv(abs($halalas), 100).'.'.str_pad((string) (abs($halalas) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** `$halalas × $numerator ÷ $denominator`, rounded half away from zero — exact integer arithmetic. */
    public static function ratio(int $halalas, int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }
        $product = $halalas * $numerator;
        $sign = $product < 0 ? -1 : 1;

        return $sign * intdiv(2 * abs($product) + $denominator, 2 * $denominator);
    }

    /** A percentage (up to four decimals, e.g. 9.75) of an amount. */
    public static function pct(int $halalas, mixed $pct): int
    {
        return self::ratio($halalas, (int) round(((float) $pct) * 10000), 1000000);
    }

    /** A rate / factor with four decimals (e.g. 1.5 → 15000), for the hours a policy multiplies. */
    public static function factor(mixed $rate): int
    {
        return (int) round(((float) $rate) * 10000);
    }
}
