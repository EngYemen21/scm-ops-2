<?php

namespace App\Banking\Support\Sheet;

use App\Support\AppError;
use Throwable;

/**
 * A statement file as the client sends it — `{ fileName, base64 }` (the base64 may be a data URL) — turned into rows of
 * text. What the file is, is told from its bytes, never from its name: an Excel workbook (.xlsx) or delimited text.
 * The old binary Excel format (.xls) is refused with the way out: save it as .xlsx or .csv.
 */
final class SheetFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Validation rules of the file part of a request. */
    public static function rules(): array
    {
        $maxChars = (int) ceil(self::MAX_BYTES / 3) * 4 + 256;

        return ['fileName' => 'required|string|min:1|max:200', 'base64' => "required|string|min:4|max:{$maxChars}"];
    }

    /**
     * @param  array{fileName:string, base64:string}  $file
     * @param  array{sheet?:?string, delimiter?:?string, encoding?:?string}  $options
     * @return array{format:string, fileName:string, hash:string, size:int, rows:array<int, array<int, ?string>>, sheets:string[], sheet:?string, delimiter:?string, encoding:?string}
     */
    public static function read(array $file, array $options = []): array
    {
        $base64 = (string) preg_replace('/\s+/', '', (string) preg_replace('/^data:[^;,]*(;[^;,]*)*;base64,/i', '', trim((string) $file['base64'])));
        $bytes = base64_decode($base64, true);
        if (! is_string($bytes) || $bytes === '') {
            throw AppError::validation('BANK_IMPORT_EMPTY', 'الملف فارغ أو ترميزه غير صالح', 'The file is empty or its encoding is invalid');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw AppError::validation('BANK_IMPORT_TOO_LARGE', 'حجم الملف يتجاوز 5 ميجابايت — قسّم الكشف على ملفين', 'The file exceeds 5 MB — split the statement into two files', ['size' => strlen($bytes), 'max' => self::MAX_BYTES]);
        }
        $name = mb_substr(trim(basename(str_replace('\\', '/', (string) $file['fileName']))), 0, 200);
        $base = ['fileName' => $name === '' ? 'statement' : $name, 'hash' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        if (strncmp($bytes, "\xD0\xCF\x11\xE0", 4) === 0) {
            throw AppError::validation('BANK_IMPORT_FORMAT', 'صيغة Excel القديمة (.xls) غير مدعومة — احفظ الملف بصيغة ‎.xlsx أو ‎.csv ثم أعد رفعه', 'The old Excel format (.xls) is not supported — save the file as .xlsx or .csv and upload it again');
        }
        if (strncmp($bytes, '%PDF-', 5) === 0) {
            throw AppError::validation('BANK_IMPORT_FORMAT', 'هذا ملف PDF — نزّل الكشف من موقع البنك بصيغة Excel أو CSV', 'This is a PDF — download the statement from the bank as Excel or CSV');
        }
        try {
            if (Zip::looksLikeZip($bytes)) {
                $sheet = XlsxReader::read($bytes, isset($options['sheet']) && $options['sheet'] !== '' ? (string) $options['sheet'] : null);

                return $base + ['format' => 'xlsx', 'rows' => $sheet['rows'], 'sheets' => $sheet['sheets'], 'sheet' => $sheet['sheet'], 'delimiter' => null, 'encoding' => null];
            }
            $delimiter = match ($options['delimiter'] ?? null) {
                'tab', "\t" => "\t", ',', ';', '|' => $options['delimiter'], default => null,
            };
            $csv = CsvReader::read($bytes, $delimiter, $options['encoding'] ?? null);

            return $base + ['format' => 'csv', 'rows' => $csv['rows'], 'sheets' => [], 'sheet' => null, 'delimiter' => $csv['delimiter'] === "\t" ? 'tab' : $csv['delimiter'], 'encoding' => $csv['encoding']];
        } catch (AppError $e) {
            throw $e;
        } catch (Throwable $e) {
            // the reason is the reader's own short text ("too many rows", "not an Excel workbook"): nothing of the file's content
            throw AppError::validation('BANK_IMPORT_UNREADABLE', 'تعذّرت قراءة الملف — تأكد أنه كشف بصيغة ‎.xlsx أو ‎.csv وغير محمي بكلمة مرور', 'The file could not be read — make sure it is an .xlsx or .csv statement and is not password protected', ['reason' => $e->getMessage()]);
        }
    }
}
