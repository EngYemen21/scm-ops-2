<?php

namespace App\Banking\Support;

use App\Banking\Models\StatementLine;
use App\Banking\Support\Sheet\ColumnMap;

/**
 * What a statement line is recognised by when it arrives a second time: the bank account, the day, the amount, the
 * bank's reference and the description — and which occurrence of exactly that it is. Two real movements of the same
 * day, amount and text (two deposits at one machine) are two lines, #1 and #2; the same file read again gives the same
 * two fingerprints and both are refused. The column is unique, so the database refuses the duplicate even when two
 * people import at once.
 */
final class Fingerprint
{
    public static function of(string $bankAccountId, string $date, int $cents, ?string $reference, ?string $description, int $occurrence): string
    {
        return hash('sha256', $bankAccountId.'|'.ColumnMap::identity($date, $cents, $reference, $description).'|#'.$occurrence);
    }

    /** The fingerprint of a line a person types: the first occurrence of its identity that no line of the bank holds yet. */
    public static function nextFree(string $bankAccountId, string $date, int $cents, ?string $reference, ?string $description, ?string $exceptLineId = null): string
    {
        for ($occurrence = 1; $occurrence <= 2000; $occurrence++) {
            $print = self::of($bankAccountId, $date, $cents, $reference, $description, $occurrence);
            if (! StatementLine::where('fingerprint', $print)->when($exceptLineId, fn ($q) => $q->where('id', '!=', $exceptLineId))->exists()) {
                return $print;
            }
        }

        return hash('sha256', $bankAccountId.'|'.uniqid('', true));
    }
}
