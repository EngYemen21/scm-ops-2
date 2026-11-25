<?php

namespace App\Planning\Engine;

/**
 * The balance of a product at a warehouse, period by period, IF NOTHING IS BOUGHT — and from it, what must be bought.
 * Pure: no database, no clock.
 *
 * ONE PERIOD.  opening + booked in − outflow = projected balance, where the outflow depends on the netting chosen:
 *   net     booked deliveries are part of the forecast, not on top of it: outflow = the larger of (forecast, booked
 *           out); "unmet demand" = forecast − booked out, never below zero — the demand no order was booked for yet;
 *   gross   booked deliveries are ignored: outflow = forecast, unmet demand = forecast;
 *   ignore  the forecast is only shown beside the balance: outflow = booked out, unmet demand = 0.
 * The first opening balance is what is on hand less what is expected to leave before the horizon starts. "Booked
 * balance" is the same chain without any forecast (on hand + booked in − booked out): what the documents alone say.
 * Shortage = how far the balance ends the period below the safety stock; surplus = how far above the maximum level
 * (safety stock + the days of cover asked for; no maximum, no surplus). The first shortage date is the day the
 * balance crosses the safety stock, the period's outflow being spread evenly over its days.
 *
 * WHAT TO BUY (requirements()).  The same walk, but a period that would end below the safety stock gets a purchase:
 * net requirement = safety stock − balance without purchase, raised to the vendor's minimum and rounded up to the order
 * multiple; the purchase is added to the balance the next period opens with, so no period pays twice for an earlier
 * gap. Need date = the day the balance would cross the safety stock; order-by date = need date − lead time − planning
 * buffer; "late" when that day is before the anchor day. "Stock-out" is the part of the gap that is below zero.
 */
final class Projection
{
    public const NETTING = ['net', 'gross', 'ignore'];

    /** What leaves in a period and how much of the forecast no document covers yet. @return array{0:float, 1:float} [outflow, unmet] */
    public static function outflow(string $netting, float $forecast, float $bookedOut): array
    {
        return match ($netting) {
            'gross' => [$forecast, $forecast],
            'ignore' => [$bookedOut, 0.0],
            default => [max($forecast, $bookedOut), max(0.0, $forecast - $bookedOut)],
        };
    }

    /**
     * @param  Period[]  $horizon
     * @param  array<int, float>  $forecast  by period index
     * @param  array<int, float>  $in  booked in by period index
     * @param  array<int, float>  $out  booked out by period index
     * @param  array{onHand:float, before?:float, netting?:string, safety?:float, maxLevel?:float, avgDaily?:float}  $opt
     * @return array{periods:array<int, array>, minBalance:float, firstShortage:?string, shortage:float, surplus:float, bookedIn:float, bookedOut:float, opening:float}
     */
    public static function run(array $horizon, array $forecast, array $in, array $out, array $opt): array
    {
        $netting = $opt['netting'] ?? 'net';
        $safety = (float) ($opt['safety'] ?? 0);
        $max = (float) ($opt['maxLevel'] ?? 0);
        $daily = (float) ($opt['avgDaily'] ?? 0);
        $balance = $opening = (float) $opt['onHand'] - (float) ($opt['before'] ?? 0);
        $booked = (float) $opt['onHand'];
        $rows = [];
        $min = null;
        $first = null;
        $worst = 0.0;
        foreach ($horizon as $p) {
            $f = (float) ($forecast[$p->index] ?? 0);
            $i = (float) ($in[$p->index] ?? 0);
            $o = (float) ($out[$p->index] ?? 0);
            [$flow, $unmet] = self::outflow($netting, $f, $o);
            $end = $balance + $i - $flow;
            $booked += $i - $o;
            $shortage = max(0.0, $safety - $end);
            if ($first === null && $shortage > 1e-9) {
                $first = self::crossing($p, $balance + $i, $flow, $safety);
            }
            $rows[$p->index] = [
                'index' => $p->index, 'label' => $p->label, 'from' => $p->from, 'to' => $p->to, 'days' => $p->days, 'forecast' => Num::q($f), 'bookedIn' => Num::q($i), 'bookedOut' => Num::q($o),
                'unmet' => Num::q($unmet), 'opening' => Num::q($balance), 'bookedBalance' => Num::q($booked), 'projected' => Num::q($end), 'shortage' => Num::q($shortage),
                'surplus' => $max > 0 ? Num::q(max(0.0, $end - $max)) : 0.0, 'coverageDays' => $daily > 0 ? Num::m(max(0.0, $end) / $daily) : null,
            ];
            $min = $min === null ? $end : min($min, $end);
            $worst = max($worst, $shortage);
            $balance = $end;
        }
        $last = $rows ? $rows[array_key_last($rows)] : null;

        return [
            'periods' => $rows, 'minBalance' => Num::q($min ?? $opening), 'firstShortage' => $first, 'shortage' => Num::q($worst),
            'surplus' => $worst > 1e-9 || ! $last ? 0.0 : $last['surplus'], 'bookedIn' => Num::q(array_sum($in)), 'bookedOut' => Num::q(array_sum($out)), 'opening' => Num::q($opening),
        ];
    }

