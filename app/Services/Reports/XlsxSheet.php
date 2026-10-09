<?php

namespace App\Services\Reports;

use Carbon\CarbonInterface;

/** One worksheet of an {@see XlsxWorkbook}. */
class XlsxSheet
{
    /** @var array<int, string> */
    private array $rows = [];

    /** @var array<int, float> */
    private array $columnWidths = [];

    private int $frozenRows = 0;

    private ?string $autoFilter = null;

    private bool $showGridLines = true;

    private bool $landscapeFit = false;

    /** @var array<int, string> */
    private array $merges = [];

    private int $rowNumber = 0;

    public function __construct(public readonly string $name) {}

    /** @param array<int, float|int> $widths Character widths, first column first. */
    public function columnWidths(array $widths): static
    {
        $this->columnWidths = array_values($widths);

        return $this;
    }

    public function freezeRows(int $rows): static
    {
        $this->frozenRows = $rows;

        return $this;
    }

    /** Print on landscape pages scaled to one page wide. */
    public function landscapeFit(): static
    {
        $this->landscapeFit = true;

        return $this;
    }

    /** Merge the cells of a range such as `A12:C12`. */
    public function merge(string $range): static
    {
        $this->merges[] = $range;

        return $this;
    }

    public function hideGridLines(): static
    {
        $this->showGridLines = false;

        return $this;
    }

    /** Add a filter dropdown to the header row, covering columns A to the given count and the rows written so far. */
    public function autoFilterHeader(int $columns, int $headerRow = 1): static
    {
        $this->autoFilter = 'A'.$headerRow.':'.self::columnLetter($columns).max($headerRow, $this->rowNumber);

        return $this;
    }

    public function currentRow(): int
    {
        return $this->rowNumber;
    }

    /**
     * Append a row. Cells are scalars or `['value' => mixed, 'style' => int, 'formula' => ?string]`.
     *
     * @param  array<int, mixed>  $cells
     */
    public function row(array $cells, ?float $height = null): static
    {
        $this->rowNumber++;
        $xml = '';

        foreach (array_values($cells) as $index => $cell) {
            $reference = self::columnLetter($index + 1).$this->rowNumber;
            $spec = is_array($cell) && array_key_exists('value', $cell) ? $cell : ['value' => $cell];
            $xml .= $this->cell($reference, $spec['value'], (int) ($spec['style'] ?? XlsxWorkbook::STYLE_DEFAULT), $spec['formula'] ?? null);
        }

        $this->rows[] = '<row r="'.$this->rowNumber.'"'.($height !== null ? ' ht="'.$height.'" customHeight="1"' : '').'>'.$xml.'</row>';

        return $this;
    }

    public function toXml(): string
    {
        $pane = $this->frozenRows > 0
            ? '<pane ySplit="'.$this->frozenRows.'" topLeftCell="A'.($this->frozenRows + 1).'" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft"/>'
            : '';

        $columns = '';
        foreach ($this->columnWidths as $index => $width) {
            $columns .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($this->landscapeFit ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '')
            .'<sheetViews><sheetView workbookViewId="0"'.($this->showGridLines ? '' : ' showGridLines="0"').'>'.$pane.'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .($columns !== '' ? '<cols>'.$columns.'</cols>' : '')
            .'<sheetData>'.implode('', $this->rows).'</sheetData>'
            .($this->autoFilter !== null ? '<autoFilter ref="'.$this->autoFilter.'"/>' : '')
            .($this->merges !== [] ? '<mergeCells count="'.count($this->merges).'">'.implode('', array_map(fn (string $range): string => '<mergeCell ref="'.$range.'"/>', $this->merges)).'</mergeCells>' : '')
            .($this->landscapeFit ? '<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>' : '')
            .'</worksheet>';
    }

    public static function columnLetter(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $number = intdiv($number - 1, 26);
        }

        return $letters;
    }

    public static function escape(string $text): string
    {
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function cell(string $reference, mixed $value, int $style, ?string $formula): string
    {
        $styleAttribute = $style !== 0 ? ' s="'.$style.'"' : '';

        if ($formula !== null) {
            return '<c r="'.$reference.'"'.$styleAttribute.'><f>'.self::escape($formula).'</f><v>'.(is_numeric($value) ? $value + 0 : 0).'</v></c>';
        }

        if ($value instanceof CarbonInterface) {
            return '<c r="'.$reference.'"'.$styleAttribute.'><v>'.XlsxWorkbook::serialDate($value).'</v></c>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$reference.'"'.$styleAttribute.'><v>'.$value.'</v></c>';
        }

        if ($value === null || $value === '') {
            return '<c r="'.$reference.'"'.$styleAttribute.'/>';
        }

        return '<c r="'.$reference.'"'.$styleAttribute.' t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
    }
}
