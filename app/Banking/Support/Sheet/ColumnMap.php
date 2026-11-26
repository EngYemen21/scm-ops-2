<?php

namespace App\Banking\Support\Sheet;

use App\Banking\Support\Mask;
use App\Support\AppError;

/**
 * From the rows of a bank's file to statement lines: which row holds the column titles, which column is what
 * (the mapping a person confirms once and the section keeps per bank), and every data row read into a date, a signed
 * amount and its texts — or refused with the reason, row by row.
 *
 * A column is named in a mapping by its title in the file (so the mapping survives a bank adding a column) or by its
 * position `#N` (a file without titles).
 */
final class ColumnMap
{
    public const FIELDS = ['date', 'valueDate', 'description', 'reference', 'amount', 'debit', 'credit', 'type', 'balance', 'partnerName', 'partnerAccount'];

    /** signed: one column, + in / − out · debit_credit: one column per side · amount_type: an amount and a debit / credit mark. */
    public const AMOUNT_MODES = ['signed', 'debit_credit', 'amount_type'];

    public const ORDERS = ['auto', 'asc', 'desc'];

    public const MAX_LINES = 10000;

    /** Titles banks give their columns, most specific first (a "value date" is not the transaction date). */
    private const TITLES = [
        'valueDate' => ['value date', 'value dt', 'تاريخ القيمة', 'تاريخ الاستحقاق'],
        'date' => ['transaction date', 'posting date', 'trans date', 'txn date', 'booking date', 'تاريخ العملية', 'تاريخ الحركة', 'تاريخ المعاملة', 'تاريخ القيد', 'التاريخ', 'date', 'تاريخ'],
        'debit' => ['debit', 'withdrawal', 'paid out', 'money out', 'مدين', 'سحب', 'مسحوبات', 'مدفوعات'],
        'credit' => ['credit', 'deposit', 'paid in', 'money in', 'دائن', 'إيداع', 'ايداع', 'مقبوضات'],
        'balance' => ['running balance', 'balance', 'الرصيد', 'رصيد'],
        'amount' => ['amount', 'المبلغ', 'مبلغ', 'القيمة'],
        'type' => ['dr/cr', 'cr/dr', 'transaction type', 'txn type', 'نوع العملية', 'نوع الحركة', 'النوع', 'type'],
        'reference' => ['reference', 'ref no', 'ref.', 'transaction id', 'trans id', 'cheque', 'check no', 'رقم المرجع', 'المرجع', 'رقم العملية', 'رقم الشيك', 'ref'],
        'partnerAccount' => ['iban', 'beneficiary account', 'counterparty account', 'account number', 'account no', 'الآيبان', 'الايبان', 'ايبان', 'رقم الحساب', 'حساب المستفيد'],
        'partnerName' => ['beneficiary', 'counterparty', 'payee', 'payer', 'sender', 'اسم المستفيد', 'المستفيد', 'الطرف المقابل', 'المرسل'],
        'description' => ['description', 'narrative', 'narration', 'details', 'particulars', 'remarks', 'memo', 'البيان', 'التفاصيل', 'الوصف', 'الشرح', 'ملاحظات'],
    ];

    private const DEBIT_WORDS = ['dr', 'd', 'debit', 'db', 'withdrawal', 'مدين', 'سحب', 'خصم', '-'];

    private const CREDIT_WORDS = ['cr', 'c', 'credit', 'deposit', 'دائن', 'إيداع', 'ايداع', 'إضافة', '+'];

