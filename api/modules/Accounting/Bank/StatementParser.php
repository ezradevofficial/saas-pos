<?php

namespace Modules\Accounting\Bank;

use App\Core\Currency\CurrencyDecimals;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerError;
use Throwable;

/**
 * ACC-13: statement files into lines `{date, description, reference,
 * amount_minor, balance_minor}` (money in positive, out negative).
 *
 * - `csv`: the person maps the file's columns: date, description,
 *   reference, and either one signed amount or money in and money out;
 *   the date format is one of DATE_FORMATS. Amounts may carry thousands
 *   separators, a leading minus or parentheses for negatives.
 * - `mpesa`: the M-Pesa business statement export: the header row starting
 *   with "Receipt No." (rows above it are the report's summary), columns
 *   Completion Time, Details, Transaction Status, Paid In, Withdrawn and
 *   Balance; only completed transactions are lines.
 */
class StatementParser
{
    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y'];

    public const MAX_LINES = 5000;

    public function __construct(private readonly CurrencyDecimals $decimals) {}

    /**
     * @param  array<string, string|null>  $mapping  csv only: date, description, reference, amount, money_in, money_out, date_format
     * @return list<array{date: string, description: string, reference: ?string, amount_minor: int, balance_minor: ?int}>
     */
    public function parse(string $path, string $format, string $currency, array $mapping = []): array
    {
        $rows = $this->rows($path);

        return $format === 'mpesa' ? $this->mpesa($rows, $currency) : $this->csv($rows, $currency, $mapping);
    }

