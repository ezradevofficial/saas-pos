<?php

namespace App\Core\Imports;

use App\Core\Http\ApiException;
use ZipArchive;

/**
 * IMP-01, IMP-03: checks an uploaded file before anything parses it, so a
 * hostile or odd file is refused with a 422, never a 500 or a stuck worker.
 *
 * - CSV must be text in UTF-8. Windows-1252 and ISO-8859-1 (what Excel
 *   writes on many machines) are converted to UTF-8 in place; UTF-16 and
 *   anything else that is not one of these is refused (`file_encoding`).
 * - XLSX is a zip: its declared uncompressed size is capped (a zip bomb
 *   expands before any row is read) and so is the shared strings count.
 * - Rows are counted physically, empty ones included, up to twice the row
 *   limit (a sheet with a row at 1,048,576 is a million empty rows to read).
 * - A cell longer than MAX_CELL characters is a row error (ImportCells).
 */
class ImportUploads
{
    /** Largest total uncompressed size of an XLSX's parts. */
    public const MAX_XLSX_BYTES = 200 * 1024 * 1024;

    /** Most shared strings an XLSX may declare. */
    public const MAX_SHARED_STRINGS = 1_000_000;

    /** Rows read, empty ones included, before a file is refused. */
    public const MAX_PHYSICAL_ROWS = 2 * ImportLimits::MAX_ROWS;

    /** Bytes in 0x80-0x9F that Windows-1252 leaves undefined: not text in either encoding. */
    private const UNDEFINED_1252 = '/[\x81\x8D\x8F\x90\x9D]/';

    /** Refuse or fix the file at $path before it is parsed; throws ApiException (422). */
    public function inspect(string $path, string $format): void
    {
        $format === 'xlsx' ? $this->inspectXlsx($path) : $this->toUtf8($path);
    }

    /**
     * The data rows (not empty) and whether the file is within the physical
     * row limit.
     *
     * @return int data rows
     */
    public function count(ImportFile $file): int
    {
        [$rows, $physical] = $file->count(ImportLimits::MAX_ROWS, self::MAX_PHYSICAL_ROWS);

        if ($rows > ImportLimits::MAX_ROWS || $physical > self::MAX_PHYSICAL_ROWS) {
            $message = __('imports.errors.too_many_rows', ['rows' => number_format(ImportLimits::MAX_ROWS)]);

            throw new ApiException(422, 'too_many_rows', $message, ['file' => [$message]]);
        }

        return $rows;
    }

    private function toUtf8(string $path): void
    {
        $bytes = (string) file_get_contents($path);

        // UTF-16 (BOM or NUL bytes) is not read as CSV here.
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF") || str_contains($bytes, "\0")) {
            $this->refuseEncoding();
        }

        if (mb_check_encoding($bytes, 'UTF-8')) {
            return;
        }

        if (preg_match(self::UNDEFINED_1252, $bytes) === 1) {
            $this->refuseEncoding();
        }

        // Windows-1252 is ISO-8859-1 with printable characters in 0x80-0x9F,
        // so one conversion serves both.
        file_put_contents($path, mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252'));
    }

    private function inspectXlsx(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->refuse('file_unreadable');
        }

        try {
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);

                if ($total > self::MAX_XLSX_BYTES) {
                    $this->refuse('file_too_large');
                }
            }

            $stream = $zip->getStream('xl/sharedStrings.xml');

            if ($stream !== false) {
                $head = (string) fread($stream, 4096);
                fclose($stream);

                if (preg_match('/\b(?:count|uniqueCount)="(\d+)"/', $head, $match) === 1 && (int) $match[1] > self::MAX_SHARED_STRINGS) {
                    $this->refuse('file_too_large');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function refuseEncoding(): never
    {
        $this->refuse('file_encoding');
    }

    private function refuse(string $code): never
    {
        $message = __("imports.errors.{$code}");

        throw new ApiException(422, $code, $message, ['file' => [$message]]);
    }
}
