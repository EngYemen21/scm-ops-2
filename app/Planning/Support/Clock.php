<?php

namespace App\Planning\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * The days of planning are the days of the business (config `app.business_timezone`): an order taken at 01:30 in
 * Riyadh belongs to that day, although the database (and Odoo) keep it as 22:30 UTC of the day before.
 */
final class Clock
{
    /** @var array<string, DateTimeZone> */
    private static array $zones = [];

    public static function zone(): DateTimeZone
    {
        $name = (string) config('app.business_timezone', 'Asia/Riyadh');

        return self::$zones[$name] ??= new DateTimeZone($name);
    }

    /** Today, as the business counts it (the tests' clock included). */
    public static function today(): string
    {
        return now(self::zone())->format('Y-m-d');
    }

    /** The business day of a moment kept in UTC — an OPS timestamp or the text Odoo prints (`Y-m-d H:i:s`). */
    public static function day(DateTimeInterface|string|null $utc): ?string
    {
        if ($utc === null || $utc === '' || $utc === false) {
            return null;
        }
        if ($utc instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($utc)->setTimezone(self::zone())->format('Y-m-d');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $utc)) {
            return null;
        }
        if (strlen($utc) <= 10) {
            return substr($utc, 0, 10); // a plain date is already a day
        }
        try {
            return (new DateTimeImmutable($utc, self::$zones['UTC'] ??= new DateTimeZone('UTC')))->setTimezone(self::zone())->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
