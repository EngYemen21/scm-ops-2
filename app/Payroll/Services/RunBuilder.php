<?php

namespace App\Payroll\Services;

use App\Hr\Models\Contract;
use App\Hr\Services\Workforce;
use App\Payroll\Models\Adjustment;
use App\Payroll\Models\Component;
use App\Payroll\Models\Leave;
use App\Payroll\Models\Payslip;
use App\Payroll\Models\PayslipLine;
use App\Payroll\Models\Run;
use App\Payroll\Models\Sheet;
use App\Payroll\Models\Structure;
use App\Payroll\Support\Money;
use App\Payroll\Support\Shape;
use App\Services\Core\NumberingService;
use App\Support\AppError;
use App\Support\AuthUser;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * From a run to its payslips: gathers, for every contract in force during the month, what the engine needs (the
 * contract's pay through Workforce, the pay structure and schedule of the person, their approved unpaid leave, the
 * approved attendance sheet of the period, the adjustments waiting for the month, the expense claims still owed) and
 * writes what the engine answers.
 *
 * `compute()` only reads; `write()` stores; `differences()` computes again and compares with what is stored — the proof
 * a run is still what its inputs say before it is approved.
 *
 * WHAT GOES WHERE when a person had two contracts in the month (a renewal): each contract gets its own payslip for its
 * own days; the attendance sheet, the adjustments and the reimbursements go to the later one, once.
 * A contract that already has a standing payslip for the month in another run is left out and listed with the reason —
 * and the unique `live_key` makes a second one impossible even if two people compute at the same moment.
 */
class RunBuilder
{
    public function __construct(
        private readonly Workforce $workforce,
        private readonly WorkCalendar $calendar,
        private readonly PayrollEngine $engine,
        private readonly SheetService $sheets,
    ) {}

    /**
     * @return array{slips: array<string, array<string, mixed>>, skipped: array<int, array{contract: string, employee: ?string, reason: string}>}
     */
    public function compute(Run $run): array
    {
        $period = $run->period;
        $from = Carbon::parse("{$period}-01")->format('Y-m-d');
        $to = Carbon::parse("{$period}-01")->endOfMonth()->format('Y-m-d');
        $components = Component::where('active', true)->orderBy('sequence')->get()->keyBy('code');
        $structures = Structure::where('active', true)->get()->keyBy('id');
        $default = $structures->first(fn (Structure $s) => $s->is_default);
        $contracts = $this->workforce->contractsBetween($from, $to)->filter(fn (Contract $c) => $c->employee !== null && ($run->department_id === null || $c->employee->department_id === $run->department_id));
        $mine = Payslip::where('run_id', $run->id)->pluck('id', 'contract_id');
        $slips = [];
        $skipped = [];
        foreach ($contracts->groupBy('employee_id') as $employeeId => $own) {
            /** @var Collection<int, Contract> $own */
            $own = $own->sortBy(fn (Contract $c) => Shape::day($c->start_date).$c->id)->values();
            $employee = $own->first()->employee;
            $carrier = $own->last()->id;
            $profile = $this->calendar->profileOf($employeeId);
            $structure = ($profile?->structure_id ? ($structures[$profile->structure_id] ?? null) : null) ?? $default;
            $schedule = $this->calendar->scheduleOf($employeeId);
            foreach ($own as $c) {
                $skip = fn (string $reason) => ['contract' => $c->number, 'employee' => $employee->code, 'reason' => $reason];
                $other = Payslip::where('live_key', Payslip::liveKey($c->id, $period))->where(fn ($w) => $w->whereNull('run_id')->orWhere('run_id', '!=', $run->id))->first(['number']);
                if ($other) {
                    $skipped[] = $skip('ALREADY_PAID') + ['payslip' => $other->number];

                    continue;
                }
                if (mb_strtoupper((string) $c->currency) !== mb_strtoupper((string) $run->currency)) {
                    $skipped[] = $skip('OTHER_CURRENCY');

                    continue;
                }
                $pay = Workforce::pay($c);
                $extras = $c->id === $carrier;
                $slipId = $mine[$c->id] ?? null;
                $sheets = $extras ? $this->sheets->approvedFor($employeeId, $period, $slipId) : collect();
                try {
                    $out = $this->engine->compute([
                        'period' => $period,
                        'contract' => ['id' => $c->id, 'number' => $c->number, 'start' => Shape::day($c->start_date), 'end' => Shape::day($c->end_date),
                            'wage' => $pay['basic'], 'housing' => $pay['housing'], 'transport' => $pay['transport'], 'other' => $pay['other']],
                        'components' => $components,
                        'structure' => $structure ? ['id' => $structure->id, 'code' => $structure->code, 'nameAr' => $structure->name_ar, 'nameEn' => $structure->name_en] : null,
                        'gosiScheme' => $structure?->gosi_scheme,
                        'dayMinutes' => $schedule?->dayMinutes() ?? 0,
                        'unpaidLeaves' => $this->unpaidLeaves($employeeId, $from, $to),
                        'sheets' => $sheets->map(fn (Sheet $s) => ['id' => $s->id, 'number' => $s->number, 'overtime' => $s->overtime_minutes, 'late' => $s->late_minutes, 'early' => $s->early_minutes,
                            'absence' => $s->absence_minutes, 'absenceDays' => $s->absence_count])->all(),
                        'adjustments' => $extras ? $this->adjustments($employeeId, $period, $slipId) : [],
                        'claims' => $extras ? $this->claims($employeeId, $run->id) : [],
                    ]);
                } catch (AppError $e) {
                    $skipped[] = $skip($e->errorCode);

                    continue;
                }
                if ($extras && Sheet::where('employee_id', $employeeId)->where('period', $period)->where('state', '!=', 'approved')->exists()) {
                    $out['warnings'][] = 'SHEET_NOT_APPROVED'; // a sheet of the month exists and is not approved: its figures are not in this payslip
                }
                if ($out['totals']['net'] > 0 && ($profile?->payment_method ?? 'bank') === 'bank' && ! $this->workforce->bankOf($employeeId)['bankAccount']) {
                    $out['warnings'][] = 'NO_BANK_ACCOUNT';
                }
                $slips[$c->id] = $out + ['employee_id' => $employeeId, 'contract_id' => $c->id, 'department_id' => $employee->department_id, 'structure_id' => $structure?->id, 'employee_code' => $employee->code];
            }
        }

        return ['slips' => $slips, 'skipped' => $skipped];
    }

