<?php

namespace Tests\Feature\Banking;

use App\Banking\Models\ImportProfile;
use App\Banking\Models\ImportRun;
use App\Banking\Models\StatementLine;
use App\Models\AuditLog;
use Carbon\Carbon;
use Tests\ApiTestCase;
use ZipArchive;

/**
 * Importing a bank's file into a statement: the file read as the bank writes it (a title line above the column
 * titles, Arabic titles, a debit and a credit column, thousands separators, a running balance), the mapping proposed
 * and kept per bank account, every row reported (taken / duplicate / refused with its reason), the same file — or an
 * overlapping export — never doubling a line (fingerprint), a dry run that writes nothing, an Excel workbook (dates as
 * date cells), and the files that are refused.
 */
class BankImportTest extends ApiTestCase
{
    use BankingFixtures;

    private static array $s = [];

    /** d/m/Y as Saudi banks print it. */
    private function dmy(int $offset): string
    {
        return Carbon::parse($this->day($offset))->format('d/m/Y');
    }

    private function bankCsv(): string
    {
        $rows = [
            'كشف حساب — البنك الأهلي',
            'التاريخ,البيان,المرجع,مدين,دائن,الرصيد',
            "{$this->dmy(-25)},إيداع نقدي,DEP-1,,\"1,500.00\",\"2,500.00\"",
            "{$this->dmy(-20)},شيك صادر,CHQ-9,250.25,,\"2,249.75\"",
            "{$this->dmy(-20)},شيك صادر,CHQ-9,250.25,,\"1,999.50\"",
            'ليس تاريخًا,سطر تالف,X,10.00,,',
            "{$this->dmy(-40)},حركة من الشهر السابق,OLD-1,,99.00,",
            "{$this->dmy(-10)},رسوم خدمة,FEE-1,15.00,,\"1,984.50\"",
        ];

        return implode("\r\n", $rows)."\r\n";
    }

    private static function file(string $content, string $name = 'statement.csv'): array
    {
        return ['fileName' => $name, 'base64' => base64_encode($content)];
    }

    public function test_01_the_file_is_read_as_the_bank_writes_it_and_the_mapping_is_proposed(): void
    {
        $bank = $this->newBank('BI')['code'];
        self::$s['bank'] = $bank;
        $s = $this->newStatement($bank, $this->day(-30), $this->day(-1), '1000.00', '1984.50');
        self::$s['statement'] = $s['number'];

        $preview = $this->expectOk($this->postAs('finance', '/api/banking/import/preview', self::file($this->bankCsv()) + ['bankAccount' => $bank]));
        $this->assertSame(2, $preview['headerRow'], 'the title line above the column titles is passed over');
        $this->assertSame('csv', $preview['file']['format']);
        $this->assertTrue($preview['mappingComplete']);
        $this->assertSame('debit_credit', $preview['options']['amountMode']);
        $this->assertSame('dmy', $preview['options']['dateOrder']);
        $mapping = $preview['mapping'];
        $this->assertSame(['التاريخ', 'البيان', 'المرجع', 'مدين', 'دائن', 'الرصيد'], [$mapping['date'], $mapping['description'], $mapping['reference'], $mapping['debit'], $mapping['credit'], $mapping['balance']]);
        $check = $preview['check'];
        $this->assertSame([6, 5, 1], [$check['rows'], $check['readable'], $check['refused']]);
        $this->assertSame('ROW_BAD_DATE', $check['refusedBy'][0]['code']);
        $this->assertSame(['1000.00', '1984.50', 0], [$check['opening'], $check['closing'], $check['balanceBreaks']], 'the running balance says what the statement opens and closes with');
        $this->assertSame([$this->day(-40), $this->day(-10)], [$check['dateFrom'], $check['dateTo']]);

        // reading a file is entering statements: gm may not
        $this->expectRejected($this->postAs('gm', '/api/banking/import/preview', self::file($this->bankCsv())), 'FORBIDDEN', [403]);
    }

