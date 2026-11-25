<?php

namespace App\Planning\Support;

use RuntimeException;
use ZipArchive;

/**
 * The smallest honest Excel writer: an Office Open XML workbook (.xlsx) with one sheet per table, a bold frozen header
 * row, numbers as numbers and text as inline strings (UTF-8, Arabic included). Rows are written to a temporary file as
 * they come, so a run of thousands of items never sits in memory twice.
 */
final class XlsxWriter
{
    /** @var array<int, array{name:string, file:string, rows:int, cols:int}> */
    private array $sheets = [];

    /** @var resource|null */
    private $current = null;

    private int $row = 0;

    public function sheet(string $name, array $header): void
    {
        $this->close();
        $file = tempnam(sys_get_temp_dir(), 'pxl');
        $this->current = fopen($file, 'wb');
        $this->sheets[] = ['name' => mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $name), 0, 31), 'file' => $file, 'rows' => 0, 'cols' => count($header)];
        $this->row = 0;
        $this->write($header, true);
    }

    public function row(array $cells): void
    {
        $this->write($cells, false);
    }

    private function write(array $cells, bool $bold): void
    {
        $this->row++;
        $xml = '<row r="'.$this->row.'">';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::col($i).$this->row;
            $style = $bold ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) {
                $xml .= '<c r="'.$ref.'"'.$style.'><v>'.(is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v).'</v></c>';
            } elseif (is_bool($v)) {
                $xml .= '<c r="'.$ref.'"'.$style.' t="b"><v>'.($v ? 1 : 0).'</v></c>';
            } elseif ($v !== null && $v !== '') {
                $text = htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
            }
        }
        fwrite($this->current, $xml.'</row>');
        $this->sheets[count($this->sheets) - 1]['rows'] = $this->row;
    }

    private function close(): void
    {
        if ($this->current) {
            fclose($this->current);
            $this->current = null;
        }
    }

    /** Writes the workbook and returns the path of the file (the caller sends it and deletes it). */
    public function save(): string
    {
        $this->close();
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension of PHP is needed to write Excel files');
        }
        $path = tempnam(sys_get_temp_dir(), 'pxw');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $n = count($this->sheets);
        $types = '';
        $rels = '';
        $list = '';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet'.$k.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="rId'.$k.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$k.'.xml"/>';
            $list .= '<sheet name="'.htmlspecialchars($s['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8').'" sheetId="'.$k.'" r:id="rId'.$k.'"/>';
            $body = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>'
                .file_get_contents($s['file']).'</sheetData></worksheet>';
            $zip->addFromString("xl/worksheets/sheet{$k}.xml", $body);
            @unlink($s['file']);
        }
        $rels .= '<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$types.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$list.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
        $zip->close();
        $this->sheets = [];

        return $path;
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }
}
