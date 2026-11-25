<?php

namespace App\Commissions\Support;

use App\Support\AppError;

/**
 * Tiered rates. A list of tiers is `[{ upTo: 100000, rate: 1 }, { upTo: 250000, rate: 1.5 }, { upTo: null, rate: 2 }]`:
 * ascending ceilings on the month's base, the last one open. An amount exactly on a ceiling belongs to the tier below it.
 *
 *   whole        the tier the month's total reaches pays on the whole base          → rateAt()
 *   progressive  each slice of the base earns the rate of its own tier              → cumulative()
 *
 * Pure arithmetic: no database, no rounding (the caller rounds a line's commission to the halala).
 */
final class Tiers
{
    /**
     * Checks and orders what a person typed.
     *
     * @param  array<int, array{upTo?: mixed, rate?: mixed}>  $tiers
     * @return array<int, array{upTo: ?float, rate: float}>
     */
    public static function normalise(array $tiers, string $path = 'tiers'): array
    {
        $out = [];
        foreach (array_values($tiers) as $i => $t) {
            $rate = is_array($t) ? ($t['rate'] ?? null) : null;
            $upTo = is_array($t) ? ($t['upTo'] ?? null) : null;
            if (! is_numeric($rate) || (float) $rate < 0 || (float) $rate > 100) {
                throw self::bad("{$path}.{$i}.rate", 'نسبة الشريحة بين 0 و100', 'A tier rate is between 0 and 100');
            }
            if ($upTo !== null && $upTo !== '' && (! is_numeric($upTo) || (float) $upTo <= 0)) {
                throw self::bad("{$path}.{$i}.upTo", 'سقف الشريحة مبلغ أكبر من صفر، أو فارغ للشريحة الأخيرة', 'A tier ceiling is an amount above zero, or empty for the last tier');
            }
            $out[] = ['upTo' => $upTo === null || $upTo === '' ? null : round((float) $upTo, 2), 'rate' => round((float) $rate, 4)];
        }
        if (! $out) {
            throw self::bad($path, 'أضف شريحة واحدة على الأقل', 'Add at least one tier');
        }
        usort($out, fn (array $a, array $b) => ($a['upTo'] ?? INF) <=> ($b['upTo'] ?? INF));
        $open = array_filter($out, fn (array $t) => $t['upTo'] === null);
        if (count($open) !== 1) {
            throw self::bad($path, 'شريحة واحدة فقط — الأخيرة — تكون بلا سقف', 'Exactly one tier — the last — has no ceiling');
        }
        for ($i = 1; $i < count($out) - 1; $i++) {
            if ($out[$i]['upTo'] === $out[$i - 1]['upTo']) {
                throw self::bad("{$path}.{$i}.upTo", 'سقفان متساويان في شريحتين', 'Two tiers share one ceiling');
            }
        }

        return $out;
    }

    /**
     * The tier an amount falls in.
     *
     * @param  array<int, array{upTo: ?float, rate: float}>  $tiers
     * @return array{index: int, from: float, upTo: ?float, rate: float}
     */
    public static function tierAt(array $tiers, float $amount): array
    {
        $from = 0.0;
        foreach (array_values($tiers) as $i => $t) {
            $upTo = isset($t['upTo']) ? (float) $t['upTo'] : null;
            if ($upTo === null || $amount <= $upTo + 0.00001) {
                return ['index' => $i, 'from' => $from, 'upTo' => $upTo, 'rate' => (float) $t['rate']];
            }
            $from = $upTo;
        }
        $last = end($tiers) ?: ['rate' => 0.0];

        return ['index' => max(0, count($tiers) - 1), 'from' => $from, 'upTo' => null, 'rate' => (float) $last['rate']];
    }

    /** The rate that applies to everything when the month's base totals `$total` (a total of zero or less sits in the first tier). */
    public static function rateAt(array $tiers, float $total): float
    {
        return self::tierAt($tiers, max(0.0, $total))['rate'];
    }

    /**
     * The commission on a cumulative base of `$amount`, each slice at the rate of its own tier. What goes below zero
     * (returns larger than the sales so far) is taken back at the first tier's rate. A line's share is the difference
     * of this function before and after the line.
     */
    public static function cumulative(array $tiers, float $amount): float
    {
        $tiers = array_values($tiers);
        if (! $tiers) {
            return 0.0;
        }
        if ($amount <= 0) {
            return $amount * (float) $tiers[0]['rate'] / 100;
        }
        $sum = 0.0;
        $from = 0.0;
        foreach ($tiers as $t) {
            $upTo = isset($t['upTo']) ? (float) $t['upTo'] : null;
            $top = $upTo === null ? $amount : min($amount, $upTo);
            if ($top > $from) {
                $sum += ($top - $from) * (float) $t['rate'] / 100;
            }
            if ($upTo === null || $amount <= $upTo) {
                break;
            }
            $from = $upTo;
        }

        return $sum;
    }

    private static function bad(string $path, string $ar, string $en): AppError
    {
        return AppError::validation('COMMISSION_TIERS', $ar, $en, [['path' => $path, 'message' => $ar]]);
    }
}
