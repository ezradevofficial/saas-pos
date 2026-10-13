<?php

namespace App\Core\Imports;

use Generator;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * IMP-01, IMP-03: reads an uploaded file, streaming (OpenSpout), so a
 * 50,000-row file never sits in memory.
 *
 * - CSV in UTF-8, with or without a BOM; the separator (comma,
 *   semicolon as French Excel writes, or tab) is found from the first line.
 * - XLSX: the first sheet; dates come as dates.
 * - The first non-empty row holds the headers. Empty rows are skipped;
 *   every row keeps its number in the sheet, so errors name the row the
 *   person sees.
 */
class ImportFile
{
    public function __construct(
        private readonly string $path,
        private readonly string $format,
    ) {}

    /** @return list<string> the header row's texts, trimmed */
    public function headers(): array
    {
        foreach ($this->read() as $cells) {
            return array_map(fn (mixed $cell) => trim(ImportCells::display($cell)), $cells);
        }

        return [];
    }

    /**
     * The data rows after the header row: sheet row number => cells.
     *
     * @return Generator<int, list<mixed>>
     */
    public function rows(): Generator
    {
        $header = true;

        foreach ($this->read() as $number => $cells) {
            if ($header) {
                $header = false;

                continue;
            }

            yield $number => $cells;
        }
    }

    /** Data rows (empty ones not counted), stopping once past $limit. */
    public function count(int $limit): int
    {
        $count = 0;

        foreach ($this->rows() as $cells) {
            if (++$count > $limit) {
                break;
            }
        }

        return $count;
    }

    /** @return Generator<int, list<mixed>> non-empty rows, header included, by sheet row number */
    private function read(): Generator
    {
        $reader = $this->reader();
        $reader->open($this->path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = $row->toArray();

                    if (array_filter($cells, fn (mixed $cell) => $cell !== null && trim(ImportCells::display($cell)) !== '') === []) {
                        continue;
                    }

                    yield (int) $number => $cells;
                }

                // The first sheet only.
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function reader(): ReaderInterface
    {
        if ($this->format === 'xlsx') {
            return new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        }

        return new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $this->delimiter()));
    }

    /** The separator used most on the first line, outside quotes. */
    private function delimiter(): string
    {
        $handle = fopen($this->path, 'r');
        $line = $handle === false ? '' : (string) fgets($handle, 65536);

        if ($handle !== false) {
            fclose($handle);
        }

        $line = (string) preg_replace('/"[^"]*"/', '', $line);
        $counts = ['NUL' => 0, ',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $best = array_key_first($counts);

        return $best === 'NUL' || $counts[$best] === 0 ? ',' : $best;
    }
}
