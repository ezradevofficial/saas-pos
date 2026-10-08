<?php

namespace App\Core\Exports;

/**
 * Text that is safe in a spreadsheet file.
 */
final class SpreadsheetCell
{
    /**
     * A CSV cell a spreadsheet will not run as a formula: text starting
     * with =, +, -, @, tab or carriage return gets a leading quote (E.164
     * phone numbers excepted).
     */
    public static function safe(?string $value): string
    {
        $value ??= '';

        if ($value !== '' && preg_match('/^\+\d+$/', $value) !== 1 && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    /** A worksheet name: at most 31 characters, none of []:*?/\ . */
    public static function sheetName(string $title): string
    {
        $name = trim(mb_substr(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $title), 0, 31));

        return $name === '' ? 'Sheet1' : $name;
    }
}
