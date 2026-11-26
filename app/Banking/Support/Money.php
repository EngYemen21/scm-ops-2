<?php

namespace App\Banking\Support;

use InvalidArgumentException;

/**
 * Money of the banking section, exact: every amount is an integer number of halalas (hundredths) while it is
 * compared, added or matched, and a decimal string ("1234.50") when it is stored or sent. A float never takes part in a
 * comparison — "a match never leaves a cent unexplained" is only true of integers.
 */
final class Money
{
    /** The largest amount a column of the section holds (decimal 16,2). */
    public const MAX_CENTS = 99_999_999_999_999;

    /** Is this a money value: a number with at most two decimals that fits a column of the section? */
    public static function valid(mixed $value): bool
    {
        try {
            return abs(self::cents($value)) <= self::MAX_CENTS;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The amount in hundredths. A decimal string is read digit by digit (never through a float); a float — a JSON
     * number, a spreadsheet cell — is rounded to the nearest hundredth.
     */
    public static function cents(mixed $value): int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('not a finite amount');
            }

            return (int) round($value * 100);
        }
        if (! is_string($value) || ! preg_match('/^\s*([+-]?)(\d+)(?:\.(\d+))?\s*$/', $value, $m)) {
            throw new InvalidArgumentException('not an amount');
        }
        $fraction = $m[3] ?? '';
        if (strlen($fraction) > 2 && rtrim(substr($fraction, 2), '0') !== '') {
            throw new InvalidArgumentException('more than two decimals');
        }
        if (strlen(ltrim($m[2], '0')) > 15) {
            throw new InvalidArgumentException('amount too large');
        }
        $cents = (int) $m[2] * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }

    /** Hundredths as the decimal string the API and the database speak: 123450 → "1234.50", -5 → "-0.05". */
    public static function str(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A stored decimal (or null) as its exact string, for the JSON of the section. */
    public static function out(mixed $value): ?string
    {
        return $value === null ? null : self::str(self::cents((string) $value));
    }

    /** The sum of stored decimals, in hundredths. */
    public static function sum(iterable $values): int
    {
        $total = 0;
        foreach ($values as $value) {
            $total += self::cents((string) $value);
        }

        return $total;
    }

    /** What the ledger service is handed: it rounds a float to two decimals itself, and two decimals survive a float. */
    public static function float(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** "1,234.50" for a message a person reads. */
    public static function human(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
