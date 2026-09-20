<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The few SQL fragments that differ between MySQL and PostgreSQL, for the raw queries of the dashboard and reports.
 * Everything else goes through the query builder, which is portable already. Both engines run the whole test suite.
 *
 * Arguments are SQL expressions (column names, `?` placeholders, literals) — never user input.
 */
final class Sql
{
    public static function pg(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    /** Whole seconds from $from to $to (negative when $to is earlier). */
    public static function seconds(string $from, string $to): string
    {
        return self::pg()
            ? "TRUNC(EXTRACT(EPOCH FROM (({$to})::timestamp - ({$from})::timestamp)))"
            : "TIMESTAMPDIFF(SECOND, {$from}, {$to})";
    }

    /** Calendar days from $b to $a, like MySQL's DATEDIFF(a, b). */
    public static function days(string $a, string $b): string
    {
        return self::pg() ? "(({$a})::date - ({$b})::date)" : "DATEDIFF({$a}, {$b})";
    }

    /** $timestamp plus a (fractional) number of hours, rounded to the second. */
    public static function addHours(string $timestamp, string $hours): string
    {
        return self::pg()
            ? "({$timestamp} + ROUND(({$hours}) * 3600) * INTERVAL '1 second')"
            : "TIMESTAMPADD(SECOND, ROUND(({$hours}) * 3600), {$timestamp})";
    }

    /** Distinct values of $expr joined with commas, in order. */
    public static function joinDistinct(string $expr): string
    {
        return self::pg() ? "STRING_AGG(DISTINCT {$expr}, ',' ORDER BY {$expr})" : "GROUP_CONCAT(DISTINCT {$expr} ORDER BY {$expr} SEPARATOR ',')";
    }

    /** Case-insensitive LIKE (MySQL's collation already is; PostgreSQL needs ILIKE). */
    public static function like(): string
    {
        return self::pg() ? 'ILIKE' : 'LIKE';
    }

}