    public function test_02_the_import_reports_every_row_and_keeps_the_mapping(): void
    {
        $s = self::$s['statement'];
        $res = $this->postAs('finance', "/api/banking/statements/{$s}/import", self::file($this->bankCsv(), 'alahli-oct.csv') + ['saveAs' => 'ملف الأهلي']);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $out = (array) $res->json();
        $this->assertSame([6, 4, 0, 2], [$out['total'], $out['taken'], $out['duplicates'], $out['refused']]);
        $byRow = collect($out['lines'])->keyBy('row');
        $this->assertSame(['taken', 1], [$byRow[3]['status'], $byRow[3]['lineNo']]);
        $this->assertSame(['taken', 'taken'], [$byRow[4]['status'], $byRow[5]['status']], 'two equal movements of one day are two lines');
        $this->assertSame(['refused', 'ROW_BAD_DATE'], [$byRow[6]['status'], $byRow[6]['code']]);
        $this->assertSame(['refused', 'ROW_DATE_OUTSIDE'], [$byRow[7]['status'], $byRow[7]['code']]);
        $this->assertSame('-15.00', $byRow[8]['amount']);
        $this->assertSame('984.50', $out['takenTotal']);
        $this->assertTrue($out['statement']['totals']['balanced'], 'opening 1,000.00 + 984.50 = closing 1,984.50');
        $this->assertSame('ملف الأهلي', $out['profile']['name']);
        $this->assertTrue($out['profile']['isDefault']);

        // what is kept of the import: counts, and row numbers with reasons — never a figure or a text of the file
        $run = ImportRun::findOrFail($out['import']['id']);
        $this->assertSame([6, 4, 0, 2, 'alahli-oct.csv', 'csv'], [$run->rows_total, $run->rows_taken, $run->rows_duplicate, $run->rows_refused, $run->file_name, $run->format]);
        $stored = json_encode($run->report, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('250.25', $stored);
        $this->assertStringNotContainsString('سطر تالف', $stored);
        $this->assertEquals([['row' => 6, 'status' => 'refused', 'code' => 'ROW_BAD_DATE'], ['row' => 7, 'status' => 'refused', 'code' => 'ROW_DATE_OUTSIDE']], $run->report);
        $this->assertSame(1, AuditLog::where('action', 'BANK.IMPORT')->where('entity_number', $s)->count());
        $this->assertSame(4, StatementLine::where('import_id', $run->id)->count());
    }

    public function test_03_the_same_file_again_or_an_overlapping_export_never_doubles_a_line(): void
    {
        $s = self::$s['statement'];
        $lines = StatementLine::where('statement_id', $this->statement($s)['id'])->count();
        // no mapping sent: the one kept for this bank account reads the file
        $again = $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file($this->bankCsv())));
        $this->assertSame([0, 4, 2], [$again['taken'], $again['duplicates'], $again['refused']]);
        $dup = collect($again['lines'])->firstWhere('row', 4);
        $this->assertSame(['duplicate', 'ROW_DUPLICATE', $s, 2], [$dup['status'], $dup['code'], $dup['existing']['statement'], $dup['existing']['lineNo']]);
        $this->assertSame($lines, StatementLine::where('statement_id', $this->statement($s)['id'])->count());