    /**
     * @param  Period[]  $horizon
     * @param  array<int, float>  $forecast
     * @param  array<int, float>  $in
     * @param  array<int, float>  $out
     * @param  array{onHand:float, before?:float, netting?:string, safety?:float, moq?:float, multiple?:float, leadDays?:float, bufferDays?:float, anchor:string}  $opt
     * @return array<int, array{period:int, label:string, from:string, to:string, demand:float, opening:float, projected:float, safety:float, stockout:float,
     *                          net:float, moq:float, multiple:float, qty:int, needDate:string, orderBy:string, late:bool, leadDays:float, bufferDays:float}>
     */
    public static function requirements(array $horizon, array $forecast, array $in, array $out, array $opt): array
    {
        $netting = $opt['netting'] ?? 'net';
        $safety = (float) ($opt['safety'] ?? 0);
        $moq = max(0.0, (float) ($opt['moq'] ?? 0));
        $multiple = max(1.0, (float) ($opt['multiple'] ?? 1));
        $lead = max(0.0, (float) ($opt['leadDays'] ?? 0));
        $buffer = max(0.0, (float) ($opt['bufferDays'] ?? 0));
        $ahead = (int) ceil($lead + $buffer - 1e-9);
        $balance = (float) $opt['onHand'] - (float) ($opt['before'] ?? 0);
        $rows = [];
        foreach ($horizon as $p) {
            $f = (float) ($forecast[$p->index] ?? 0);
            $i = (float) ($in[$p->index] ?? 0);
            [$flow] = self::outflow($netting, $f, (float) ($out[$p->index] ?? 0));
            $end = $balance + $i - $flow;
            if ($safety - $end <= 1e-9) {
                $balance = $end;

                continue;
            }
            $net = $safety - $end;
            $qty = Num::orderQty($net, $moq, $multiple);
            $need = self::crossing($p, $balance + $i, $flow, $safety);
            $orderBy = Calendar::add($need, -$ahead);
            $rows[] = [
                'period' => $p->index, 'label' => $p->label, 'from' => $p->from, 'to' => $p->to, 'demand' => Num::q($f), 'opening' => Num::q($balance), 'projected' => Num::q($end),
                'safety' => Num::q($safety), 'stockout' => Num::q(max(0.0, -$end)), 'net' => Num::q($net), 'moq' => Num::q($moq), 'multiple' => Num::q($multiple), 'qty' => $qty,
                'needDate' => $need, 'orderBy' => $orderBy, 'late' => $orderBy < $opt['anchor'], 'leadDays' => $lead, 'bufferDays' => $buffer,
            ];
            $balance = $end + $qty;
        }

        return $rows;
    }

    /**
     * The day of a period on which the balance crosses a level, the outflow being spread evenly over the days:
     * `$available` is what the period has to give (opening + receipts). A period that opens at or below the level
     * crosses it on its first day.
     */
    public static function crossing(Period $p, float $available, float $outflow, float $level): string
    {
        $room = $available - $level;
        if ($room <= 1e-9 || $outflow <= 1e-9) {
            return $p->from;
        }

        return Calendar::add($p->from, min($p->days - 1, (int) floor($room / ($outflow / $p->days) + 1e-9)));
    }

    /**
     * The safety stock of an item.
     *   days           the forecast per day × the days asked for;
     *   service_level  z × the deviation of a day's demand × √(lead time + buffer, one day at least), with z = 1.28 for
     *                  90 %, 1.65 for 95 %, 2.05 for 98 %, 2.33 for 99 % (the usual table, two decimals).
     *
     * @return array{qty:float, method:string, z:?float, sigmaDaily:?float, days:?float, exposure:?float}
     */
    public static function safety(string $method, float $avgDaily, float $sigmaDaily, float $days, ?string $level, float $leadDays, float $bufferDays): array
    {
        if ($method === 'service_level') {
            $z = self::Z[(string) $level] ?? self::Z['95'];
            $exposure = max(1.0, $leadDays + $bufferDays);

            return ['qty' => Num::q($z * $sigmaDaily * sqrt($exposure)), 'method' => 'service_level', 'z' => $z, 'sigmaDaily' => round($sigmaDaily, 6), 'days' => null, 'exposure' => $exposure];
        }

        return ['qty' => Num::q(max(0.0, $avgDaily * $days)), 'method' => 'days', 'z' => null, 'sigmaDaily' => null, 'days' => $days, 'exposure' => null];
    }

    public const Z = ['90' => 1.28, '95' => 1.65, '98' => 2.05, '99' => 2.33];

    /**
     * The reorder settings a run suggests: minimum = safety stock + the demand of the lead time (and buffer);
     * maximum = minimum + the demand of the days of cover asked for. Whole units, rounded up.
     *
     * @return array{min:int, max:int}
     */
    public static function minMax(float $safety, float $avgDaily, float $leadDays, float $bufferDays, float $coverDays): array
    {
        $min = (int) ceil($safety + $avgDaily * ($leadDays + $bufferDays) - 1e-9);

        return ['min' => max(0, $min), 'max' => max(0, (int) ceil($safety + $avgDaily * ($leadDays + $bufferDays + max(0.0, $coverDays)) - 1e-9))];
    }
}