    /** The reading options of a file with their defaults; unknown values fall back to the default. */
    public static function options(array $o): array
    {
        return [
            'sheet' => isset($o['sheet']) && $o['sheet'] !== '' ? mb_substr((string) $o['sheet'], 0, 120) : null,
            'delimiter' => in_array($o['delimiter'] ?? null, [',', ';', '|', 'tab'], true) ? $o['delimiter'] : null,
            'encoding' => in_array($o['encoding'] ?? null, CsvReader::ENCODINGS, true) ? $o['encoding'] : null,
            'headerRow' => isset($o['headerRow']) && is_numeric($o['headerRow']) ? max(0, min(200, (int) $o['headerRow'])) : null,
            'dateOrder' => in_array($o['dateOrder'] ?? null, Cells::DATE_ORDERS, true) ? $o['dateOrder'] : null,
            'decimal' => in_array($o['decimal'] ?? null, ['.', ','], true) ? $o['decimal'] : null,
            'amountMode' => in_array($o['amountMode'] ?? null, self::AMOUNT_MODES, true) ? $o['amountMode'] : null,
            'invert' => filter_var($o['invert'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'order' => in_array($o['order'] ?? null, self::ORDERS, true) ? $o['order'] : 'auto',
        ];
    }

    /**
     * The row that holds the column titles (1-based), or 0 when no row looks like one: the first of the top rows in
     * which at least two cells are titles a bank uses.
     *
     * @param  array<int, array<int, ?string>>  $rows
     */
    public static function detectHeaderRow(array $rows): int
    {
        foreach (array_slice($rows, 0, 40, true) as $i => $row) {
            $hits = 0;
            foreach ($row as $cell) {
                if ($cell === null) {
                    continue;
                }
                // a row of titles holds no number and no date: a data row that happens to say "deposit" is not one
                if (preg_match('/^[+\-(]?\d[\d.,\/\-: )]*$/', Mask::latinDigits($cell))) {
                    continue 2;
                }
                $hits += mb_strlen($cell) <= 40 && self::fieldOf($cell) !== null ? 1 : 0;
            }
            if ($hits >= 2) {
                return $i + 1;
            }
        }

        return 0;
    }

    /** The field a column title names, if any. */
    private static function fieldOf(string $title, array $taken = []): ?string
    {
        $title = Cells::norm($title);
        foreach (self::TITLES as $field => $words) {
            if (isset($taken[$field])) {
                continue;
            }
            foreach ($words as $word) {
                if ($title === $word || str_contains($title, $word)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * The columns of the table: position, title (unique: a repeated title gets " (2)"), and a few values.
     *
     * @return array<int, array{ref:string, name:string, header:?string, samples:string[]}>
     */
    public static function columns(array $rows, int $headerRow): array
    {
        $header = $headerRow > 0 ? ($rows[$headerRow - 1] ?? []) : [];
        $data = array_slice($rows, $headerRow, 200);
        $width = count($header);
        foreach ($data as $row) {
            $width = max($width, count($row));
        }
        $width = min($width, XlsxReader::MAX_COLUMNS);
        $seen = [];
        $out = [];
        for ($c = 0; $c < $width; $c++) {
            $title = isset($header[$c]) && $header[$c] !== null ? mb_substr($header[$c], 0, 120) : null;
            $name = $title ?? '#'.($c + 1);
            $key = Cells::norm($name);
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > 1) {
                $name .= " ({$seen[$key]})";
            }
            $samples = [];
            foreach ($data as $row) {
                if (isset($row[$c]) && $row[$c] !== null && count($samples) < 4) {
                    $samples[] = mb_substr($row[$c], 0, 80);
                }
            }
            $out[] = ['ref' => '#'.($c + 1), 'name' => $name, 'header' => $title, 'samples' => $samples];
        }

        return $out;
    }

    /**
     * The mapping the titles suggest, for a person to confirm.
     *
     * @return array{mapping: array<string, string>, amountMode: ?string}
     */
    public static function suggest(array $columns): array
    {
        $mapping = [];
        $used = [];
        foreach (array_keys(self::TITLES) as $field) {
            foreach ($columns as $i => $column) {
                if (isset($used[$i]) || $column['header'] === null) {
                    continue;
                }
                $title = Cells::norm($column['header']);
                foreach (self::TITLES[$field] as $word) {
                    if ($title === $word || str_contains($title, $word)) {
                        $mapping[$field] = $column['name'];
                        $used[$i] = true;

                        continue 3;
                    }
                }
            }
        }
        $mode = isset($mapping['debit'], $mapping['credit']) ? 'debit_credit' : (isset($mapping['amount'], $mapping['type']) ? 'amount_type' : (isset($mapping['amount']) ? 'signed' : null));
        if ($mode === 'debit_credit') {
            unset($mapping['amount'], $mapping['type']);
        } elseif ($mode === 'signed') {
            unset($mapping['debit'], $mapping['credit'], $mapping['type']);
        } elseif ($mode === 'amount_type') {
            unset($mapping['debit'], $mapping['credit']);
        }

        return ['mapping' => $mapping, 'amountMode' => $mode];
    }

    /**
     * The mapping as column positions, checked: every column it names exists, and the columns the amount mode needs
     * are there. `description` may name several columns (their texts are joined).
     *
     * @param  array<string, string|string[]>  $mapping
     * @return array<string, int|int[]> field → 0-based column (description: a list)
     */
    public static function resolve(array $mapping, array $columns, string $amountMode): array
    {
        $byName = [];
        foreach ($columns as $i => $column) {
            $byName[Cells::norm($column['name'])] ??= $i;
            $byName[$column['ref']] ??= $i;
        }
        $find = function (string $field, mixed $name) use ($byName, $columns): int {
            $name = trim((string) $name);
            if (preg_match('/^#(\d+)$/', $name, $m)) {
                $index = (int) $m[1] - 1;
                if ($index >= 0 && $index < max(count($columns), 1)) {
                    return $index;
                }
            }
            $index = $byName[Cells::norm($name)] ?? null;
            if ($index === null) {
                throw AppError::validation('BANK_IMPORT_MAPPING', "العمود «{$name}» المحدد للحقل «{$field}» غير موجود في الملف — راجع ربط الأعمدة", "Column “{$name}” mapped to “{$field}” is not in the file — check the column mapping",
                    [['path' => "mapping.{$field}", 'message' => 'العمود غير موجود في الملف']]);
            }

            return $index;
        };
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $mapping[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $out[$field] = $field === 'description' && is_array($value)
                ? array_values(array_unique(array_map(fn ($name) => $find($field, $name), array_slice($value, 0, 6))))
                : $find($field, is_array($value) ? ($value[0] ?? '') : $value);
        }
        $need = ['date' => true] + match ($amountMode) {
            'debit_credit' => ['debit' => true, 'credit' => true], 'amount_type' => ['amount' => true, 'type' => true], default => ['amount' => true],
        };
        foreach (array_keys($need) as $field) {
            if (! isset($out[$field])) {
                throw AppError::validation('BANK_IMPORT_MAPPING', "ربط الأعمدة ناقص: حدّد عمود «{$field}»", "The column mapping is incomplete: choose the “{$field}” column", [['path' => "mapping.{$field}", 'message' => 'هذا العمود إلزامي']]);
            }
        }

        return $out;
    }

    /**
     * Every data row of the file, read. A row with nothing in it is not a row. Each record names its row in the file
     * (1-based, as a person counts) and either its values or the code of the reason it cannot be a statement line.
     *
     * @param  array<string, int|int[]>  $map  from resolve()
     * @param  array{dateOrder:string, decimal:string, amountMode:string, invert:bool}  $options
     * @return array<int, array{row:int, code:?string, date:?string, valueDate:?string, cents:?int, description:?string, reference:?string, partnerName:?string, partnerAccount:?string, type:?string, balance:?int}>
     */
    public static function records(array $rows, int $headerRow, array $map, array $options): array
    {
        $cell = fn (array $row, string $field): ?string => isset($map[$field]) && is_int($map[$field]) ? ($row[$map[$field]] ?? null) : null;
        $out = [];
        foreach ($rows as $i => $row) {
            if ($i < $headerRow || ! array_filter($row, fn ($c) => $c !== null)) {
                continue;
            }
            if (count($out) >= self::MAX_LINES) {
                throw AppError::validation('BANK_IMPORT_TOO_MANY', 'الملف يحوي أكثر من '.self::MAX_LINES.' سطر — قسّمه على كشفين', 'The file holds more than '.self::MAX_LINES.' lines — split it into two statements');
            }
            $record = ['row' => $i + 1, 'code' => null, 'date' => null, 'valueDate' => null, 'cents' => null, 'description' => null, 'reference' => null, 'partnerName' => null, 'partnerAccount' => null, 'type' => null, 'balance' => null];
            $texts = [];
            foreach ((array) ($map['description'] ?? []) as $column) {
                if (($row[$column] ?? null) !== null) {
                    $texts[] = $row[$column];
                }
            }
            $record['description'] = $texts ? mb_substr(implode(' · ', $texts), 0, 500) : null;
            $record['reference'] = self::cut($cell($row, 'reference'), 120);
            $record['partnerName'] = self::cut($cell($row, 'partnerName'), 190);
            $record['partnerAccount'] = self::cut($cell($row, 'partnerAccount'), 64);
            $record['type'] = self::cut($cell($row, 'type'), 60);

            $rawDate = $cell($row, 'date');
            [$cents, $amountCode, $hasAmount] = self::amountOf($row, $map, $options);
            if ($rawDate === null && ! $hasAmount) {
                $record['code'] = 'ROW_NOT_A_LINE';
                $out[] = $record;

                continue;
            }
            $record['date'] = Cells::date($rawDate, $options['dateOrder']);
            $record['valueDate'] = Cells::date($cell($row, 'valueDate'), $options['dateOrder']);
            $record['cents'] = $cents;
            $balance = Cells::amount($cell($row, 'balance'), $options['decimal']);
            $record['balance'] = is_int($balance) ? $balance : null;
            $record['code'] = match (true) {
                $rawDate === null => 'ROW_NO_DATE',
                $record['date'] === null => 'ROW_BAD_DATE',
                $amountCode !== null => $amountCode,
                default => null,
            };
            $out[] = $record;
        }

        return $out;
    }

    /** @return array{0:?int, 1:?string, 2:bool} the signed amount in hundredths, the reason it cannot be read, whether the row holds any amount cell */
    private static function amountOf(array $row, array $map, array $options): array
    {
        $read = fn (string $field) => isset($map[$field]) ? Cells::amount($row[$map[$field]] ?? null, $options['decimal']) : null;
        if ($options['amountMode'] === 'debit_credit') {
            $debit = $read('debit');
            $credit = $read('credit');
            $has = $debit !== null || $credit !== null;
            if ($debit === false || $credit === false) {
                return [null, 'ROW_BAD_AMOUNT', true];
            }
            $debit = abs((int) $debit);
            $credit = abs((int) $credit);
            if ($debit !== 0 && $credit !== 0) {
                return [null, 'ROW_BOTH_SIDES', true];
            }
            if ($debit === 0 && $credit === 0) {
                return [null, $has ? 'ROW_ZERO_AMOUNT' : 'ROW_NO_AMOUNT', $has];
            }

            return [$credit !== 0 ? $credit : -$debit, null, true];
        }
        $amount = $read('amount');
        if ($amount === null) {
            return [null, 'ROW_NO_AMOUNT', false];
        }
        if ($amount === false) {
            return [null, 'ROW_BAD_AMOUNT', true];
        }
        if ($amount === 0) {
            return [null, 'ROW_ZERO_AMOUNT', true];
        }
        if ($options['amountMode'] === 'amount_type') {
            $mark = Cells::norm(isset($map['type']) ? ($row[$map['type']] ?? null) : null);
            $side = in_array($mark, self::DEBIT_WORDS, true) ? -1 : (in_array($mark, self::CREDIT_WORDS, true) ? 1 : 0);
            if ($side === 0) {
                foreach (self::DEBIT_WORDS as $word) {
                    $side = $side === 0 && mb_strlen($word) > 2 && str_contains($mark, $word) ? -1 : $side;
                }
                foreach (self::CREDIT_WORDS as $word) {
                    $side = $side === 0 && mb_strlen($word) > 2 && str_contains($mark, $word) ? 1 : $side;
                }
            }
            if ($side === 0) {
                return [null, 'ROW_NO_SIDE', true];
            }

            return [$side * abs($amount), null, true];
        }

        return [$options['invert'] ? -$amount : $amount, null, true];
    }

    /** The reasons a row is refused, for a person. @return array{ar:string, en:string} */
    public static function reason(string $code): array
    {
        return match ($code) {
            'ROW_NOT_A_LINE' => ['ar' => 'ليس سطر حركة: بلا تاريخ ولا مبلغ (عنوان، إجمالي أو تتمة نص)', 'en' => 'Not a transaction row: no date and no amount (a heading, a total or wrapped text)'],
            'ROW_NO_DATE' => ['ar' => 'بلا تاريخ', 'en' => 'No date'],
            'ROW_BAD_DATE' => ['ar' => 'التاريخ لا يُقرأ بالترتيب المحدد (يوم / شهر / سنة)', 'en' => 'The date cannot be read in the chosen order'],
            'ROW_NO_AMOUNT' => ['ar' => 'بلا مبلغ', 'en' => 'No amount'],
            'ROW_ZERO_AMOUNT' => ['ar' => 'المبلغ صفر', 'en' => 'The amount is zero'],
            'ROW_BAD_AMOUNT' => ['ar' => 'المبلغ لا يُقرأ رقمًا بخانتين عشريتين', 'en' => 'The amount is not a number with at most two decimals'],
            'ROW_BOTH_SIDES' => ['ar' => 'السطر مدين ودائن معًا', 'en' => 'The row has both a debit and a credit'],
            'ROW_NO_SIDE' => ['ar' => 'لا يُعرف إن كان المبلغ مدينًا أم دائنًا (عمود النوع)', 'en' => 'The debit / credit mark of the amount is not recognised'],
            'ROW_DATE_OUTSIDE' => ['ar' => 'التاريخ خارج فترة الكشف', 'en' => 'The date is outside the statement period'],
            'ROW_DUPLICATE' => ['ar' => 'السطر مستورد من قبل', 'en' => 'The line was imported before'],
            default => ['ar' => 'سطر غير صالح', 'en' => 'Invalid row'],
        };
    }

    private static function cut(?string $text, int $max): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === null || $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** What a line is recognised by when the same line arrives again (see ImportService::fingerprint). */
    public static function identity(string $date, int $cents, ?string $reference, ?string $description): string
    {
        return implode('|', [$date, $cents, Mask::key($reference) ?? '', Cells::norm($description)]);
    }
}
