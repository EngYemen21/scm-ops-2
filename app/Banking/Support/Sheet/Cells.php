<?php

namespace App\Banking\Support\Sheet;

use App\Banking\Support\Mask;
use App\Banking\Support\Money;

/**
 * Reading what a bank writes in a cell: a date in the order its portal prints it, an amount with its thousands marks,
 * its sign in any of the ways banks show one (−, trailing −, brackets, DR / CR) and Arabic or Latin digits.
 * Nothing is guessed silently: what cannot be read gives null and the row is refused with its reason.
 */
final class Cells
{
    /** The orders a date is written in; `auto` is settled by the preview. */
    public const DATE_ORDERS = ['dmy', 'mdy', 'ymd'];

    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
        'يناير' => 1, 'فبراير' => 2, 'مارس' => 3, 'ابريل' => 4, 'أبريل' => 4, 'إبريل' => 4, 'مايو' => 5, 'يونيو' => 6, 'يونيه' => 6, 'يوليو' => 7, 'يوليه' => 7,
        'اغسطس' => 8, 'أغسطس' => 8, 'سبتمبر' => 9, 'اكتوبر' => 10, 'أكتوبر' => 10, 'نوفمبر' => 11, 'ديسمبر' => 12,
    ];

    /** A date cell as `YYYY-MM-DD`, or null when it is not a date in that order. */
    public static function date(?string $raw, string $order = 'dmy'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $text = trim(Mask::latinDigits($raw));
        if ($text === '') {
            return null;
        }
        // ISO (what a spreadsheet date cell gives, and many exports): unambiguous whatever the order
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $text, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $text, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // a time after the date is not part of it
        $text = trim((string) preg_replace('/[ T]+\d{1,2}:\d{2}(:\d{2})?(\.\d+)?\s*([AaPp][Mm]|[صم])?$/u', '', $text));
        $parts = array_values(array_filter(preg_split('/[\/\-.\s,،]+/u', $text) ?: [], fn (string $p) => $p !== ''));
        if (count($parts) !== 3) {
            return null;
        }
        [$a, $b, $c] = $parts;
        [$y, $mo, $d] = match ($order) {
            'mdy' => [$c, $a, $b], 'ymd' => [$a, $b, $c], default => [$c, $b, $a],
        };
        $month = ctype_digit($mo) ? (int) $mo : (self::MONTHS[mb_strtolower($mo)] ?? 0);
        if (! ctype_digit($y) || ! ctype_digit($d) || $month === 0) {
            return null;
        }
        $year = (int) $y;
        if (strlen($y) <= 2) {
            $year += $year < 70 ? 2000 : 1900;
        }

        return self::ymd($year, $month, (int) $d);
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        return $y >= 1990 && $y <= 2100 && checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /**
     * Which order the dates of a column are written in, from the values themselves: a first part above 12 can only be a
     * day, a second part above 12 only a day too. When nothing settles it the answer is null (the caller keeps
     * day-month-year, the way this region writes dates, and says it guessed).
     *
     * @param  array<int, ?string>  $samples
     */
    public static function dateOrder(array $samples): ?string
    {
        $firstOver = $secondOver = $yearFirst = false;
        foreach ($samples as $raw) {
            $text = trim(Mask::latinDigits((string) $raw));
            if ($text === '' || preg_match('/^\d{4}-\d{1,2}-\d{1,2}/', $text) || preg_match('/^\d{8}$/', $text)) {
                continue;
            }
            $parts = array_values(array_filter(preg_split('/[\/\-.\s,،]+/u', $text) ?: [], fn (string $p) => $p !== ''));
            if (count($parts) < 3) {
                continue;
            }
            if (ctype_digit($parts[0]) && strlen($parts[0]) === 4) {
                $yearFirst = true;

                continue;
            }
            $firstOver = $firstOver || (ctype_digit($parts[0]) && (int) $parts[0] > 12);
            $secondOver = $secondOver || (ctype_digit($parts[1]) && (int) $parts[1] > 12);
        }
        if ($yearFirst) {
            return 'ymd';
        }

        return $firstOver && ! $secondOver ? 'dmy' : ($secondOver && ! $firstOver ? 'mdy' : null);
    }

    /**
     * An amount cell in hundredths. `$decimal` is the mark the file uses for decimals ("." or ","). Returns null for an
     * empty cell and false for a cell that holds something that is not an amount of at most two decimals.
     */
    public static function amount(?string $raw, string $decimal = '.'): int|false|null
    {
        if ($raw === null) {
            return null;
        }
        $text = trim(Mask::latinDigits($raw));
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }
        $negative = false;
        // the sign, however the bank writes it
        if (preg_match('/^\((.*)\)$/u', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }
        if (preg_match('/\b(DR|DB|D)\b\.?$/i', $text) || preg_match('/^(DR|DB)\b/i', $text) || str_contains($text, 'مدين')) {
            $negative = true;
        }
        $text = (string) preg_replace('/\b(CR|DR|DB|D|C)\b\.?/i', '', $text);
        if (preg_match('/^[^\d]*[-−–]/u', $text) || preg_match('/[-−–]\s*$/u', $text)) {
            $negative = true;
        }
        // Arabic decimal and thousands marks, then everything that is not part of the number (currency, spaces)
        $text = str_replace(['٫', '٬'], [$decimal, ''], $text);
        $text = (string) preg_replace('/[^\d.,]/u', '', $text);
        if ($text === '') {
            return false;
        }
        $text = $decimal === ',' ? str_replace(',', '.', str_replace('.', '', $text)) : str_replace(',', '', $text);
        if (! preg_match('/^\d*\.?\d*$/', $text) || $text === '.') {
            return false;
        }
        if (str_starts_with($text, '.')) {
            $text = '0'.$text;
        }
        if (str_ends_with($text, '.')) {
            $text .= '0';
        }
        if (! Money::valid($text)) {
            return false;
        }
        $cents = Money::cents($text);

        return $negative ? -$cents : $cents;
    }

    /** Which mark a column of amounts uses for decimals: "," when its values end in a comma and one or two digits and none ends in a dot and digits. */
    public static function decimalMark(array $samples): string
    {
        $comma = $dot = 0;
        foreach ($samples as $raw) {
            $text = (string) preg_replace('/[^\d.,]/u', '', Mask::latinDigits((string) $raw));
            if (preg_match('/,\d{1,2}$/', $text)) {
                $comma++;
            } elseif (preg_match('/\.\d{1,2}$/', $text)) {
                $dot++;
            }
        }

        return $comma > 0 && $dot === 0 ? ',' : '.';
    }

    /** Text compared without regard to case, marks or the width of its spaces. */
    public static function norm(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(Mask::latinDigits((string) $text))));
    }
}