    /** Stores what `compute()` answered: a payslip per contract (kept with its number when it was there), its lines, the run's totals. */
    public function write(Run $run, array $computed, AuthUser $user, NumberingService $numbering): void
    {
        $existing = Payslip::where('run_id', $run->id)->get()->keyBy('contract_id');
        $sum = array_fill_keys(['gross', 'deductions', 'reimbursements', 'net', 'employer_cost'], 0);
        $warnings = [];
        foreach ($computed['slips'] as $contractId => $s) {
            $t = $s['totals'];
            $values = ['employee_id' => $s['employee_id'], 'department_id' => $s['department_id'], 'structure_id' => $s['structure_id'], 'period' => $run->period,
                'date_from' => $s['coverage']['from'], 'date_to' => $s['coverage']['to'], 'state' => $run->state, 'currency' => $run->currency,
                'days_covered' => $s['coverage']['days'], 'days_in_month' => $s['coverage']['daysInMonth'], 'basic' => Money::s($t['basic']), 'gross' => Money::s($t['gross']),
                'deductions' => Money::s($t['deductions']), 'reimbursements' => Money::s($t['reimbursements']), 'net' => Money::s($t['net']), 'employer_cost' => Money::s($t['employer_cost']),
                'worked' => $s['worked'], 'explain' => $s['explain'], 'warnings' => $s['warnings'] ?: null];
            $slip = $existing[$contractId] ?? null;
            if ($slip) {
                $slip->update($values);
                PayslipLine::where('payslip_id', $slip->id)->delete();
            } else {
                try {
                    $slip = Payslip::create($values + ['number' => $numbering->next('PSL'), 'run_id' => $run->id, 'contract_id' => $contractId, 'live_key' => Payslip::liveKey($contractId, $run->period), 'created_by' => $user->username]);
                } catch (UniqueConstraintViolationException) {
                    throw AppError::conflict('PAYRUN_CONTRACT_TAKEN', 'مسير آخر أخذ أحد العقود لهذا الشهر في اللحظة نفسها — أعِد الحساب', 'Another run took one of the contracts for this month at the same moment — compute again');
                }
            }
            foreach ($s['lines'] as $l) {
                PayslipLine::create(['payslip_id' => $slip->id, 'sequence' => $l['sequence'], 'component_id' => $l['component_id'], 'code' => $l['code'], 'name_ar' => $l['name_ar'], 'name_en' => $l['name_en'],
                    'category' => $l['category'], 'quantity' => $l['quantity'], 'rate' => $l['rate'], 'amount' => Money::s($l['amount']), 'total' => Money::s($l['total']), 'explain' => $l['explain'],
                    'claim_id' => $l['claim_id'], 'adjustment_id' => $l['adjustment_id']]);
            }
            foreach ($sum as $key => $_) {
                $sum[$key] += $t[$key];
            }
            foreach ($s['warnings'] as $w) {
                $warnings[$w] = ($warnings[$w] ?? 0) + 1;
            }
            unset($existing[$contractId]);
        }
        foreach ($existing as $gone) { // a contract that no longer belongs in the run: its draft payslip goes with it
            PayslipLine::where('payslip_id', $gone->id)->delete();
            $gone->delete();
        }
        $run->update(array_map(fn (int $h) => Money::s($h), $sum) + ['slip_count' => count($computed['slips']), 'skipped' => $computed['skipped'] ?: null,
            'warnings' => $warnings ? array_map(fn (string $code, int $n) => ['code' => $code, 'count' => $n], array_keys($warnings), $warnings) : null, 'computed_at' => now()]);
    }

