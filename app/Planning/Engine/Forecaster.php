<?php

namespace App\Planning\Engine;

/**
 * From the demand of the past to the demand of the coming periods — the three methods that work on periods. (The
 * fourth, the reorder cadence of each customer, works on single orders: `Cadence`.) Pure: no database, no clock.
 *
 * WHAT COUNTS AS HISTORY. The periods before the first sale are not history: a product that started in the middle of
 * the window is averaged over the periods since it started ("periods used"), and a period without a sale AFTER that is
 * a real zero. Periods are compared by their daily rate (quantity ÷ days), so a month cut by the edge of the window
 * weighs what it should.
 *
 *   average   every used period counts the same: total ÷ days.
 *   wma       the last `wmaWindow` used periods, the newest weighing most (1, 2, … n): Σ weight × rate ÷ Σ weight.
 *   seasonal  each coming period takes what was sold on the SAME DATES one year earlier; with "scale to the current
 *             level", multiplied by (the last 13 weeks ÷ the same 13 weeks a year earlier), kept between 0.2 and 5.
 *             Where the window does not reach back a year, or nothing was sold on those dates, it falls back to the
 *             weighted moving average and says so.
 * Every method ends with the growth factor (1 + growth %). A forecast is never negative.
 */
final class Forecaster
{
    public const PERIOD_METHODS = ['average', 'wma', 'seasonal'];

    /** Days of "the current level" when a seasonal forecast is scaled, and the limits of that scale. */
    public const SCALE_WINDOW = 91;

    public const SCALE_MIN = 0.2;

    public const SCALE_MAX = 5.0;

    /**
     * @param  array<string, float>  $daily  net demand per day inside the history window
     * @param  Period[]  $history
     * @param  Period[]  $horizon
     * @param  array{wmaWindow?:int, growthPct?:float, seasonalScale?:bool}  $opt
     * @return array{asked:string, used:string, fallback:bool, reason:?array{code:string, ar:string, en:string}, history:array<int, float>, periodsUsed:int,
     *               firstUsed:?int, total:float, mean:float, stdev:float, sigmaDaily:float, dailyRate:float, forecast:array<int, float>,
     *               sameDatesLastYear:array<int, ?float>, forecastTotal:float, avgDaily:float, detail:array}
     */
    public static function run(string $method, array $daily, array $history, array $horizon, array $opt = []): array
    {
        $growth = max(0.0, 1 + (float) ($opt['growthPct'] ?? 0) / 100);
        $window = max(1, (int) ($opt['wmaWindow'] ?? 3));
        $qty = [];
        foreach ($history as $p) {
            $qty[$p->index] = Calendar::sum($daily, $p->from, $p->to);
        }
        $first = null;
        foreach ($history as $p) {
            if ($qty[$p->index] > 0) {
                $first = $p->index;
                break;
            }
        }
        $used = $first === null ? [] : array_values(array_filter($history, fn (Period $p) => $p->index >= $first));
        $usedQty = array_map(fn (Period $p) => $qty[$p->index], $used);
        $usedDays = array_sum(array_map(fn (Period $p) => $p->days, $used));
        $total = array_sum($usedQty);
        $historyFrom = $history ? $history[0]->from : null;
        $historyTo = $history ? $history[count($history) - 1]->to : null;
        $horizonDays = array_sum(array_map(fn (Period $p) => $p->days, $horizon));

        // what was sold on the same dates a year earlier — shown beside every forecast, whatever the method
        $lastYear = [];
        $covered = $historyFrom !== null && $horizon !== [];
        foreach ($horizon as $p) {
            [$from, $to] = [Calendar::yearBefore($p->from), Calendar::yearBefore($p->to)];
            $inside = $historyFrom !== null && $from >= $historyFrom && $to <= $historyTo;
            $lastYear[$p->index] = $inside ? Num::q(Calendar::sum($daily, $from, $to)) : null;
            $covered = $covered && $inside;
        }

        $out = [
            'asked' => $method, 'used' => $method, 'fallback' => false, 'reason' => null,
            'history' => array_map(fn ($q) => Num::q($q), $qty), 'periodsUsed' => count($used), 'firstUsed' => $first,
            'total' => Num::q($total), 'mean' => $used ? Num::q($total / count($used)) : 0.0, 'stdev' => Num::q(Num::stdev($usedQty)),
            'sigmaDaily' => $used ? Num::stdev($usedQty) / sqrt($usedDays / count($used)) : 0.0,
            'dailyRate' => 0.0, 'forecast' => array_fill_keys(array_map(fn (Period $p) => $p->index, $horizon), 0.0),
            'sameDatesLastYear' => $lastYear, 'forecastTotal' => 0.0, 'avgDaily' => 0.0, 'detail' => ['growthPct' => (float) ($opt['growthPct'] ?? 0)],
        ];
        if (! $used || $total <= 0) {
            $returnsOnly = $used && $total <= 0;

            return ['used' => 'none', 'reason' => $returnsOnly
                ? ['code' => 'returns', 'ar' => 'المرتجعات تساوي المبيعات أو تزيد عليها في فترة السجل — لا طلب صافٍ يُبنى عليه', 'en' => 'Returns equal or exceed the sales of the history window — no net demand to build on']
                : ['code' => 'no_history', 'ar' => 'لا طلب في فترة السجل', 'en' => 'No demand in the history window']] + $out;
        }

        if ($method === 'seasonal') {
            $sum = array_sum(array_map(fn ($v) => (float) $v, $lastYear));
            if (! $covered) {
                $out = self::fellBack($out, 'seasonal_window', 'فترة السجل لا تصل إلى التواريخ نفسها من العام الماضي — استُخدم المتوسط المتحرك المرجّح', 'The history window does not reach the same dates last year — the weighted moving average was used');
            } elseif ($sum <= 0) {
                $out = self::fellBack($out, 'seasonal_empty', 'لا مبيعات في التواريخ نفسها من العام الماضي — استُخدم المتوسط المتحرك المرجّح', 'Nothing was sold on the same dates last year — the weighted moving average was used');
            } else {
                $scale = ! empty($opt['seasonalScale']) ? self::scale($daily, $historyFrom, $historyTo) : null;
                $factor = $scale['factor'] ?? 1.0;
                foreach ($horizon as $p) {
                    $out['forecast'][$p->index] = Num::q(max(0.0, (float) $lastYear[$p->index]) * $factor * $growth);
                }
                $out['detail'] += ['scale' => $scale];
                $out['dailyRate'] = $horizonDays ? $sum * $factor / $horizonDays : 0.0;

                return self::closed($out, $horizonDays);
            }
        }

        if ($out['used'] === 'wma') {
            $recent = array_slice($used, -$window);
            $weights = [];
            $num = $den = 0.0;
            foreach ($recent as $i => $p) {
                $w = $i + 1;
                $rate = $qty[$p->index] / $p->days;
                $num += $w * $rate;
                $den += $w;
                $weights[] = ['period' => $p->index, 'label' => $p->label, 'weight' => $w, 'qty' => Num::q($qty[$p->index]), 'days' => $p->days, 'dailyRate' => round($rate, 6)];
            }
            $rate = max(0.0, $num / $den);
            $out['detail'] += ['window' => $window, 'periods' => $weights];
        } else {
            $rate = max(0.0, $total / $usedDays);
            $out['detail'] += ['days' => $usedDays];
        }
        $out['dailyRate'] = $rate;
        foreach ($horizon as $p) {
            $out['forecast'][$p->index] = Num::q($rate * $p->days * $growth);
        }

        return self::closed($out, $horizonDays);
    }

