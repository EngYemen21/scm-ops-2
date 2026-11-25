<?php

namespace App\Banking\Support\Sheet;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use XMLReader;

/**
 * Reads one sheet of an Excel workbook (.xlsx) into rows of text, without a spreadsheet library: the archive is opened
 * by `Zip`, the XML is streamed. A cell comes out as people see it in the file as far as an import needs it — a text is
 * its text, a number its digits (the noise a binary fraction adds is removed), a date cell `YYYY-MM-DD` (with the time
 * when it has one). Row `i` of the answer is row `i + 1` of the sheet, so a refusal can name the row a person looks at.
 */
final class XlsxReader
{
    public const MAX_ROWS = 20000;

    public const MAX_COLUMNS = 80;

    /** Excel's built-in number formats that are dates or times. */
    private const DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    /**
     * @return array{sheets: string[], sheet: string, rows: array<int, array<int, ?string>>}
     */
    public static function read(string $bytes, ?string $sheet = null): array
    {
        $zip = new Zip($bytes);
        [$sheets, $date1904] = self::workbook($zip);
        if (! $sheets) {
            throw new RuntimeException('the workbook has no sheet');
        }
        $name = $sheet !== null && isset($sheets[$sheet]) ? $sheet : (string) array_key_first($sheets);
        $xml = $zip->read($sheets[$name]) ?? throw new RuntimeException('the sheet is missing from the workbook');
        $strings = self::sharedStrings($zip->read('xl/sharedStrings.xml'));
        $dateStyles = self::dateStyles($zip->read('xl/styles.xml'));

        return ['sheets' => array_keys($sheets), 'sheet' => $name, 'rows' => self::rows($xml, $strings, $dateStyles, $date1904)];
    }

    /** @return array{0: array<string, string>, 1: bool} sheet name → its path inside the archive; the 1904 date system */
    private static function workbook(Zip $zip): array
    {
        $workbook = $zip->read('xl/workbook.xml') ?? throw new RuntimeException('not an Excel workbook');
        $targets = [];
        if ($rels = $zip->read('xl/_rels/workbook.xml.rels')) {
            $r = self::reader($rels);
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship') {
                    $target = (string) $r->getAttribute('Target');
                    $targets[(string) $r->getAttribute('Id')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
        }
        $sheets = [];
        $date1904 = false;
        $n = 0;
        $r = self::reader($workbook);
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'workbookPr') {
                $date1904 = in_array($r->getAttribute('date1904'), ['1', 'true'], true);
            } elseif ($r->localName === 'sheet') {
                $n++;
                $id = $r->getAttributeNs('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? $r->getAttribute('r:id');
                $path = $targets[(string) $id] ?? "xl/worksheets/sheet{$n}.xml";
                if ($zip->has($path)) {
                    $sheets[(string) ($r->getAttribute('name') ?? "Sheet{$n}")] = $path;
                }
            }
        }
        if (! $sheets && $zip->has('xl/worksheets/sheet1.xml')) {
            $sheets['Sheet1'] = 'xl/worksheets/sheet1.xml';
        }

        return [$sheets, $date1904];
    }

