<?php

namespace App\Planning\Engine;

/** The arithmetic every figure of a run goes through, so two computations of the same input print the same numbers. */
final class Num
{
    /** A quantity: three decimals. */
    public static function q(float|int $v): float
    {
        return round((float) $v, 3) + 0.0;
    }

    /** Money, a score, a percentage: two decimals. */
    public static function m(float|int $v): float
    {
        return round((float) $v, 2) + 0.0;
    }

    /** A price: four decimals, as the price service gives it. */
    public static function p(float|int $v): float
    {
        return round((float) $v, 4) + 0.0;
    }

    /** @param float[] $xs */
    public static function mean(array $xs): float
    {
        return $xs ? array_sum($xs) / count($xs) : 0.0;
    }

    /** The sample deviation (n − 1); 0 for fewer than two values. @param float[] $xs */
    public static function stdev(array $xs): float
    {
        $n = count($xs);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($xs) / $n;
        $sum = 0.0;
        foreach ($xs as $x) {
            $sum += ($x - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }

    /** Deviation ÷ mean; 0 when the mean is not positive. @param float[] $xs */
    public static function cv(array $xs): float
    {
        $mean = self::mean($xs);

        return $mean > 0 ? self::stdev($xs) / $mean : 0.0;
    }

    public static function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    /** Half a day and more is one day: 7.5 → 8 (always away from zero, whatever the platform's default). */
    public static function days(float $v): int
    {
        return (int) floor($v + 0.5);
    }

    /**
     * What is ordered for a net requirement: at least the vendor's minimum, in whole multiples of the order multiple,
     * and a whole number of units (OPS documents carry whole units).
     */
    public static function orderQty(float $net, float $moq = 0.0, float $multiple = 1.0): int
    {
        if ($net <= 0) {
            return 0;
        }
        $qty = max($net, $moq);
        if ($multiple > 1) {
            $qty = ceil($qty / $multiple - 1e-9) * $multiple;
        }

        return (int) ceil($qty - 1e-9);
    }
}