    /**
     * What a fresh computation would change in the stored payslips — payslip numbers (or contract numbers), empty when
     * the run still says what its inputs say.
     *
     * @return string[]
     */
    public function differences(Run $run): array
    {
        $fresh = $this->compute($run)['slips'];
        $out = [];
        foreach (Payslip::where('run_id', $run->id)->get() as $slip) {
            $now = $fresh[$slip->contract_id] ?? null;
            unset($fresh[$slip->contract_id]);
            if (! $now) {
                $out[] = $slip->number;

                continue;
            }
            $stored = PayslipLine::where('payslip_id', $slip->id)->orderBy('sequence')->orderBy('code')->get()->map(fn (PayslipLine $l) => [$l->code, Money::h($l->total), $l->claim_id, $l->adjustment_id])->all();
            $computed = array_map(fn (array $l) => [$l['code'], $l['total'], $l['claim_id'], $l['adjustment_id']], $now['lines']);
            sort($stored);
            sort($computed);
            if ($stored !== $computed) {
                $out[] = $slip->number;
            }
        }
        foreach ($fresh as $s) {
            $out[] = 'new:'.$s['employee_code'];
        }

        return $out;
    }

    /** @return array<int, array{number: string, from: string, to: string, half: bool}> */
    private function unpaidLeaves(string $employeeId, string $from, string $to): array
    {
        return Leave::where('employee_id', $employeeId)->where('state', 'approved')->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)
            ->whereIn('leave_type_id', fn ($q) => $q->select('id')->from('pay_leave_types')->where('paid', false))->orderBy('date_from')->get()
            ->map(fn (Leave $l) => ['number' => $l->number, 'from' => Shape::day($l->date_from), 'to' => Shape::day($l->date_to), 'half' => (bool) $l->half_day])->all();
    }

    /** The adjustments waiting for this employee and month (and the ones this very payslip already carries). */
    private function adjustments(string $employeeId, string $period, ?string $slipId): array
    {
        return Adjustment::where('employee_id', $employeeId)->where('period', $period)->where('state', 'pending')->with('component:id,code')->orderBy('created_at')->orderBy('id')->get()
            ->filter(fn (Adjustment $a) => $a->component !== null && ($a->payslip_id === null || $a->payslip_id === $slipId))
            ->map(fn (Adjustment $a) => ['id' => $a->id, 'code' => $a->component->code, 'amount' => $a->amount])->values()->all();
    }

    /** Posted claims still owed to the employee that no other standing payslip is paying. */
    private function claims(string $employeeId, string $runId): array
    {
        $due = $this->workforce->reimbursementsDue($employeeId);
        if ($due->isEmpty()) {
            return [];
        }
        $taken = PayslipLine::whereIn('claim_id', $due->pluck('id')->all())
            ->whereIn('payslip_id', fn ($q) => $q->select('id')->from('pay_payslips')->whereNotNull('live_key')->where(fn ($w) => $w->whereNull('run_id')->orWhere('run_id', '!=', $runId)))->pluck('claim_id')->flip();

        return $due->reject(fn ($claim) => isset($taken[$claim->id]))->map(fn ($claim) => ['id' => $claim->id, 'number' => $claim->number, 'due' => $claim->employee_due])->values()->all();
    }
}
