<?php

namespace App\Commissions\Support;

use App\Support\AppError;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** A commission period is a calendar month, written `YYYY-MM`. */
final class Period
{
    public const PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    public static function valid(?string $period): bool
    {
        return is_string($period) && preg_match(self::PATTERN, $period) === 1;
    }

    /** @return array{0: Carbon, 1: Carbon} the first and the last day of the month */
    public static function bounds(string $period): array
    {
        if (! self::valid($period)) {
            throw AppError::validation('COMMISSION_PERIOD', 'الفترة تُكتب سنة-شهر، مثل 2026-11', 'A period is written year-month, like 2026-11', [['path' => 'period', 'message' => 'YYYY-MM']]);
        }
        $from = Carbon::createFromFormat('!Y-m-d', $period.'-01');

        return [$from, $from->copy()->endOfMonth()->startOfDay()];
    }

    public static function of(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->format('Y-m') : substr($date, 0, 7);
    }

    public static function current(): string
    {
        return Carbon::now()->format('Y-m');
    }

    /** `$period` shifted by a number of months (negative = earlier). */
    public static function shift(string $period, int $months): string
    {
        return self::bounds($period)[0]->addMonthsNoOverflow($months)->format('Y-m');
    }
}
