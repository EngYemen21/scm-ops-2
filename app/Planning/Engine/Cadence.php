<?php

namespace App\Planning\Engine;

/**
 * The reorder cadence of ONE customer for one product at one warehouse: how often it orders, how much, when the next
 * order is due and how far that can be trusted. Pure: no database, no clock — "today" is the anchor date of the run.
 *
 * ORDERS. A day on which the customer took the product is one order (two deliveries on one day are one order). A
 * return is not an order: its quantity is taken off the latest order before it, and an order that came back whole is
 * no order at all.
 *
 * THE PATTERN. Of the last `intervalWindow` gaps between orders: the average gap and how regular it is; of the orders
 * that bound those gaps: the average quantity and how steady it is. The expected quantity is that average × the trend
 * (the newer half of those orders ÷ the older half, kept within ± the cap; four orders at least) × the seasonal factor
 * of the product (when one is given) × the growth factor.
 *
 * WHERE IT STANDS (against the anchor day):
 *   insufficient  fewer orders than the minimum — nothing is expected from it;
 *   lapsed        the last order is older than `lapseFactor` × the average gap — the customer stopped: nothing expected;
 *   overdue       the next order should have come already — it is expected NOW (one catch-up order on the anchor day)
 *                 and the rhythm restarts from there;
 *   active        on schedule: last order + 1, 2, 3 … gaps.
 *
 * CONFIDENCE (0–100) = 100 × (0.5 × regularity + 0.2 × steadiness + 0.3 × depth), where regularity = 1 − variability of
 * the gaps, steadiness = 1 − variability of the quantities, depth = orders ÷ (2 × the minimum), each kept in 0…1; an
 * overdue pattern loses up to half of it (days overdue ÷ the lapse limit). ≥ 70 high · ≥ 40 medium · below: low.
 */
final class Cadence
{
    public const HIGH = 70.0;

    public const MEDIUM = 40.0;

    /** Never more expected orders than this for one pattern (a daily customer over a long horizon). */
    public const MAX_EVENTS = 400;

    /**
     * The orders of a customer out of its net demand per day (returns are negative days).
     *
     * @param  array<string, float>  $daily
     * @return array<int, array{day:string, qty:float}> oldest first
     */
    public static function orders(array $daily): array
    {
        ksort($daily);
        $orders = [];
        foreach ($daily as $day => $qty) {
            if ($qty > 0) {
                $orders[] = ['day' => (string) $day, 'qty' => (float) $qty];

                continue;
            }
            // a return: taken off the latest orders before it, newest first
            $back = -$qty;
            for ($i = count($orders) - 1; $i >= 0 && $back > 1e-9; $i--) {
                $take = min($orders[$i]['qty'], $back);
                $orders[$i]['qty'] -= $take;
                $back -= $take;
            }
            $orders = array_values(array_filter($orders, fn (array $o) => $o['qty'] > 1e-9));
        }

        return array_map(fn (array $o) => ['day' => $o['day'], 'qty' => Num::q($o['qty'])], $orders);
    }

