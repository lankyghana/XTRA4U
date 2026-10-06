<?php

namespace App\Services\VendorContactExport;

use RuntimeException;
use ZipArchive;

/**
 * Minimal single-sheet XLSX writer. Rows are appended to a temp file as they arrive, so memory stays flat
 * regardless of row count; the finished package is zipped from disk and streamed out. Every cell is an
 * inline string (or integer), so text such as "=1+1" is stored as text and never evaluated by Excel.
 */
final class SimpleXlsxWriter
{
    /** @var resource */
    private $sheet;

    private string $sheetPath;

    private int $row = 0;

    public function __construct(private readonly string $sheetName = 'Sheet1')
    {
        $this->sheetPath = (string) tempnam(sys_get_temp_dir(), 'x4u');
        $handle = fopen($this->sheetPath, 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to create temporary export file.');
        }
        $this->sheet = $handle;
        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
    }

    /** @param array<int, string|int|null> $cells */
    public function addRow(array $cells, bool $bold = false): void
    {
        $this->row++;
        $xml = '<row r="'.$this->row.'">';
        $col = 0;
        foreach ($cells as $value) {
            $ref = self::columnName($col++).$this->row;
            $style = $bold ? ' s="1"' : '';
            if (is_int($value)) {
                $xml .= '<c r="'.$ref.'"'.$style.' t="n"><v>'.$value.'</v></c>';
            } else {
                $xml .= '<c r="'.$ref.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'
                    .self::escape((string) $value).'</t></is></c>';
            }
        }
        fwrite($this->sheet, $xml.'</row>');
    }

    /** Writes the finished workbook to $out (a stream) and removes every temp file. */
    public function finish($out): void
    {
        fwrite($this->sheet, '</sheetData></worksheet>');
        fclose($this->sheet);

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'x4u');
        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to build XLSX package.');
            }
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRels());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
            $zip->close();

            $in = fopen($zipPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        } finally {
            @unlink($zipPath);
            @unlink($this->sheetPath);
        }
    }

    /** Remove temp data when the export is abandoned before finish(). */
    public function abort(): void
    {
        if (is_resource($this->sheet)) {
            fclose($this->sheet);
        }
        @unlink($this->sheetPath);
    }

    public static function columnName(int $index): string
    {
        $name = '';
        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26).$name;
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        // Drop characters that are illegal in XML 1.0, then escape markup.
        $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            .'</styleSheet>';
    }
}
