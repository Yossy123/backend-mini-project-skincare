<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;

/**
 * Builds a small, dependency-free .xlsx workbook: typed cells, a few ready-made styles,
 * column widths, a frozen header row and an autofilter.
 *
 * Cells are scalars (written with the sheet's default look) or arrays:
 * `['value' => mixed, 'style' => int, 'formula' => ?string]`.
 */
class XlsxWorkbook
{
    public const STYLE_DEFAULT = 0;

    public const STYLE_HEADER = 1;

    public const STYLE_TEXT = 2;

    public const STYLE_WRAP = 3;

    public const STYLE_RUPIAH = 4;

    public const STYLE_DATETIME = 5;

    public const STYLE_INTEGER = 6;

    public const STYLE_TITLE = 7;

    public const STYLE_NOTE = 8;

    public const STYLE_TOTAL_LABEL = 9;

    public const STYLE_TOTAL_RUPIAH = 10;

    public const STYLE_TOTAL_INTEGER = 11;

    public const STYLE_CENTER = 12;

    /** @var array<int, XlsxSheet> */
    private array $sheets = [];

    public function addSheet(string $name): XlsxSheet
    {
        $sheet = new XlsxSheet($this->sanitizeSheetName($name));
        $this->sheets[] = $sheet;

        return $sheet;
    }

    /** Excel serial date for a moment, read as Jakarta wall-clock time. */
    public static function serialDate(CarbonInterface $moment): float
    {
        $wallClock = $moment->copy()->setTimezone('Asia/Jakarta')->shiftTimezone('UTC');

        return $wallClock->getTimestamp() / 86400 + 25569;
    }

    public function toBinary(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->stylesXml(),
        ];

        foreach ($this->sheets as $index => $sheet) {
            $files['xl/worksheets/sheet'.($index + 1).'.xml'] = $sheet->toXml();
        }

        return $this->zip($files);
    }

    private function sanitizeSheetName(string $name): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $name) ?? '');

        return mb_substr($clean === '' ? 'Sheet' : $clean, 0, 31);
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $_) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets
            .'</Types>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.XlsxSheet::escape($sheet->name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<bookViews><workbookView activeTab="0"/></bookViews>'
            .'<sheets>'.$sheets.'</sheets>'
            .'<calcPr fullCalcOnLoad="1"/>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        $relations = '';
        foreach ($this->sheets as $index => $_) {
            $relations .= '<Relationship Id="rId'.($index + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }
        $relations .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relations.'</Relationships>';
    }

    private function stylesXml(): string
    {
        $border = '<border><left style="thin"><color rgb="FFD1D5DB"/></left><right style="thin"><color rgb="FFD1D5DB"/></right>'
            .'<top style="thin"><color rgb="FFD1D5DB"/></top><bottom style="thin"><color rgb="FFD1D5DB"/></bottom><diagonal/></border>';

        $xf = static fn (int $numFmt, int $font, int $fill, int $border, string $alignment = ''): string => '<xf numFmtId="'.$numFmt.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0"'
            .($numFmt ? ' applyNumberFormat="1"' : '').($font ? ' applyFont="1"' : '').($fill ? ' applyFill="1"' : '').($border ? ' applyBorder="1"' : '')
            .($alignment !== '' ? ' applyAlignment="1">'.$alignment.'</xf>' : '/>');

        $top = '<alignment vertical="top"/>';
        $topWrap = '<alignment vertical="top" wrapText="1"/>';

        $cellXfs = [
            $xf(0, 0, 0, 0),                                                                      // 0 default
            $xf(0, 1, 2, 1, '<alignment horizontal="center" vertical="center" wrapText="1"/>'),   // 1 header
            $xf(0, 0, 0, 1, $top),                                                                // 2 text
            $xf(0, 0, 0, 1, $topWrap),                                                            // 3 wrapped text
            $xf(164, 0, 0, 1, $top),                                                              // 4 rupiah
            $xf(165, 0, 0, 1, '<alignment horizontal="left" vertical="top"/>'),                   // 5 date and time
            $xf(166, 0, 0, 1, $top),                                                              // 6 integer
            $xf(0, 3, 0, 0),                                                                      // 7 title
            $xf(0, 4, 0, 0, '<alignment vertical="top" wrapText="1"/>'),                          // 8 note
            $xf(0, 2, 3, 1, $top),                                                                // 9 total label
            $xf(164, 2, 3, 1, $top),                                                              // 10 total rupiah
            $xf(166, 2, 3, 1, $top),                                                              // 11 total integer
            $xf(0, 0, 0, 1, '<alignment horizontal="center" vertical="top"/>'),                   // 12 centred
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="&quot;Rp&quot;\ #,##0"/>'
            .'<numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm"/>'
            .'<numFmt numFmtId="166" formatCode="#,##0"/>'
            .'</numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="16"/><name val="Calibri"/></font>'
            .'<font><i/><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1F2937"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.$border.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($cellXfs).'">'.implode('', $cellXfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    /**
     * Pack files into an uncompressed ZIP archive (all an .xlsx needs).
     *
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $now = now('Asia/Jakarta');
        $dosTime = ($now->hour << 11) | ($now->minute << 5) | intdiv($now->second, 2);
        $dosDate = (($now->year - 1980) << 9) | ($now->month << 5) | $now->day;

        $body = '';
        $directory = '';
        $count = 0;

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $offset = strlen($body);

            $body .= pack('VvvvvvVVVvv', 0x04034B50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0).$name.$content;
            $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $count++;
        }

        return $body.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), strlen($body), 0);
    }
}
