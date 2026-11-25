<?php

namespace App\Hr\Services;

use App\Hr\Models\Claim;
use App\Hr\Models\Contract;
use App\Hr\Models\Employee;
use App\Hr\Models\EmployeePrivate;
use App\Hr\Support\Hr;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The READ side of People & expenses for the modules built on top of it — payroll, time off, attendance
 * (docs/odoo/WAVE3.md P6): who works here, who reports to whom, which contract is in force on a day or during a
 * month and what it pays, what the company still owes an employee from posted expense claims, and where their pay
 * is sent.
 *
 * Read-only: nothing here changes a row of this section (the acts are the services' — ContractsService,
 * ClaimsService::pay). It answers CODE, not a person: contracts and bank accounts are people's files, and the module
 * that calls decides who may see what it builds from them (its own permissions; here they are behind `hr.private`).
 *
 * Models are returned as they are (App\Hr\Models\Employee, Contract, Claim): read their attributes, do not save them.
 */
class Workforce
{
    /** The states in which a contract is, or was, in force (a draft never was; a cancelled one is treated as never). */
    public const IN_FORCE = ['running', 'expired'];

    /** Employees at work, by name — of the whole company or of one department. @return Collection<int, Employee> */
    public function active(?string $departmentId = null): Collection
    {
        return Employee::where('status', 'active')->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))->orderBy('name_ar')->orderBy('id')->get();
    }

    public function employee(string $idOrCode): ?Employee
    {
        return Employee::where('id', $idOrCode)->orWhere('code', $idOrCode)->first();
    }

    /** The employee record of an OPS login — the person behind a session ("my payslip", "my leave"). */
    public function ofUser(string $userId): ?Employee
    {
        return Employee::where('user_id', $userId)->first();
    }

    /** The people who report directly to an employee and are at work (who a manager approves leave for). @return Collection<int, Employee> */
    public function reportsOf(string $employeeId): Collection
    {
        return Employee::where('manager_id', $employeeId)->where('status', 'active')->orderBy('name_ar')->orderBy('id')->get();
    }

    /**
     * The contract in force for an employee on a day (today when none is given): it had started, had not ended, and
     * was never cancelled. An employee has one running contract at a time; when history overlaps, the latest start wins.
     */
    public function contractOn(string $employeeId, CarbonInterface|string|null $day = null): ?Contract
    {
        $day = self::day($day);

        return $this->inForce($day, $day)->where('employee_id', $employeeId)->orderByDesc('start_date')->orderByDesc('id')->first();
    }

    /**
     * Every contract in force at some point between two days (both included) — a month, for a payroll run. One row per
     * contract: an employee whose contract was renewed inside the period has two, each with its own dates.
     *
     * @return Collection<int, Contract> with `employee` loaded
     */
    public function contractsBetween(CarbonInterface|string $from, CarbonInterface|string $to): Collection
    {
        return $this->inForce(self::day($from), self::day($to))->with('employee')->orderBy('employee_id')->orderBy('start_date')->orderBy('id')->get();
    }

    /**
     * The monthly pay of a contract as numbers (the model keeps them as decimal strings).
     *
     * @return array{basic: float, housing: float, transport: float, other: float, total: float, currency: string}
     */
    public static function pay(Contract $contract): array
    {
        $pay = ['basic' => (float) $contract->wage, 'housing' => (float) $contract->housing_allowance, 'transport' => (float) $contract->transport_allowance, 'other' => (float) $contract->other_allowances];

        return $pay + ['total' => round(array_sum($pay), 2), 'currency' => (string) $contract->currency];
    }

    /**
     * Expense claims posted to the OPS books whose reimbursement is still owed (`posted`, accrued) — of one employee
     * or of everyone. `employee_due` is the amount; paying one is ClaimsService::pay($user, $claim->id, ['reference' => …]).
     * What Odoo posted is in Odoo's books and is settled there: it is never listed here.
     *
     * @return Collection<int, Claim>
     */
    public function reimbursementsDue(?string $employeeId = null): Collection
    {
        return Claim::where('state', 'posted')->where('employee_due', '>', 0)->where('posted_in', 'ops')->when($employeeId, fn ($q) => $q->where('employee_id', $employeeId))
            ->orderBy('posted_at')->orderBy('id')->get();
    }

    /**
     * Where an employee's pay is sent, from the personal file. People's data: ask only for what a transfer needs, and
     * only from code whose caller may see it.
     *
     * @return array{bankName: ?string, bankAccount: ?string}
     */
    public function bankOf(string $employeeId): array
    {
        $file = EmployeePrivate::where('employee_id', $employeeId)->first(['bank_name', 'bank_account']);

        return ['bankName' => $file?->bank_name, 'bankAccount' => $file?->bank_account];
    }

    /** Contracts that were in force at some point of [from, to]. */
    private function inForce(string $from, string $to)
    {
        return Contract::query()->whereIn('state', self::IN_FORCE)->whereDate('start_date', '<=', $to)->where(fn ($w) => $w->whereNull('end_date')->orWhereDate('end_date', '>=', $from));
    }

    private static function day(CarbonInterface|string|null $day): string
    {
        return $day === null ? Carbon::today()->toDateString() : (string) Hr::day($day instanceof CarbonInterface ? $day : Carbon::parse($day));
    }
}
