<?php

namespace App\Pricing\Support;

use App\Support\AppError;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * A pricing day. Validity periods are whole days as the business counts them (config `app.business_timezone`), held
 * as midnight UTC of that calendar day so they compare with the DATE columns without a time zone getting in the way.
 */
final class Day
{
    public static function today(): Carbon
    {
        return Carbon::parse(now((string) config('app.business_timezone', 'UTC'))->toDateString(), 'UTC');
    }

    /** `YYYY-MM-DD…`, a date object, or nothing (today). */
    public static function of(DateTimeInterface|string|null $date): Carbon
    {
        if ($date === null || $date === '') {
            return self::today();
        }
        if ($date instanceof DateTimeInterface) {
            return Carbon::parse($date->format('Y-m-d'), 'UTC');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            throw AppError::validation('INVALID_DATE', 'التاريخ غير صالح — الصيغة YYYY-MM-DD', 'Invalid date — expected YYYY-MM-DD', [['path' => 'date', 'message' => 'YYYY-MM-DD']]);
        }

        return Carbon::parse(substr($date, 0, 10), 'UTC');
    }
}
