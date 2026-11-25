<?php

namespace App\Credit\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * How the target hours of a stage are counted.
 *
 * `working` (the default): only the working time of the business counts — its working days and its day from opening
 * to closing, as the clock shows it in the BUSINESS time zone (config `app.business_timezone`), holidays left out.
 * Eight hours given on Thursday at 16:00 in Riyadh end on Sunday at 15:00, not on Friday at midnight.
 * `calendar`: every hour counts, day and night.
 *
 * The moments it returns are in the application's own time zone (UTC), as every stored moment is.
 */
final class BusinessClock
{
    public const MODES = ['working', 'calendar'];

    /**
     * @param  int[]  $weekdays  the working days, 0 = Sunday … 6 = Saturday
     * @param  int  $startMinute  the opening time, in minutes after midnight
     * @param  int  $endMinute  the closing time, in minutes after midnight
     * @param  string[]  $holidays  days off, `Y-m-d` in the business time zone
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $timezone,
        public readonly array $weekdays,
        public readonly int $startMinute,
        public readonly int $endMinute,
        public readonly array $holidays = [],
    ) {}

    /** @param  array{mode?:string, weekdays?:int[], dayStart?:string, dayEnd?:string, holidays?:string[]}  $calendar */
    public static function from(array $calendar, ?string $timezone = null): self
    {
        return new self(
            in_array($calendar['mode'] ?? null, self::MODES, true) ? $calendar['mode'] : 'working',
            $timezone ?: (string) config('app.business_timezone', 'UTC'),
            array_values(array_unique(array_map('intval', (array) ($calendar['weekdays'] ?? [])))),
            self::minutes((string) ($calendar['dayStart'] ?? '08:00')) ?? 480,
            self::minutes((string) ($calendar['dayEnd'] ?? '17:00')) ?? 1020,
            array_values(array_filter((array) ($calendar['holidays'] ?? []), 'is_string')),
        );
    }

    /** "08:30" → 510; null when the text is not a time of day ("24:00" is the end of the day). */
    public static function minutes(string $time): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }
        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return (int) $m[2] > 59 || $minutes > 1440 ? null : $minutes;
    }

    /** Working time is counted (the mode says so and the calendar has a working day with an open hour in it). */
    public function counted(): bool
    {
        return $this->mode === 'working' && $this->weekdays !== [] && $this->endMinute > $this->startMinute;
    }

    /** The moment that lies `$hours` of counted time after `$from`. */
    public function add(DateTimeInterface $from, float $hours): Carbon
    {
        $left = max(0, (int) round($hours * 3600));
        $t = Carbon::instance($from)->setTimezone($this->timezone);
        if (! $this->counted()) {
            return $t->addSeconds($left)->setTimezone((string) config('app.timezone', 'UTC'));
        }
        for ($day = 0; $day < 4000; $day++) {
            $t = $this->opening($t);
            $close = $this->at($t, $this->endMinute);
            $open = (int) $t->diffInSeconds($close, true);
            if ($left <= $open) {
                $t = $t->copy()->addSeconds($left);
                break;
            }
            $left -= $open;
            $t = $close; // the day is used up: the rest is counted from the next opening
        }

        return $t->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** `$t` itself when the business is open then, otherwise the next moment it opens. */
    public function opening(DateTimeInterface $t): Carbon
    {
        $t = Carbon::instance($t)->setTimezone($this->timezone);
        if (! $this->counted()) {
            return $t;
        }
        for ($day = 0; $day < 800; $day++) {
            if ($this->workingDay($t)) {
                $open = $this->at($t, $this->startMinute);
                if ($t->lt($open)) {
                    return $open;
                }
                if ($t->lt($this->at($t, $this->endMinute))) {
                    return $t;
                }
            }
            $t = $t->copy()->addDay()->startOfDay();
        }

        return $t; // a calendar with no working day within two years: nothing to wait for
    }

    public function workingDay(DateTimeInterface $t): bool
    {
        $t = Carbon::instance($t)->setTimezone($this->timezone);

        return in_array($t->dayOfWeek, $this->weekdays, true) && ! in_array($t->toDateString(), $this->holidays, true);
    }

    private function at(Carbon $day, int $minute): Carbon
    {
        return $day->copy()->startOfDay()->setTime(intdiv($minute, 60), $minute % 60, 0);
    }
}
