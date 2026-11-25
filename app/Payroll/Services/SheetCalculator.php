<?php

namespace App\Payroll\Services;

use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\AttendanceRule;

/**
 * The arithmetic of an attendance sheet — pure: the same days and the same policy always give the same lines.
 *
 * ONE DAY (`day()`), from what was planned and the presences of that day (minutes after midnight):
 *   worked          every minute checked in
 *   late            planned work minutes that passed before the first check-in
 *   early           planned work minutes not covered by a presence, lateness aside (leaving early, a gap in the day)
 *   overtime        minutes checked in after the planned sign-out; on a weekend or a holiday, every minute
 *   absence         a planned day with no presence at all (not a holiday, not a leave)
 *
 * THE POLICY (`applyPolicy()`), over the lines of one sheet in date order:
 *   late / early    up to the grace nothing counts; above it the rule's line with the highest threshold reached
 *                   decides: `rate × minutes` or a fixed time, weighed by how many times that line was hit in the
 *                   period (first … fifth). No rule: the actual minutes. Below every threshold: nothing.
 *   absence         the n-th absence of the period costs `rate ×` the day's planned minutes (no rule: the day itself)
 *   overtime        the rule of the day's kind (working day, weekend, holiday): paid once it exceeds
 *                   `active_after`, as `rate ×` the minutes. No rule for that kind of day: nothing is paid.
 */
final class SheetCalculator
{
    /**
     * @param  array{kind: string, planned: array{working: bool, in: ?int, out: ?int, minutes: int, intervals: array<int, array{0:int, 1:int}>}, presences: array<int, array{0:int, 1:?int}>}  $day
     *                                                                                                                                                                                               kind: work | weekend | holiday | leave | off | future
     * @return array{planned_in: ?int, planned_out: ?int, planned_minutes: int, actual_in: ?int, actual_out: ?int, worked_minutes: int, late_actual: int, early_actual: int, overtime_actual: int, status: ?string, open: bool}
     */
    public static function day(array $day): array
    {
        $planned = $day['planned'];
        $kind = $day['kind'];
        $closed = [];
        $open = false;
        $firstIn = null;
        $lastOut = null;
        foreach ($day['presences'] as [$in, $out]) {
            $firstIn = $firstIn === null ? $in : min($firstIn, $in);
            if ($out === null) {
                $open = true; // still checked in, or the check-out was never recorded: it counts for nothing until corrected

                continue;
            }
            $lastOut = $lastOut === null ? $out : max($lastOut, $out);
            if ($out > $in) {
                $closed[] = [$in, $out];
            }
        }
        $closed = WorkCalendar::merge($closed);
        $worked = array_sum(array_map(fn (array $i) => $i[1] - $i[0], $closed));
        $out = ['planned_in' => null, 'planned_out' => null, 'planned_minutes' => 0, 'actual_in' => $firstIn, 'actual_out' => $lastOut, 'worked_minutes' => $worked,
            'late_actual' => 0, 'early_actual' => 0, 'overtime_actual' => 0, 'status' => null, 'open' => $open];

        if ($kind !== 'work') {
            // nothing was planned: a presence on a weekend or a holiday is overtime, on any other such day it is only shown
            $out['status'] = $kind === 'future' ? null : $kind;
            $out['overtime_actual'] = in_array($kind, ['weekend', 'holiday'], true) ? $worked : 0;

            return $out;
        }
        $out['planned_in'] = $planned['in'];
        $out['planned_out'] = $planned['out'];
        $out['planned_minutes'] = $planned['minutes'];
        if ($firstIn === null) {
            $out['status'] = 'absence';

            return $out;
        }
        $covered = 0;
        $before = 0;
        foreach ($planned['intervals'] as [$from, $to]) {
            foreach ($closed as [$in, $leave]) {
                $covered += max(0, min($to, $leave) - max($from, $in));
            }
            $before += max(0, min($to, $firstIn) - $from); // planned work that passed before the first check-in
        }
        $out['late_actual'] = $before;
        $out['early_actual'] = max(0, $planned['minutes'] - $covered - $before);
        foreach ($closed as [$in, $leave]) {
            $out['overtime_actual'] += max(0, $leave - max($in, $planned['out']));
        }

        return $out;
    }

