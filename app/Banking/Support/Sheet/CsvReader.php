<?php

namespace App\Banking\Support\Sheet;

use RuntimeException;

/**
 * Reads a delimited text file (CSV) a bank exports into rows of text. The encoding is told from the bytes — UTF-8 or
 * UTF-16 with their marks, otherwise Arabic Windows (1256), which is what older banking portals still write — and the
 * delimiter from the first lines (comma, semicolon, tab or bar), unless the caller names them. Quoted fields may hold
 * the delimiter and line breaks.
 */
final class CsvReader
{
    public const MAX_ROWS = 20000;

    public const DELIMITERS = [',', ';', "\t", '|'];

    public const ENCODINGS = ['utf-8', 'utf-16', 'windows-1256', 'windows-1252'];

    /**
     * @return array{rows: array<int, array<int, ?string>>, delimiter: string, encoding: string}
     */
    public static function read(string $bytes, ?string $delimiter = null, ?string $encoding = null): array
    {
        [$text, $encoding] = self::decode($bytes, $encoding);
        $delimiter = in_array($delimiter, self::DELIMITERS, true) ? $delimiter : self::delimiter($text);
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('the file cannot be read');
        }
        fwrite($handle, $text);
        rewind($handle);
        $rows = [];
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw new RuntimeException('too many rows');
            }
            $row = [];
            foreach ($cells as $cell) {
                $cell = $cell === null ? '' : trim((string) $cell);
                $row[] = $cell === '' ? null : $cell;
            }
            while ($row && end($row) === null) {
                array_pop($row);   // trailing delimiters are not columns
            }
            $rows[] = $row;
        }
        fclose($handle);

        return ['rows' => $rows, 'delimiter' => $delimiter, 'encoding' => $encoding];
    }

    /** @return array{0:string, 1:string} the text as UTF-8 without a byte-order mark, and the encoding it was read from */
    private static function decode(string $bytes, ?string $encoding): array
    {
        if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) {
            return [substr($bytes, 3), 'utf-8'];
        }
        if (strncmp($bytes, "\xFF\xFE", 2) === 0 || strncmp($bytes, "\xFE\xFF", 2) === 0) {
            $from = $bytes[0] === "\xFF" ? 'UTF-16LE' : 'UTF-16BE';

            return [(string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', $from), 'utf-16'];
        }
        $encoding = strtolower((string) $encoding);
        if ($encoding === 'utf-16') {
            return [(string) mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE'), 'utf-16'];
        }
        if (in_array($encoding, ['windows-1256', 'windows-1252'], true)) {
            return [self::fromCodePage($bytes, $encoding), $encoding];
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, 'utf-8'];
        }

        return [self::fromCodePage($bytes, 'windows-1256'), 'windows-1256'];
    }

    private static function fromCodePage(string $bytes, string $codePage): string
    {
        $text = @iconv(strtoupper($codePage), 'UTF-8//IGNORE', $bytes);
        if ($text === false) {
            throw new RuntimeException('the encoding of the file is not recognised');
        }

        return $text;
    }

    /** The delimiter that splits the first lines into the most columns, the same number on most of them. */
    private static function delimiter(string $text): string
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\r|\n/', substr($text, 0, 16384)) ?: [], fn (string $l) => trim($l) !== '')), 0, 25);
        $best = ',';
        $bestScore = 0.0;
        foreach (self::DELIMITERS as $candidate) {
            $counts = array_map(fn (string $line) => count(str_getcsv($line, $candidate, '"', '')) - 1, $lines);
            $counts = array_values(array_filter($counts, fn (int $n) => $n > 0));
            if (! $counts) {
                continue;
            }
            $frequency = array_count_values($counts);
            arsort($frequency);
            $columns = (int) array_key_first($frequency);
            // most lines agree on the number of columns, and more columns is the better split
            $score = (reset($frequency) / max(1, count($lines))) * $columns;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
