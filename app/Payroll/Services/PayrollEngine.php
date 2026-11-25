<?php

namespace App\Payroll\Services;

use App\Payroll\Models\Component;
use App\Payroll\Support\Money;
use App\Support\AppError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The payslip of ONE contract for ONE month — pure arithmetic: the same inputs always give the same lines, and every
 * line says what it was computed from. Whole halalas throughout (Support\Money); a figure is rounded once, when it
 * becomes a line.
 *
 *   contract amounts   basic, housing, transport, other — in full for a month the contract covers whole, otherwise
 *                      `monthly × days covered ÷ days of the month` (two contracts that share a month add up to one)
 *   unpaid leave       `base × calendar days of the leave inside the coverage ÷ month_days`; the base is the gross or
 *                      the basic (`unpaid_base`); a coverage that is unpaid whole loses exactly what it earned
 *   attendance         from the approved sheet(s) of the period, in minutes after the policy:
 *                      `base × minutes ÷ (month_days × the schedule's minutes per day)` — overtime is paid; absence,
 *                      lateness and early leave are deducted; the base is `attendance_base`
 *   adjustments        the one-off additions and deductions people entered for the month
 *   social insurance   `clamp(basic + housing of the contract, floor, ceiling) × coverage`, then the employee's and
 *                      the employer's percentage of the scheme of the pay structure, at the rates in force on the
 *                      last day of the month
 *   reimbursements     expense claims posted and still owed to the employee
 *
 *   gross = basic + allowances · net = gross − deductions + reimbursements (the lines add up to it to the halala)
 *
 * Deductions for time not worked never take more than the month earned: what exceeds it is cut and said so.
 */
class PayrollEngine
{
    public function __construct(private readonly Rates $rates) {}

