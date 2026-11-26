<?php

namespace App\Payroll\Services;

use App\Hr\Models\Employee;
use App\Payroll\Models\AttendancePolicy;
use App\Payroll\Models\Holiday;
use App\Payroll\Models\Profile;
use App\Payroll\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The working time of an employee: which schedule and attendance policy apply to them, what a given day plans
 * (sign in, sign out, the stretches of work), and which days are public holidays for them. Time off, attendance sheets
 * and payroll all ask here, so a day means the same thing everywhere.
 *
 * Weekdays are 0 = Monday … 6 = Sunday; times of day are minutes after midnight on the schedule's own clock.
 */
class WorkCalendar
{
    /** @var array<string, ?Schedule> */
    private array $schedules = [];

    /** @var array<string, ?AttendancePolicy> */
    private array $policies = [];

    /** @var array<string, ?Profile> */
    private array $profiles = [];

    private ?Schedule $default = null;

    private bool $defaultRead = false;

    public static function weekday(string $date): int
    {
        return Carbon::parse($date)->dayOfWeekIso - 1;
    }

    public function profileOf(string $employeeId): ?Profile
    {
        if (! array_key_exists($employeeId, $this->profiles)) {
            $this->profiles[$employeeId] = Profile::where('employee_id', $employeeId)->first();
        }

        return $this->profiles[$employeeId];
    }

    public function defaultSchedule(): ?Schedule
    {
        if (! $this->defaultRead) {
            $this->default = Schedule::with('days')->where('active', true)->orderByDesc('is_default')->orderBy('created_at')->orderBy('id')->first();
            $this->defaultRead = true;
        }

        return $this->default;
    }

    /** The schedule an employee works: the one on their pay profile while it is active, otherwise the company's default. */
    public function scheduleOf(string $employeeId): ?Schedule
    {
        if (! array_key_exists($employeeId, $this->schedules)) {
            $id = $this->profileOf($employeeId)?->schedule_id;
            $this->schedules[$employeeId] = ($id ? Schedule::with('days')->where('active', true)->find($id) : null) ?? $this->defaultSchedule();
        }

        return $this->schedules[$employeeId];
    }

    /**
     * The attendance policy of an employee: the one on their pay profile, otherwise the default policy. A policy that
     * is known by name only (its rules could not be read from the source system) decides nothing: the default stands in.
     */
    public function policyOf(string $employeeId): ?AttendancePolicy
    {
        if (! array_key_exists($employeeId, $this->policies)) {
            $with = ['lateRule.lines', 'earlyRule.lines', 'absenceRule.lines', 'overtimeRules'];
            $id = $this->profileOf($employeeId)?->policy_id;
            $this->policies[$employeeId] = ($id ? AttendancePolicy::with($with)->where('active', true)->where('incomplete', false)->find($id) : null)
                ?? AttendancePolicy::with($with)->where('active', true)->where('is_default', true)->orderBy('created_at')->first();
        }

        return $this->policies[$employeeId];
    }

    public function timezone(?Schedule $schedule): string
    {
        return $schedule?->timezone ?: (string) config('app.business_timezone', 'Asia/Riyadh');
    }

    /**
     * What a schedule plans for a date.
     *
     * @return array{working: bool, in: ?int, out: ?int, minutes: int, intervals: array<int, array{0:int, 1:int}>}
     */
    public function planned(?Schedule $schedule, string $date): array
    {
        $intervals = [];
        if ($schedule) {
            $weekday = self::weekday($date);
            foreach ($schedule->days as $d) {
                if ($d->weekday === $weekday && $d->kind === 'work' && $d->to_minute > $d->from_minute) {
                    $intervals[] = [$d->from_minute, $d->to_minute];
                }
            }
        }
        $intervals = self::merge($intervals);
        if (! $intervals) {
            return ['working' => false, 'in' => null, 'out' => null, 'minutes' => 0, 'intervals' => []];
        }

        return ['working' => true, 'in' => $intervals[0][0], 'out' => $intervals[count($intervals) - 1][1],
            'minutes' => array_sum(array_map(fn (array $i) => $i[1] - $i[0], $intervals)), 'intervals' => $intervals];
    }

    /** The public holidays that touch a period. @return Collection<int, Holiday> */
    public function holidays(string $from, string $to): Collection
    {
        return Holiday::where('active', true)->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)->orderBy('date_from')->get();
    }

    /** The holiday a date is for this employee, out of the holidays of the period. */
    public function holidayOn(Collection $holidays, string $date, string $employeeId, ?string $departmentId): ?Holiday
    {
        foreach ($holidays as $h) {
            if ($h->date_from->format('Y-m-d') > $date || $h->date_to->format('Y-m-d') < $date) {
                continue;
            }
            $ids = (array) ($h->scope_ids ?? []);
            if ($h->scope === 'company' || ($h->scope === 'department' && $departmentId !== null && in_array($departmentId, $ids, true)) || ($h->scope === 'employee' && in_array($employeeId, $ids, true))) {
                return $h;
            }
        }

        return null;
    }

    /**
     * The days a leave from…to costs an employee: the working days of their schedule in the range that are not public
     * holidays; a half-day request of a single day costs 0.5.
     *
     * @return array{days: float, dates: string[]}
     */
    public function leaveDays(Employee $employee, string $from, string $to, bool $half = false): array
    {
        $schedule = $this->scheduleOf($employee->id);
        $holidays = $this->holidays($from, $to);
        $dates = [];
        for ($d = Carbon::parse($from); $d->format('Y-m-d') <= $to; $d = $d->copy()->addDay()) {
            $date = $d->format('Y-m-d');
            if ($this->planned($schedule, $date)['working'] && ! $this->holidayOn($holidays, $date, $employee->id, $employee->department_id)) {
                $dates[] = $date;
            }
        }

        return ['days' => $half && $from === $to ? (count($dates) ? 0.5 : 0.0) : (float) count($dates), 'dates' => $dates];
    }

    /** A time of day in minutes on a schedule's clock, for a moment kept in UTC — counted from the midnight of `$workDate`. */
    public function minuteOf(?Schedule $schedule, \DateTimeInterface $moment, string $workDate): int
    {
        $tz = $this->timezone($schedule);
        $local = Carbon::instance($moment)->setTimezone($tz);
        $midnight = Carbon::parse($workDate, $tz)->startOfDay();

        return (int) floor(($local->getTimestamp() - $midnight->getTimestamp()) / 60);
    }

    /** The date a moment falls on, on a schedule's clock. */
    public function dateOf(?Schedule $schedule, \DateTimeInterface $moment): string
    {
        return Carbon::instance($moment)->setTimezone($this->timezone($schedule))->format('Y-m-d');
    }

    /** Sorted, with overlapping or touching stretches joined. @param array<int, array{0:int, 1:int}> $intervals */
    public static function merge(array $intervals): array
    {
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($intervals as $i) {
            $last = count($out) - 1;
            if ($last >= 0 && $i[0] <= $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $i[1]);
            } else {
                $out[] = $i;
            }
        }

        return $out;
    }
}
