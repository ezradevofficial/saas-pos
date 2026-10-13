<?php

namespace App\Core\Imports;

use App\Core\Exports\SpreadsheetCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * IMP-02, IMP-03: the error file of a dry run and the result file of an
 * import: the file's own columns, as uploaded, plus a result column
 * (result files) and an errors column. Written as XLSX, streaming. Every
 * cell is text and safe from formula injection (a cell starting with
 * = + - @ gets a leading quote, SpreadsheetCell).
 */
class ImportReport
{
    private Writer $writer;

    private string $path;

    private int $rows = 0;

    /** @param list<string> $headers */
    public function __construct(array $headers, private readonly bool $withResult)
    {
        $this->path = tempnam(sys_get_temp_dir(), 'import-report-');
        $this->writer = new Writer;
        $this->writer->openToFile($this->path);
        $sheet = $this->writer->getCurrentSheet();
        $sheet->setName(SpreadsheetCell::sheetName(__('imports.report.sheet')));
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $titles = [...$headers, __('imports.report.row')];

        if ($withResult) {
            $titles[] = __('imports.report.result');
        }

        $titles[] = __('imports.report.errors');
        $bold = new Style(fontBold: true);
        $this->writer->addRow(new Row(array_map(fn (string $title) => new StringCell(SpreadsheetCell::safe($title), $bold), $titles)));
    }

    /**
     * @param  list<mixed>  $cells  the row as uploaded
     * @param  int  $width  how many columns the file has
     */
    public function add(array $cells, int $width, int $number, ?string $result, string $errors): void
    {
        $values = [];

        for ($i = 0; $i < $width; $i++) {
            $values[] = mb_substr(ImportCells::display($cells[$i] ?? null), 0, ImportCells::MAX_CELL);
        }

        $values[] = (string) $number;

        if ($this->withResult) {
            $values[] = (string) $result;
        }

        $values[] = $errors;
        $this->writer->addRow(new Row(array_map(fn (string $value) => new StringCell(SpreadsheetCell::safe($value)), $values)));
        $this->rows++;
    }

    public function rows(): int
    {
        return $this->rows;
    }

    /** Close the file; the local path to store. */
    public function finish(): string
    {
        $this->writer->close();

        return $this->path;
    }

    public function discard(): void
    {
        try {
            $this->writer->close();
        } finally {
            @unlink($this->path);
        }
    }
}