    /**
     * The file as UTF-8: UTF-8 as it is (a byte-order mark dropped);
     * Windows-1252 (Excel's CSV on Windows) or ISO-8859-1 converted. Anything
     * else (UTF-16, binary data such as a spreadsheet) is refused
     * (`statement_unreadable`) rather than read as nonsense.
     */
    public static function utf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF") || str_contains($content, "\0")) {
            throw LedgerError::make('statement_unreadable', field: 'file');
        }

        if (mb_check_encoding($content, 'UTF-8')) {
            return preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        }

        // Bytes Windows-1252 leaves undefined mean ISO-8859-1 (C1 controls).
        $from = preg_match('/[\x81\x8D\x8F\x90\x9D]/', $content) === 1 ? 'ISO-8859-1' : 'Windows-1252';

        return mb_convert_encoding($content, 'UTF-8', $from);
    }

    /** @return list<list<string>> */
    private function rows(string $path): array
    {
        $content = is_readable($path) ? file_get_contents($path) : false;

        if ($content === false) {
            throw LedgerError::make('statement_unreadable', field: 'file');
        }

        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, self::utf8($content));
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(fn ($cell) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell)), $row);

            if (count($rows) > self::MAX_LINES + 50) {
                throw LedgerError::make('statement_too_long', ['max' => (string) self::MAX_LINES], 'file');
            }
        }

        fclose($handle);

        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows, string $currency, array $mapping): array
    {
        $header = array_map('mb_strtolower', array_shift($rows) ?? []);
        $index = function (?string $name) use ($header): ?int {
            $found = $name === null || $name === '' ? false : array_search(mb_strtolower($name), $header, true);

            return $found === false ? null : (int) $found;
        };
        $columns = [];

        foreach (['date', 'description', 'reference', 'amount', 'money_in', 'money_out', 'balance'] as $key) {
            $columns[$key] = $index($mapping[$key] ?? null);

            if (($mapping[$key] ?? null) !== null && ($mapping[$key] ?? '') !== '' && $columns[$key] === null) {
                throw LedgerError::make('statement_column_missing', ['column' => (string) $mapping[$key]], "mapping.{$key}");
            }
        }

        if ($columns['date'] === null || $columns['description'] === null || ($columns['amount'] === null && $columns['money_in'] === null && $columns['money_out'] === null)) {
            throw LedgerError::make('statement_mapping', field: 'mapping');
        }

        $format = in_array($mapping['date_format'] ?? 'Y-m-d', self::DATE_FORMATS, true) ? ($mapping['date_format'] ?? 'Y-m-d') : 'Y-m-d';
        $lines = [];

        foreach ($rows as $number => $row) {
            $cell = fn (?int $i) => $i === null ? '' : ($row[$i] ?? '');

            if (implode('', $row) === '') {
                continue;
            }

            $amount = $columns['amount'] !== null
                ? $this->minor($cell($columns['amount']), $currency, $number + 2)
                : $this->minor($cell($columns['money_in']), $currency, $number + 2) - abs($this->minor($cell($columns['money_out']), $currency, $number + 2));

            if ($amount === 0) {
                continue;
            }

            $lines[] = [
                'date' => $this->date($cell($columns['date']), $format, $number + 2),
                'description' => mb_substr($cell($columns['description']), 0, 255),
                'reference' => $cell($columns['reference']) === '' ? null : mb_substr($cell($columns['reference']), 0, 100),
                'amount_minor' => $amount,
                'balance_minor' => $columns['balance'] === null || $cell($columns['balance']) === '' ? null : $this->minor($cell($columns['balance']), $currency, $number + 2),
            ];
        }

        return $lines;
    }

    /** @param list<list<string>> $rows */
    private function mpesa(array $rows, string $currency): array
    {
        $start = null;

        foreach ($rows as $i => $row) {
            if (str_starts_with(mb_strtolower($row[0] ?? ''), 'receipt no')) {
                $start = $i;

                break;
            }
        }

        if ($start === null) {
            throw LedgerError::make('statement_not_mpesa', field: 'file');
        }

        $header = array_map('mb_strtolower', $rows[$start]);
        $col = function (string $name) use ($header): int {
            foreach ($header as $i => $title) {
                if (str_starts_with($title, $name)) {
                    return $i;
                }
            }

            throw LedgerError::make('statement_not_mpesa', field: 'file');
        };
        [$receipt, $time, $details, $status, $in, $out] = [$col('receipt no'), $col('completion time'), $col('details'), $col('transaction status'), $col('paid in'), $col('withdrawn')];
        $balance = in_array('balance', $header, true) ? array_search('balance', $header, true) : null;
        $lines = [];

        foreach (array_slice($rows, $start + 1) as $offset => $row) {
            if (($row[$receipt] ?? '') === '' || mb_strtolower($row[$status] ?? '') !== 'completed') {
                continue;
            }

            $line = $start + $offset + 2;
            $amount = $this->minor($row[$in] ?? '', $currency, $line) - abs($this->minor($row[$out] ?? '', $currency, $line));

            if ($amount === 0) {
                continue;
            }

            $lines[] = [
                'date' => $this->dateTime($row[$time] ?? '', $line),
                'description' => mb_substr($row[$details] ?? '', 0, 255),
                'reference' => mb_substr($row[$receipt], 0, 100),
                'amount_minor' => $amount,
                'balance_minor' => $balance === null || ($row[$balance] ?? '') === '' ? null : $this->minor($row[$balance], $currency, $line),
            ];
        }

        return $lines;
    }

    /** A statement amount in minor units: "1,234.50", "-150.00", "(150.00)"; never more decimals than the currency has. */
    private function minor(string $value, string $currency, int $line): int
    {
        $value = str_replace([',', ' ', "\u{00A0}"], '', trim($value));

        if ($value === '' || $value === '-') {
            return 0;
        }

        $negative = str_starts_with($value, '(') && str_ends_with($value, ')');
        $value = trim($value, '()');

        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw LedgerError::make('statement_amount', ['line' => (string) $line], 'file');
        }

        $decimals = $this->decimals->for($currency);

        if ($decimal->getScale() > $decimals && ! $decimal->toScale($decimals, RoundingMode::Down)->isEqualTo($decimal)) {
            throw LedgerError::make('statement_amount', ['line' => (string) $line], 'file');
        }

        $minor = (int) (string) $decimal->withPointMovedRight($decimals)->toScale(0);

        return $negative ? -abs($minor) : $minor;
    }

    private function date(string $value, string $format, int $line): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!'.$format, $value);
        } catch (Throwable) {
            $date = false;
        }

        if ($date === false || $date === null) {
            throw LedgerError::make('statement_date', ['line' => (string) $line], 'file');
        }

        return $date->toDateString();
    }

    private function dateTime(string $value, int $line): string
    {
        foreach (['Y-m-d H:i:s', 'd-m-Y H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i', 'd-m-Y H:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== false && $date !== null) {
                return $date->toDateString();
            }
        }

        throw LedgerError::make('statement_date', ['line' => (string) $line], 'file');
    }
}