        // the next export overlaps the last day of this one: a dry run says what an import would do, and writes nothing
        $next = implode("\r\n", ['التاريخ,البيان,المرجع,مدين,دائن,الرصيد', "{$this->dmy(-10)},رسوم خدمة,FEE-1,15.00,,\"1,984.50\"", "{$this->dmy(-5)},إيداع,DEP-7,,100.00,\"2,084.50\""]);
        $runs = ImportRun::count();
        $dry = $this->postAs('finance', "/api/banking/statements/{$s}/import", self::file($next) + ['dryRun' => true]);
        $this->assertSame(200, $dry->getStatusCode(), $dry->getContent());
        $this->assertSame([true, 1, 1, null], [$dry->json('dryRun'), $dry->json('taken'), $dry->json('duplicates'), $dry->json('import')]);
        $this->assertSame([$lines, $runs], [StatementLine::where('statement_id', $this->statement($s)['id'])->count(), ImportRun::count()]);
    }

    public function test_04_saved_mappings_are_listed_changed_and_checked(): void
    {
        $bank = self::$s['bank'];
        $profiles = $this->expectOk($this->getAs('gm', "/api/banking/profiles?bank={$bank}"))['items'];
        $mine = collect($profiles)->firstWhere('name', 'ملف الأهلي');
        $this->assertSame('debit_credit', $mine['options']['amountMode']);
        $this->expectRejected($this->postAs('finance', '/api/banking/profiles', ['name' => 'ملف الأهلي', 'bankAccount' => $bank, 'mapping' => ['date' => 'التاريخ', 'amount' => 'المبلغ']]), 'BANK_PROFILE_EXISTS', [409]);
        $this->expectRejected($this->postAs('finance', '/api/banking/profiles', ['name' => 'ناقص', 'bankAccount' => $bank, 'mapping' => ['date' => 'التاريخ'], 'options' => ['amountMode' => 'signed']]), 'BANK_IMPORT_MAPPING', [400]);
        $second = $this->postAs('finance', '/api/banking/profiles', ['name' => 'ملف بعمود واحد', 'bankAccount' => $bank, 'mapping' => ['date' => 'Date', 'amount' => 'Amount', 'description' => ['Details', 'Narrative']],
            'options' => ['amountMode' => 'signed', 'dateOrder' => 'ymd'], 'isDefault' => true]);
        $this->assertSame(201, $second->getStatusCode(), $second->getContent());
        $this->assertSame(['Details', 'Narrative'], $second->json('mapping.description'));
        $this->assertFalse(ImportProfile::findOrFail($mine['id'])->is_default, 'one default per bank account');
        $renamed = $this->expectOk($this->patchAs('finance', '/api/banking/profiles/'.$second->json('id'), ['name' => 'ملف الإنجليزي', 'active' => false]));
        $this->assertSame(['ملف الإنجليزي', false, false], [$renamed['name'], $renamed['active'], $renamed['isDefault']]);
        $this->assertCount(1, $this->expectOk($this->getAs('finance', "/api/banking/profiles?bank={$bank}"))['items']);
        $this->assertCount(2, $this->expectOk($this->getAs('finance', "/api/banking/profiles?bank={$bank}&all=1"))['items']);
    }

    public function test_05_an_excel_workbook_is_read_with_its_date_cells(): void
    {
        $bank = $this->newBank('BX')['code'];
        $s = $this->newStatement($bank, $this->day(-30), $this->day(-1), '0.00', '1149.25');
        $serial = fn (int $offset) => (int) Carbon::parse('1899-12-30')->diffInDays(Carbon::parse($this->day($offset)));
        $xlsx = self::xlsx([
            ['Transaction Date', 'Description', 'Reference', 'Amount'],
            [['date' => $serial(-28)], 'Incoming transfer', 'TRF-100', 1200],
            [['date' => $serial(-12)], 'POS fee', 'FEE-2', -50.75],
            [$this->day(-3), 'Bad amount', 'X-1', 'abc'],
        ]);
        $res = $this->postAs('finance', "/api/banking/statements/{$s['number']}/import", ['fileName' => 'export.xlsx', 'base64' => base64_encode($xlsx), 'options' => ['amountMode' => 'signed']]);
        $this->assertSame(201, $res->getStatusCode(), $res->getContent());
        $this->assertSame(['xlsx', 2, 1], [$res->json('import.format'), $res->json('taken'), $res->json('refused')]);
        $this->assertSame('ROW_BAD_AMOUNT', collect($res->json('lines'))->firstWhere('row', 4)['code']);
        $lines = $this->expectOk($this->getAs('finance', "/api/banking/lines?statement={$s['number']}"))['items'];
        $this->assertSame([[$this->day(-28), '1200.00'], [$this->day(-12), '-50.75']], array_map(fn (array $l) => [$l['date'], $l['amount']], $lines));
        $this->assertSame([true, true], [$lines[0]['imported'], $this->statement($s['number'])['totals']['balanced']]);
    }

    public function test_06_files_that_cannot_be_statements_are_refused_with_the_way_out(): void
    {
        $s = self::$s['statement'];
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file("%PDF-1.7\n...", 'statement.pdf')), 'BANK_IMPORT_FORMAT', [400]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file("\xD0\xCF\x11\xE0old", 'statement.xls')), 'BANK_IMPORT_FORMAT', [400]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", ['fileName' => 'x.csv', 'base64' => '!!!!']), 'BANK_IMPORT_EMPTY', [400]);
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file("التاريخ,البيان,المرجع,مدين,دائن,الرصيد\r\n")), 'BANK_IMPORT_NO_ROWS', [400]);
        $this->expectRejected($this->postAs('gm', "/api/banking/statements/{$s}/import", self::file($this->bankCsv())), 'FORBIDDEN', [403]);
        // a mapping that names a column the file does not have
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file($this->bankCsv()) + ['mapping' => ['date' => 'Value', 'amount' => 'Nope']]), 'BANK_IMPORT_MAPPING', [400]);
    }

    public function test_07_a_voided_statement_releases_its_lines_for_the_right_import(): void
    {
        $bank = self::$s['bank'];
        $s = self::$s['statement'];
        $this->expectOk($this->postAs('finance', "/api/banking/statements/{$s}/void", ['reason' => 'استورد في كشف الفترة الخطأ']));
        $this->expectRejected($this->postAs('finance', "/api/banking/statements/{$s}/import", self::file($this->bankCsv())), 'BANK_STATEMENT_VOID', [422]);
        $right = $this->newStatement($bank, $this->day(-30), $this->day(-1), '1000.00', '1984.50');
        $out = $this->expectOk($this->postAs('finance', "/api/banking/statements/{$right['number']}/import", self::file($this->bankCsv())));
        $this->assertSame([4, 0], [$out['taken'], $out['duplicates']]);
        $this->assertTrue($out['statement']['totals']['balanced']);
    }

    /**
     * A minimal workbook, written the way Excel writes one: shared strings for text, numbers as numbers, and a date
     * as a serial number in a cell styled with the built-in date format 14.
     *
     * @param  array<int, array<int, mixed>>  $rows  a cell is a string, a number, or ['date' => serial]
     */
    private static function xlsx(array $rows): string
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if (is_array($value)) {
                    $cells .= "<c r=\"{$ref}\" s=\"1\"><v>{$value['date']}</v></c>";
                } elseif (is_int($value) || is_float($value)) {
                    $cells .= "<c r=\"{$ref}\"><v>{$value}</v></c>";
                } else {
                    $strings[] = htmlspecialchars((string) $value, ENT_XML1);
                    $cells .= "<c r=\"{$ref}\" t=\"s\"><v>".(count($strings) - 1).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1)."\">{$cells}</row>";
        }
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><workbook {$ns} xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Statement\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/styles.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><styleSheet {$ns}><cellXfs count=\"2\"><xf numFmtId=\"0\"/><xf numFmtId=\"14\" applyNumberFormat=\"1\"/></cellXfs></styleSheet>",
            'xl/sharedStrings.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><sst {$ns} count=\"".count($strings).'">'.implode('', array_map(fn (string $s) => "<si><t>{$s}</t></si>", $strings)).'</sst>',
            'xl/worksheets/sheet1.xml' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><worksheet {$ns}><sheetData>{$sheetRows}</sheetData></worksheet>",
        ];
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