    /**
     * @param array{
     *   period: string,
     *   contract: array{id: string, number: string, start: string, end: ?string, wage: mixed, housing: mixed, transport: mixed, other: mixed},
     *   components: Collection<string, Component>,
     *   structure?: ?array{id: string, code: ?string, nameAr: string, nameEn: string},
     *   gosiScheme?: ?string,
     *   dayMinutes: int,
     *   unpaidLeaves?: array<int, array{number: string, from: string, to: string, half: bool}>,
     *   sheets?: array<int, array{id: string, number: string, overtime: int, late: int, early: int, absence: int, absenceDays: int}>,
     *   adjustments?: array<int, array{id: string, code: string, amount: mixed}>,
     *   claims?: array<int, array{id: string, number: string, due: mixed}>
     * } $in
     * @return array{lines: array<int, array<string, mixed>>, totals: array<string, int>, worked: array<string, mixed>, explain: array<string, mixed>, warnings: string[], coverage: array{from: string, to: string, days: int, daysInMonth: int}}
     */
    public function compute(array $in): array
    {
        $monthFrom = Carbon::parse($in['period'].'-01')->startOfDay();
        $monthTo = $monthFrom->copy()->endOfMonth()->startOfDay();
        $on = $monthTo->format('Y-m-d');
        $daysInMonth = (int) $monthTo->format('d');
        $c = $in['contract'];
        $from = max($c['start'], $monthFrom->format('Y-m-d'));
        $to = min($c['end'] ?? $on, $on);
        $covered = $to >= $from ? (int) Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1 : 0;
        if ($covered <= 0) {
            throw AppError::rule('PAYROLL_NOT_IN_PERIOD', "العقد {$c['number']} ليس ساريًا في {$in['period']}", "Contract {$c['number']} is not in force in {$in['period']}");
        }
        $whole = $covered === $daysInMonth;
        /** @var Collection<string, Component> $components */
        $components = $in['components'];
        $monthDays = (int) round($this->rates->number('month_days', $on));
        $dayMinutes = max(1, (int) $in['dayMinutes']);
        $lines = [];
        $warnings = [];
        $add = function (Component $k, int $total, float $quantity, float $rate, int $amount, array $explain, array $more = []) use (&$lines) {
            // `$more` names what is particular to this line (the claim it pays, the adjustment it carries) and wins
            $lines[] = $more + ['sequence' => $k->sequence, 'component_id' => $k->id, 'code' => $k->code, 'name_ar' => $k->name_ar, 'name_en' => $k->name_en, 'category' => $k->category,
                'quantity' => round($quantity, 4), 'rate' => round($rate, 4), 'amount' => $amount, 'total' => $total, 'explain' => $explain, 'claim_id' => null, 'adjustment_id' => null];
        };
        $of = fn (string $calc) => $components->first(fn (Component $k) => $k->calc === $calc);

        // ── what the contract pays
        $monthly = ['gross' => 0, 'basic' => 0, 'gosi' => 0];
        $earned = 0;
        foreach ($components->filter(fn (Component $k) => $k->calc === 'contract')->sortBy('sequence') as $k) {
            $full = Money::h($c[$k->contract_field] ?? 0);
            if ($full <= 0) {
                continue;
            }
            $part = $whole ? $full : Money::ratio($full, $covered, $daysInMonth);
            $monthly['gross'] += $full;
            $monthly['basic'] += $k->category === 'basic' ? $full : 0;
            $monthly['gosi'] += $k->gosi_base ? $full : 0;
            $earned += $part;
            $add($k, $part, $covered, $whole ? 100 : $covered / $daysInMonth * 100, $full, ['rule' => 'contract', 'field' => $k->contract_field, 'monthly' => Money::s($full), 'daysCovered' => $covered, 'daysInMonth' => $daysInMonth]);
        }

        // ── unpaid leave: calendar days of the leave inside the coverage
        $left = $earned; // what time not worked may still take
        $unpaidUnits = 0; // half days
        $leaveNumbers = [];
        foreach ($in['unpaidLeaves'] ?? [] as $l) {
            $a = max($l['from'], $from);
            $b = min($l['to'], $to);
            if ($b < $a) {
                continue;
            }
            $unpaidUnits += $l['half'] ? 1 : 2 * ((int) Carbon::parse($a)->diffInDays(Carbon::parse($b)) + 1);
            $leaveNumbers[] = $l['number'];
        }
        if ($unpaidUnits > 0 && ($k = $of('unpaid_leave'))) {
            $base = $this->rates->choice('unpaid_base', $on) === 'basic' ? $monthly['basic'] : $monthly['gross'];
            $allUnpaid = $unpaidUnits >= 2 * $covered;
            $raw = $allUnpaid ? $earned : Money::ratio($base, $unpaidUnits, 2 * $monthDays);
            $cut = min($raw, $left);
            $left -= $cut;
            if ($cut > 0) {
                $add($k, -$cut, $unpaidUnits / 2, 0, Money::ratio($base, 1, $monthDays), ['rule' => 'unpaid_leave', 'base' => $this->rates->choice('unpaid_base', $on), 'baseAmount' => Money::s($base), 'monthDays' => $monthDays,
                    'days' => $unpaidUnits / 2, 'leaves' => $leaveNumbers, 'wholeCoverage' => $allUnpaid, 'capped' => $cut < $raw]);
            }
            if ($cut < $raw) {
                $warnings[] = 'DEDUCTION_CAPPED';
            }
        }

        // ── attendance: the approved sheets of the period
        $sheet = ['overtime' => 0, 'late' => 0, 'early' => 0, 'absence' => 0, 'absenceDays' => 0, 'numbers' => [], 'ids' => []];
        foreach ($in['sheets'] ?? [] as $s) {
            foreach (['overtime', 'late', 'early', 'absence', 'absenceDays'] as $key) {
                $sheet[$key] += (int) $s[$key];
            }
            $sheet['numbers'][] = $s['number'];
            $sheet['ids'][] = $s['id'];
        }
        if ($sheet['overtime'] + $sheet['late'] + $sheet['early'] + $sheet['absence'] > 0) {
            $baseName = $this->rates->choice('attendance_base', $on);
            $base = $baseName === 'basic' ? $monthly['basic'] : $monthly['gross'];
            $divisor = $monthDays * $dayMinutes;
            $hourly = Money::ratio($base, 60, $divisor);
            $why = fn (string $rule, int $minutes) => ['rule' => $rule, 'base' => $baseName, 'baseAmount' => Money::s($base), 'monthDays' => $monthDays, 'minutesPerDay' => $dayMinutes, 'minutes' => $minutes, 'sheets' => $sheet['numbers']];
            if ($sheet['overtime'] > 0 && ($k = $of('overtime'))) {
                $add($k, Money::ratio($base, $sheet['overtime'], $divisor), $sheet['overtime'] / 60, 0, $hourly, $why('overtime', $sheet['overtime']));
            }
            foreach (['absence', 'late', 'early'] as $calc) {
                if ($sheet[$calc] > 0 && ($k = $of($calc))) {
                    $raw = Money::ratio($base, $sheet[$calc], $divisor);
                    $cut = min($raw, $left);
                    $left -= $cut;
                    if ($cut > 0) {
                        $add($k, -$cut, $sheet[$calc] / 60, 0, $hourly, $why($calc, $sheet[$calc]) + ['capped' => $cut < $raw] + ($calc === 'absence' ? ['days' => $sheet['absenceDays']] : []));
                    }
                    if ($cut < $raw) {
                        $warnings[] = 'DEDUCTION_CAPPED';
                    }
                }
            }
        }

        // ── what people added for the month
        foreach ($in['adjustments'] ?? [] as $a) {
            $k = $components->first(fn (Component $x) => $x->code === $a['code']);
            $amount = Money::h($a['amount']);
            if (! $k || $k->calc !== 'input' || $amount <= 0) {
                continue;
            }
            $add($k, $k->category === 'deduction' ? -$amount : $amount, 1, 100, $amount, ['rule' => 'adjustment'], ['adjustment_id' => $a['id']]);
        }

        // ── social insurance
        $gosi = null;
        $scheme = $in['gosiScheme'] ?? null;
        if ($scheme && $monthly['gosi'] > 0) {
            $rate = $this->rates->gosi($scheme, $on) ?? throw AppError::rule('PAYROLL_GOSI_RATE_MISSING', "لا نسب تأمينات سارية للنظام «{$scheme}» بتاريخ {$on} — أضِفها في إعدادات الرواتب",
                "No social-insurance rates of scheme '{$scheme}' are in force on {$on} — add them in the payroll settings", ['scheme' => $scheme, 'date' => $on]);
            $clamped = max(Money::h($rate->base_min), $monthly['gosi']);
            $clamped = $rate->base_max !== null ? min($clamped, Money::h($rate->base_max)) : $clamped;
            $base = $whole ? $clamped : Money::ratio($clamped, $covered, $daysInMonth);
            $gosi = ['scheme' => $scheme, 'effectiveFrom' => $rate->effective_from->format('Y-m-d'), 'employeePct' => (float) $rate->employee_pct, 'employerPct' => (float) $rate->employer_pct,
                'baseMin' => (string) $rate->base_min, 'baseMax' => $rate->base_max === null ? null : (string) $rate->base_max, 'contractBase' => Money::s($monthly['gosi']), 'base' => Money::s($base)];
            foreach ([['gosi_employee', $rate->employee_pct, -1], ['gosi_employer', $rate->employer_pct, 1]] as [$calc, $pct, $sign]) {
                $share = Money::pct($base, $pct);
                if ($share > 0 && ($k = $of($calc))) {
                    $add($k, $sign * $share, 1, (float) $pct, $base, ['rule' => $calc] + $gosi);
                }
            }
        }

        // ── expense claims still owed
        $claimNumbers = [];
        if ($k = $of('claims')) {
            foreach ($in['claims'] ?? [] as $claim) {
                $due = Money::h($claim['due']);
                if ($due > 0) {
                    $add($k, $due, 1, 100, $due, ['rule' => 'claims', 'claim' => $claim['number']], ['claim_id' => $claim['id'], 'name_ar' => "{$k->name_ar} — {$claim['number']}", 'name_en' => "{$k->name_en} — {$claim['number']}"]);
                    $claimNumbers[] = $claim['number'];
                }
            }
        }

        // ── totals: every one of them a sum of the lines above
        $sum = fn (array $categories) => array_sum(array_map(fn (array $l) => in_array($l['category'], $categories, true) ? $l['total'] : 0, $lines));
        $totals = ['basic' => $sum(['basic']), 'gross' => $sum(['basic', 'allowance']), 'deductions' => -$sum(['deduction']), 'reimbursements' => $sum(['reimbursement']), 'employer_cost' => $sum(['company'])];
        $totals['net'] = $totals['gross'] - $totals['deductions'] + $totals['reimbursements'];
        if ($totals['net'] < 0) {
            $warnings[] = 'NEGATIVE_NET';
        }
        $summary = fn (string $code, string $ar, string $en, string $category, int $sequence, int $total) => ['sequence' => $sequence, 'component_id' => null, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en,
            'category' => $category, 'quantity' => 1.0, 'rate' => 100.0, 'amount' => $total, 'total' => $total, 'explain' => ['rule' => 'sum'], 'claim_id' => null, 'adjustment_id' => null];
        $lines[] = $summary('GROSS', 'الإجمالي', 'Gross', 'gross', 100, $totals['gross']);
        $lines[] = $summary('NET', 'الصافي', 'Net', 'net', 900, $totals['net']);
        usort($lines, fn (array $a, array $b) => [$a['sequence'], $a['code']] <=> [$b['sequence'], $b['code']]);

        return [
            'lines' => $lines, 'totals' => $totals, 'warnings' => array_values(array_unique($warnings)),
            'coverage' => ['from' => $from, 'to' => $to, 'days' => $covered, 'daysInMonth' => $daysInMonth],
            'worked' => ['daysInMonth' => $daysInMonth, 'daysCovered' => $covered, 'unpaidDays' => $unpaidUnits / 2, 'absenceDays' => $sheet['absenceDays'],
                'overtimeMinutes' => $sheet['overtime'], 'lateMinutes' => $sheet['late'], 'earlyMinutes' => $sheet['early'], 'absenceMinutes' => $sheet['absence']],
            'explain' => [
                'period' => $in['period'], 'contract' => ['number' => $c['number'], 'start' => $c['start'], 'end' => $c['end'], 'from' => $from, 'to' => $to],
                'proration' => ['rule' => $whole ? 'whole month' : 'monthly × days covered ÷ days of the month', 'daysCovered' => $covered, 'daysInMonth' => $daysInMonth],
                'parameters' => ['monthDays' => $monthDays, 'minutesPerDay' => $dayMinutes, 'on' => $on],
                'structure' => $in['structure'] ?? null, 'gosi' => $gosi, 'sheets' => $sheet['numbers'], 'sheetIds' => $sheet['ids'], 'unpaidLeaves' => $leaveNumbers, 'claims' => $claimNumbers,
                'adjustments' => count(array_filter($lines, fn (array $l) => $l['adjustment_id'] !== null)),
            ],
        ];
    }
}
