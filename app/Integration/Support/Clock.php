<?php

namespace App\Integration\Support;

/**
 * "Now" for SQL comparisons, with milliseconds. Columns are dateTime(3); binding a Carbon instance directly drops the
 * milliseconds, so a row scheduled "now" (…:10.846) would not be due at …:10 and wait a whole second or a cycle.
 */
final class Clock
{
    public static function sql(int $plusSeconds = 0): string
    {
        return now()->addSeconds($plusSeconds)->format('Y-m-d H:i:s.v');
    }
}
