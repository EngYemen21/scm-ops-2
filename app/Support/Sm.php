<?php

namespace App\Support;

/**
 * State machines. Transition tables live in config/scm.php (e.g. PO_TRANSITIONS); the server is the only
 * authority on status changes, the client merely renders them.
 */
final class Sm
{
    /** @return array<string, string[]> */
    public static function table(string $name): array
    {
        return config('scm.'.$name, []);
    }

    public static function can(string $table, ?string $from, string $to): bool
    {
        return in_array($to, self::table($table)[$from ?? ''] ?? [], true);
    }

    /** Throws BUSINESS_RULE with $code when $from -> $to is not allowed by $table. */
    public static function assert(string $table, ?string $from, string $to, string $code): void
    {
        if (! self::can($table, $from, $to)) {
            throw AppError::rule($code, "انتقال غير مسموح: {$from} ← {$to}", "Invalid transition: {$from} -> {$to}");
        }
    }
}