    /**
     * @param  array<int, array{day:string, qty:float}>  $orders  oldest first (see orders())
     * @param  array{anchor:string, horizonFrom:string, horizonTo:string, minOrders?:int, intervalWindow?:int, lapseFactor?:float, useTrend?:bool,
     *               trendCapPct?:float, seasonFactor?:?float, growthPct?:float}  $opt
     * @param  Period[]  $horizon
     * @return array{orderCount:int, firstOrder:?string, lastOrder:?string, intervals:int[], quantities:float[], avgInterval:float, intervalStdev:float,
     *               intervalCv:float, avgQty:float, qtyCv:float, trendFactor:float, seasonal:bool, seasonFactor:float, expectedQty:float, nextExpected:?string,
     *               daysSinceLast:?int, overdueDays:int, state:string, confidence:float, band:string, events:array<int, array>, forecastQty:float,
     *               expectedOrders:int, beforeHorizonQty:float, perPeriod:array<int, float>}
     */
    public static function pattern(array $orders, array $opt, array $horizon): array
    {
        $min = max(2, (int) ($opt['minOrders'] ?? 3));
        $window = max(1, (int) ($opt['intervalWindow'] ?? 6));
        $lapse = max(1.0, (float) ($opt['lapseFactor'] ?? 3));
        $growth = max(0.0, 1 + (float) ($opt['growthPct'] ?? 0) / 100);
        $anchor = $opt['anchor'];
        $n = count($orders);
        $gaps = [];
        for ($i = 1; $i < $n; $i++) {
            $gaps[] = Calendar::diff($orders[$i - 1]['day'], $orders[$i]['day']);
        }
        $gaps = array_slice($gaps, -$window);
        $quantities = array_map(fn (array $o) => (float) $o['qty'], array_slice($orders, -(count($gaps) + 1)));
        $last = $n ? $orders[$n - 1]['day'] : null;
        $since = $last !== null ? Calendar::diff($last, $anchor) : null;
        $perPeriod = array_fill_keys(array_map(fn (Period $p) => $p->index, $horizon), 0.0);
        $out = [
            'orderCount' => $n, 'firstOrder' => $n ? $orders[0]['day'] : null, 'lastOrder' => $last, 'intervals' => $gaps, 'quantities' => array_map(fn ($q) => Num::q($q), $quantities),
            'avgInterval' => round(Num::mean($gaps), 4), 'intervalStdev' => round(Num::stdev($gaps), 4), 'intervalCv' => round(Num::cv($gaps), 4),
            'avgQty' => Num::q(Num::mean($quantities)), 'qtyCv' => round(Num::cv($quantities), 4), 'trendFactor' => 1.0, 'seasonal' => false, 'seasonFactor' => 1.0,
            'expectedQty' => 0.0, 'nextExpected' => null, 'daysSinceLast' => $since, 'overdueDays' => 0, 'state' => 'insufficient', 'confidence' => 0.0, 'band' => 'none',
            'events' => [], 'forecastQty' => 0.0, 'expectedOrders' => 0, 'beforeHorizonQty' => 0.0, 'perPeriod' => $perPeriod,
        ];
        if ($n < $min || ! $gaps) {
            return $out;
        }
        $gap = Num::mean($gaps);
        $step = max(1, Num::days($gap));
        $trend = 1.0;
        if (! empty($opt['useTrend']) && count($quantities) >= 4) {
            $half = intdiv(count($quantities), 2);
            $older = Num::mean(array_slice($quantities, 0, $half));
            $newer = Num::mean(array_slice($quantities, -$half));
            $cap = max(0.0, (float) ($opt['trendCapPct'] ?? 25)) / 100;
            $trend = $older > 0 ? Num::clamp($newer / $older, max(0.0, 1 - $cap), 1 + $cap) : 1.0;
        }
        $season = isset($opt['seasonFactor']) && $opt['seasonFactor'] !== null ? (float) $opt['seasonFactor'] : null;
        $expected = Num::q(Num::mean($quantities) * $trend * ($season ?? 1.0) * $growth);
        $next = Calendar::add($last, $step);
        $overdue = max(0, Calendar::diff($next, $anchor));
        $limit = $lapse * $gap;
        $state = $since > $limit ? 'lapsed' : ($overdue > 0 ? 'overdue' : 'active');
        $score = 100 * (0.5 * max(0.0, 1 - Num::cv($gaps)) + 0.2 * max(0.0, 1 - Num::cv($quantities)) + 0.3 * min(1.0, $n / (2 * $min)));
        $score *= 1 - min(0.5, $limit > 0 ? $overdue / $limit : 0.0);
        $score = Num::m($score);
        $out = [
            'trendFactor' => round($trend, 4), 'seasonal' => $season !== null, 'seasonFactor' => round($season ?? 1.0, 4), 'expectedQty' => $expected, 'nextExpected' => $next,
            'overdueDays' => $overdue, 'state' => $state, 'confidence' => $score, 'band' => $score >= self::HIGH ? 'high' : ($score >= self::MEDIUM ? 'medium' : 'low'),
        ] + $out;
        if ($state === 'lapsed') {
            return $out;
        }

        // the expected orders: an overdue one is expected on the anchor day and the rhythm restarts there
        [$from, $to] = [$opt['horizonFrom'], $opt['horizonTo']];
        $events = [];
        $base = $state === 'overdue' ? $anchor : $last;
        for ($k = $state === 'overdue' ? 0 : 1, $occurrence = 1; $occurrence <= self::MAX_EVENTS; $k++, $occurrence++) {
            $day = Calendar::add($base, Num::days($k * $gap));
            if ($occurrence > 1 && $day <= $events[count($events) - 1]['day']) {
                $day = Calendar::add($events[count($events) - 1]['day'], 1); // a gap shorter than a day still moves on
            }
            if ($day > $to) {
                break;
            }
            $index = Calendar::indexOf($horizon, $day);
            $events[] = ['occurrence' => $occurrence, 'day' => $day, 'catchUp' => $state === 'overdue' && $occurrence === 1, 'inHorizon' => $day >= $from, 'period' => $index, 'qty' => $expected, 'confidence' => $score];
            if ($index !== null) {
                $perPeriod[$index] = Num::q($perPeriod[$index] + $expected);
                $out['forecastQty'] += $expected;
                $out['expectedOrders']++;
            } else {
                $out['beforeHorizonQty'] += $expected;
            }
        }

        return ['events' => $events, 'perPeriod' => $perPeriod, 'forecastQty' => Num::q($out['forecastQty']), 'beforeHorizonQty' => Num::q($out['beforeHorizonQty'])] + $out;
    }

    /**
     * The seasonal factor of a product for the cadence method: how the dates of the horizon sold a year ago, against
     * how the dates of the last 13 weeks of the history sold a year ago (both as daily rates), kept between 0.5 and 2.
     * Null where the window does not hold both stretches or one of them had no sale.
     *
     * @param  array<string, float>  $daily  the pooled demand of the product at the warehouse
     */
    public static function seasonFactor(array $daily, string $historyFrom, string $historyTo, string $horizonFrom, string $horizonTo): ?float
    {
        $days = min(Forecaster::SCALE_WINDOW, Calendar::span($historyFrom, $historyTo));
        [$refFrom, $refTo] = [Calendar::yearBefore(Calendar::add($historyTo, -($days - 1))), Calendar::yearBefore($historyTo)];
        [$hFrom, $hTo] = [Calendar::yearBefore($horizonFrom), Calendar::yearBefore($horizonTo)];
        if ($refFrom < $historyFrom || $hFrom < $historyFrom || $hTo > $historyTo) {
            return null;
        }
        $reference = Calendar::sum($daily, $refFrom, $refTo) / Calendar::span($refFrom, $refTo);
        $horizon = Calendar::sum($daily, $hFrom, $hTo) / Calendar::span($hFrom, $hTo);
        if ($reference <= 0 || $horizon <= 0) {
            return null;
        }

        return round(Num::clamp($horizon / $reference, 0.5, 2.0), 4);
    }
}
