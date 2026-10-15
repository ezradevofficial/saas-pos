<?php

namespace Modules\Payroll\Payments;

/**
 * RFC 4180 CSV for payment and return files. Cells that a spreadsheet
 * would run as a formula (=, +, -, @, tab, carriage return) are prefixed
 * with an apostrophe, except plain numbers.
 */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  list<list<string|null>>  $rows
     */
    public static function of(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(self::cell(...), $header), escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private static function cell(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && preg_match('/^[+-]?\d+(\.\d+)?\z/', $value) !== 1 && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
