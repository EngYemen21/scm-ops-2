<?php

namespace App\Planning\Engine;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The days and periods of a run. Days are plain calendar days (`YYYY-MM-DD`) of the business: nothing here knows a
 * clock or a time zone, so the same input always gives the same periods.
 *
 * PERIODS. `month` — calendar months, the first and the last cut to the range (a range from the 24th gives a first
 * period of seven days). `week` — blocks of seven days: a forecast counts them from its first day (only the last block
 * may be short), a history counts them BACK from its last day (the newest block is always whole — it is the one a
 * weighted average trusts most — and only the oldest may be short).
 */
final class Calendar
{
    public const KINDS = ['month', 'week'];

    public static function date(string $day): DateTimeImmutable
    {
        return new DateTimeImmutable(substr($day, 0, 10).' 00:00:00', new DateTimeZone('UTC'));
    }

    public static function valid(?string $day): bool
    {
        return is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4));
    }

    public static function add(string $day, int $days): string
    {
        return self::date($day)->modify(($days >= 0 ? '+' : '').$days.' days')->format('Y-m-d');
    }

    /** Days from `$a` to `$b` (negative when `$b` is earlier). */
    public static function diff(string $a, string $b): int
    {
        return (int) round((self::date($b)->getTimestamp() - self::date($a)->getTimestamp()) / 86400);
    }

    /** Both ends counted. */
    public static function span(string $from, string $to): int
    {
        return self::diff($from, $to) + 1;
    }

    /** The same day one year earlier; the 29th of February becomes the 28th. */
    public static function yearBefore(string $day): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', substr($day, 0, 10)));
        $y--;

        return sprintf('%04d-%02d-%02d', $y, $m, min($d, self::daysInMonth($y, $m)));
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return (int) self::date(sprintf('%04d-%02d-01', $year, $month))->format('t');
    }

    public static function monthStart(string $day): string
    {
        return substr($day, 0, 7).'-01';
    }

    public static function monthEnd(string $day): string
    {
        [$y, $m] = array_map('intval', explode('-', substr($day, 0, 7)));

        return sprintf('%04d-%02d-%02d', $y, $m, self::daysInMonth($y, $m));
    }

    public static function nextMonth(string $day): string
    {
        return self::add(self::monthEnd($day), 1);
    }

    /** The first days of the months from the month of `$from` to the month of `$to`. @return string[] */
    public static function months(string $from, string $to): array
    {
        $out = [];
        for ($m = self::monthStart($from); $m <= $to; $m = self::nextMonth($m)) {
            $out[] = $m;
        }

        return $out;
    }

    /** The periods a forecast is made for. @return Period[] */
    public static function horizon(string $from, string $to, string $kind = 'month'): array
    {
        return $kind === 'week' ? self::weeksForward($from, $to) : self::monthly($from, $to);
    }

    /** The periods a history is read in. @return Period[] */
    public static function history(string $from, string $to, string $kind = 'month'): array
    {
        return $kind === 'week' ? self::weeksBackward($from, $to) : self::monthly($from, $to);
    }

    /** @return Period[] */
    private static function monthly(string $from, string $to): array
    {
        $out = [];
        for ($start = $from, $i = 1; $start <= $to; $start = self::add($end, 1), $i++) {
            $end = min(self::monthEnd($start), $to);
            $out[] = new Period($i, substr($start, 0, 7), $start, $end, self::span($start, $end));
        }

        return $out;
    }

    /** @return Period[] */
    private static function weeksForward(string $from, string $to): array
    {
        $out = [];
        for ($start = $from, $i = 1; $start <= $to; $start = self::add($end, 1), $i++) {
            $end = min(self::add($start, 6), $to);
            $out[] = new Period($i, $start, $start, $end, self::span($start, $end));
        }

        return $out;
    }

    /** @return Period[] */
    private static function weeksBackward(string $from, string $to): array
    {
        $blocks = [];
        for ($end = $to; $end >= $from; $end = self::add($start, -1)) {
            $start = max(self::add($end, -6), $from);
            $blocks[] = [$start, $end];
        }
        $out = [];
        foreach (array_reverse($blocks) as $i => [$start, $end]) {
            $out[] = new Period($i + 1, $start, $start, $end, self::span($start, $end));
        }

        return $out;
    }

    /** The index of the period that holds the day, or null. @param Period[] $periods */
    public static function indexOf(array $periods, string $day): ?int
    {
        foreach ($periods as $p) {
            if ($p->holds($day)) {
                return $p->index;
            }
        }

        return null;
    }

    /** The sum of a day → quantity map between two days (both counted). @param array<string, float> $daily */
    public static function sum(array $daily, string $from, string $to): float
    {
        $sum = 0.0;
        foreach ($daily as $day => $qty) {
            if ($day >= $from && $day <= $to) {
                $sum += $qty;
            }
        }

        return $sum;
    }
}