    /**
     * Applies a policy to the lines of ONE sheet (each as `day()` returned it, plus `kind`), in date order.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>> the lines with `late`, `early`, `overtime`, `absence_minutes`
     */
    public static function applyPolicy(array $lines, ?AttendancePolicy $policy): array
    {
        $hits = ['late' => [], 'early' => []];
        $absences = 0;
        $overtimeRules = [];
        foreach ($policy?->overtimeRules ?? [] as $rule) {
            if ($rule->active && $rule->day_type !== null) {
                $overtimeRules[$rule->day_type] ??= $rule;
            }
        }
        foreach ($lines as $i => $line) {
            $line['late'] = self::deducted((int) $line['late_actual'], (int) ($policy?->late_grace_minutes ?? 0), $policy?->lateRule, $hits['late']);
            $line['early'] = self::deducted((int) $line['early_actual'], (int) ($policy?->early_grace_minutes ?? 0), $policy?->earlyRule, $hits['early']);
            $line['absence_minutes'] = 0;
            if ($line['status'] === 'absence') {
                $absences++;
                $line['absence_minutes'] = self::absence((int) $line['planned_minutes'], $absences, $policy?->absenceRule);
            }
            $dayType = match ($line['kind'] ?? 'work') {
                'weekend' => 'weekend', 'holiday' => 'holiday', 'work' => 'workday', default => null
            };
            $rule = $dayType ? ($overtimeRules[$dayType] ?? null) : null;
            $actual = (int) $line['overtime_actual'];
            $line['overtime'] = $rule && $actual > 0 && $actual > (int) ($rule->active_after_minutes ?? 0) ? (int) round($actual * (float) $rule->rate) : 0;
            $lines[$i] = $line;
        }

        return $lines;
    }

    /**
     * Totals of a sheet from its lines (the figures after the policy and the actual ones).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, int|float>
     */
    public static function totals(array $lines): array
    {
        $t = array_fill_keys(['planned_minutes', 'worked_minutes', 'late_count', 'late_actual_minutes', 'late_minutes', 'early_count', 'early_actual_minutes', 'early_minutes',
            'overtime_count', 'overtime_actual_minutes', 'overtime_minutes', 'absence_count', 'absence_minutes', 'holiday_days', 'weekend_days'], 0);
        $t['leave_days'] = 0.0;
        foreach ($lines as $l) {
            $t['planned_minutes'] += (int) $l['planned_minutes'];
            $t['worked_minutes'] += (int) $l['worked_minutes'];
            $t['late_actual_minutes'] += (int) $l['late_actual'];
            $t['late_minutes'] += (int) $l['late'];
            $t['late_count'] += (int) $l['late'] > 0 ? 1 : 0;
            $t['early_actual_minutes'] += (int) $l['early_actual'];
            $t['early_minutes'] += (int) $l['early'];
            $t['early_count'] += (int) $l['early'] > 0 ? 1 : 0;
            $t['overtime_actual_minutes'] += (int) $l['overtime_actual'];
            $t['overtime_minutes'] += (int) $l['overtime'];
            $t['overtime_count'] += (int) $l['overtime'] > 0 ? 1 : 0;
            $t['absence_minutes'] += (int) $l['absence_minutes'];
            $t['absence_count'] += $l['status'] === 'absence' ? 1 : 0;
            $t['holiday_days'] += $l['status'] === 'holiday' ? 1 : 0;
            $t['weekend_days'] += $l['status'] === 'weekend' ? 1 : 0;
            $t['leave_days'] += $l['status'] === 'leave' ? 1 : 0;
        }

        return $t;
    }

    /** @param  array<string, int>  $hits  how many times each line of the rule was reached so far in the period */
    private static function deducted(int $actual, int $grace, ?AttendanceRule $rule, array &$hits): int
    {
        if ($actual <= 0 || $actual <= $grace) {
            return 0;
        }
        $lines = $rule && $rule->active ? $rule->lines : null;
        if ($lines === null || $lines->isEmpty()) {
            return $actual;
        }
        $line = $lines->filter(fn ($l) => $actual >= (int) $l->threshold_minutes)->sortByDesc('threshold_minutes')->first();
        if (! $line) {
            return 0;
        }
        $n = $hits[$line->id] = ($hits[$line->id] ?? 0) + 1;
        $weights = [$line->first, $line->second, $line->third, $line->fourth, $line->fifth];
        $factor = 1.0;
        for ($k = min($n, 5); $k >= 1; $k--) { // the weight of this occurrence, or of the nearest earlier one that has a weight
            if ($weights[$k - 1] !== null && (float) $weights[$k - 1] > 0) {
                $factor = (float) $weights[$k - 1];
                break;
            }
        }

        return (int) round(($line->type === 'fix' ? (int) $line->amount_minutes : $actual * (float) ($line->rate ?? 1)) * $factor);
    }

    private static function absence(int $planned, int $nth, ?AttendanceRule $rule): int
    {
        $lines = $rule && $rule->active ? $rule->lines->filter(fn ($l) => $l->counter !== null)->sortBy('counter')->values() : null;
        if ($lines === null || $lines->isEmpty()) {
            return $planned;
        }
        $line = $lines->first(fn ($l) => (int) $l->counter === $nth) ?? ($nth > (int) $lines->last()->counter ? $lines->last() : $lines->first());

        return (int) round($planned * (float) ($line->rate ?? 1));
    }
}
