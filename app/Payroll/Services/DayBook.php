<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Hr\Services\Workforce;
use App\Payroll\Models\Attendance;
use App\Payroll\Models\Holiday;
use App\Payroll\Models\Leave;
use App\Payroll\Support\Shape;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything that is known about the days of some employees in a period, read once: the contracts in force, the
 * public holidays, the approved leaves and the presences. `day()` then says what ONE day was for one employee —
 * the input of the sheet arithmetic (SheetCalculator::day) for the period sheet and for the daily board alike.
 *
 * What a day is, in this order: outside the person's contract (`off`) · not a working day of their schedule
 * (`weekend`) · a public holiday for them (`holiday`) · covered by an approved leave (`leave`) · still to come
 * (`future`) · otherwise a day of work (`work`).
 */
class DayBook
{
    /** @var Collection<int, Holiday> */
    private Collection $holidays;

    /** @var array<string, array<int, array{0:string, 1:?string}>> employee → [contract start, contract end] */
    private array $contracts = [];

    /** @var array<string, array<int, Leave>> */
    private array $leaves = [];

    /** @var array<string, array<string, array<int, Attendance>>> employee → date → presences */
    private array $presences = [];

    private string $today;

    public function __construct(private readonly WorkCalendar $calendar, private readonly Workforce $workforce)
    {
        $this->holidays = collect();
        $this->today = Carbon::today()->toDateString();
    }

    /** @param  string[]  $employeeIds */
    public function load(array $employeeIds, string $from, string $to): static
    {
        $this->contracts = $this->leaves = $this->presences = [];
        $this->holidays = $this->calendar->holidays($from, $to);
        $ids = array_values(array_unique($employeeIds));
        if (! $ids) {
            return $this;
        }
        $wanted = array_flip($ids);
        foreach ($this->workforce->contractsBetween($from, $to) as $c) {
            if (isset($wanted[$c->employee_id])) {
                $this->contracts[$c->employee_id][] = [Shape::day($c->start_date), Shape::day($c->end_date)];
            }
        }
        foreach (Leave::whereIn('employee_id', $ids)->where('state', 'approved')->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->with('type')->get() as $l) {
            $this->leaves[$l->employee_id][] = $l;
        }
        // a presence belongs to the day it started on; one that started the evening before the period is not this period's
        foreach (Attendance::whereIn('employee_id', $ids)->where('status', 'ok')->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->orderBy('check_in')->get() as $a) {
            $this->presences[$a->employee_id][Shape::day($a->work_date)][] = $a;
        }

        return $this;
    }

    /**
     * @return array{date: string, weekday: int, kind: string, planned: array, presences: array<int, array{0:int, 1:?int}>, leave: ?Leave, holiday: ?Holiday, records: int}
     */
    public function day(Employee $employee, string $date): array
    {
        $schedule = $this->calendar->scheduleOf($employee->id);
        $planned = $this->calendar->planned($schedule, $date);
        $holiday = $this->calendar->holidayOn($this->holidays, $date, $employee->id, $employee->department_id);
        $leave = null;
        foreach ($this->leaves[$employee->id] ?? [] as $l) {
            if (Shape::day($l->date_from) <= $date && Shape::day($l->date_to) >= $date) {
                $leave = $l;
                break;
            }
        }
        $kind = match (true) {
            ! $this->employed($employee->id, $date) => 'off',
            ! $planned['working'] => 'weekend',
            $holiday !== null => 'holiday',
            $leave !== null => 'leave',
            $date > $this->today => 'future',
            default => 'work',
        };
        $records = $this->presences[$employee->id][$date] ?? [];
        $presences = array_map(fn (Attendance $a) => [$this->calendar->minuteOf($schedule, $a->check_in, $date), $a->check_out ? $this->calendar->minuteOf($schedule, $a->check_out, $date) : null], $records);

        return ['date' => $date, 'weekday' => WorkCalendar::weekday($date), 'kind' => $kind, 'planned' => $planned, 'presences' => $presences,
            'leave' => $kind === 'leave' ? $leave : null, 'holiday' => $kind === 'holiday' ? $holiday : null, 'records' => count($records)];
    }

    /** A person with contracts in the period is employed on the days one of them covers; one with none is not judged. */
    public function employed(string $employeeId, string $date): bool
    {
        $contracts = $this->contracts[$employeeId] ?? null;
        if ($contracts === null) {
            return true;
        }
        foreach ($contracts as [$start, $end]) {
            if ($start <= $date && ($end === null || $end >= $date)) {
                return true;
            }
        }

        return false;
    }

    public function hasContract(string $employeeId): bool
    {
        return isset($this->contracts[$employeeId]);
    }
}