    /**
     * How the level of now compares with the level of a year ago: the demand of the last 13 weeks of the history ÷
     * the demand of the same days one year earlier. Null where the window does not hold both.
     *
     * @param  array<string, float>  $daily
     * @return array{factor:float, raw:?float, now:float, then:float, days:int, from:string, to:string, capped:bool, applied:bool, why:?string}
     */
    public static function scale(array $daily, string $historyFrom, string $historyTo): array
    {
        $days = min(self::SCALE_WINDOW, Calendar::span($historyFrom, $historyTo));
        $from = Calendar::add($historyTo, -($days - 1));
        [$thenFrom, $thenTo] = [Calendar::yearBefore($from), Calendar::yearBefore($historyTo)];
        $base = ['factor' => 1.0, 'raw' => null, 'now' => Num::q(Calendar::sum($daily, $from, $historyTo)), 'then' => 0.0, 'days' => $days, 'from' => $from, 'to' => $historyTo, 'capped' => false, 'applied' => false, 'why' => null];
        if ($thenFrom < $historyFrom) {
            return ['why' => 'window'] + $base; // the window does not hold the same weeks of last year
        }
        $then = Calendar::sum($daily, $thenFrom, $thenTo);
        if ($then <= 0) {
            return ['why' => 'empty', 'then' => Num::q($then)] + $base; // nothing to compare with
        }
        $raw = max(0.0, $base['now']) / $then;
        $factor = Num::clamp($raw, self::SCALE_MIN, self::SCALE_MAX);

        return ['factor' => round($factor, 6), 'raw' => round($raw, 6), 'then' => Num::q($then), 'capped' => abs($factor - $raw) > 1e-9, 'applied' => true] + $base;
    }

    private static function fellBack(array $out, string $code, string $ar, string $en): array
    {
        return ['used' => 'wma', 'fallback' => true, 'reason' => ['code' => $code, 'ar' => $ar, 'en' => $en]] + $out;
    }

    private static function closed(array $out, int $horizonDays): array
    {
        $out['forecastTotal'] = Num::q(array_sum($out['forecast']));
        $out['avgDaily'] = $horizonDays ? $out['forecastTotal'] / $horizonDays : 0.0;
        $out['dailyRate'] = round($out['dailyRate'], 6);

        return $out;
    }
}