    /** @return string[] */
    private static function sharedStrings(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }
        $out = [];
        $r = self::reader($xml);
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                $out[] = self::text($r);
            }
        }

        return $out;
    }

    /** The text of a string item / an inline string: its `<t>` runs joined, the phonetic guides (`<rPh>`) left out. */
    private static function text(XMLReader $r): string
    {
        if ($r->isEmptyElement) {
            return '';
        }
        $depth = $r->depth;
        $text = '';
        $phonetic = null;
        while ($r->read() && ! ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $depth)) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'rPh' && ! $r->isEmptyElement) {
                $phonetic = $r->depth;
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $phonetic !== null && $r->depth === $phonetic) {
                $phonetic = null;
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't' && $phonetic === null) {
                $text .= $r->readString();
            }
        }

        return $text;
    }

    /** @return array<int, true> the cell styles (index of `cellXfs`) that show a date or a time */
    private static function dateStyles(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }
        $custom = [];
        $styles = [];
        $index = 0;
        $inCellXfs = false;
        $r = self::reader($xml);
        while ($r->read()) {
            if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
                $inCellXfs = false;
            }
            if ($r->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($r->localName === 'numFmt') {
                $custom[(int) $r->getAttribute('numFmtId')] = self::isDateFormat((string) $r->getAttribute('formatCode'));
            } elseif ($r->localName === 'cellXfs') {
                $inCellXfs = ! $r->isEmptyElement;
            } elseif ($r->localName === 'xf' && $inCellXfs) {
                $format = (int) $r->getAttribute('numFmtId');
                if ($custom[$format] ?? in_array($format, self::DATE_FORMATS, true)) {
                    $styles[$index] = true;
                }
                $index++;
            }
        }

        return $styles;
    }

    /** A custom format is a date / time when, outside its quoted text and bracketed parts, it names a day, month, year, hour or second. */
    private static function isDateFormat(string $code): bool
    {
        $bare = preg_replace(['/"[^"]*"/', '/\[[^\]]*\]/', '/\\\\./', '/_./', '/\*./'], '', $code) ?? '';

        return ! preg_match('/general/i', $bare) && (bool) preg_match('/[dmyhs]/i', $bare);
    }

    /** @return array<int, array<int, ?string>> */
    private static function rows(string $xml, array $strings, array $dateStyles, bool $date1904): array
    {
        $rows = [];
        $r = self::reader($xml);
        $last = 0;
        while ($r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') {
                continue;
            }
            $number = (int) $r->getAttribute('r') ?: $last + 1;
            if ($number > self::MAX_ROWS) {
                throw new RuntimeException('too many rows');
            }
            for ($i = $last + 1; $i < $number; $i++) {
                $rows[] = [];   // a row the file skips is an empty row: row numbers stay the sheet's own
            }
            $last = $number;
            $cells = [];
            if (! $r->isEmptyElement) {
                $depth = $r->depth;
                $column = -1;
                while ($r->read() && ! ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $depth)) {
                    if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'c') {
                        continue;
                    }
                    $ref = (string) $r->getAttribute('r');
                    $column = $ref !== '' ? self::column($ref) : $column + 1;
                    $type = (string) $r->getAttribute('t');
                    $style = (int) $r->getAttribute('s');
                    $value = null;
                    if (! $r->isEmptyElement) {
                        $cellDepth = $r->depth;
                        while ($r->read() && ! ($r->nodeType === XMLReader::END_ELEMENT && $r->depth === $cellDepth)) {
                            if ($r->nodeType !== XMLReader::ELEMENT) {
                                continue;
                            }
                            if ($r->localName === 'v') {
                                $value = $r->readString();
                            } elseif ($r->localName === 'is') {
                                $value = self::text($r);
                            }
                        }
                    }
                    if ($column >= 0 && $column < self::MAX_COLUMNS) {
                        $cells[$column] = self::cell($value, $type, isset($dateStyles[$style]), $strings, $date1904);
                    }
                }
            }
            $row = [];
            if ($cells) {
                for ($i = 0, $width = max(array_keys($cells)); $i <= $width; $i++) {
                    $row[] = $cells[$i] ?? null;
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function cell(?string $value, string $type, bool $isDate, array $strings, bool $date1904): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = match ($type) {
            's' => $strings[(int) $value] ?? '',
            'inlineStr', 'str' => $value,
            'b' => $value === '1' ? 'TRUE' : 'FALSE',
            'e' => '',
            'd' => str_replace('T', ' ', $value),
            default => is_numeric($value) ? ($isDate ? self::date((float) $value, $date1904) : self::number($value)) : $value,
        };
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /** A1 → 0, B7 → 1, AA3 → 26. */
    private static function column(string $ref): int
    {
        $n = 0;
        foreach (str_split(strtoupper((string) preg_replace('/[^A-Za-z]/', '', $ref))) as $letter) {
            $n = $n * 26 + (ord($letter) - 64);
        }

        return $n - 1;
    }

    /** A stored number as its digits: 1234.5600000000001 → "1234.56", 5.0 → "5", 1E-3 → "0.001". */
    private static function number(string $value): string
    {
        if (preg_match('/^-?\d+$/', $value)) {
            return $value;
        }
        $text = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /** An Excel serial day as a date; day 60 of the 1900 system is the 29 February 1900 that never was. */
    private static function date(float $serial, bool $date1904): string
    {
        $days = (int) floor($serial);
        $seconds = (int) round(($serial - $days) * 86400);
        $base = new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new DateTimeZone('UTC'));
        if (! $date1904 && $days < 60) {
            $days++;
        }
        $at = $base->modify(($days >= 0 ? '+' : '').$days.' days')->modify("+{$seconds} seconds");

        return $at->format($at->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    private static function reader(string $xml): XMLReader
    {
        $reader = XMLReader::XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOWARNING | LIBXML_NOERROR);
        if (! $reader instanceof XMLReader) {
            throw new RuntimeException('the workbook cannot be read');
        }

        return $reader;
    }
}
