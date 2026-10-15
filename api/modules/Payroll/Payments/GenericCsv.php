<?php

namespace Modules\Payroll\Payments;

/**
 * PAY-20: the generic payment CSV, for any bank or wallet that takes a
 * spreadsheet: one row per employee paid by bank or mobile money.
 */
final class GenericCsv implements PaymentFormat
{
    public const COLUMNS = ['employee_number', 'name', 'method', 'provider', 'branch_code', 'account_name', 'account_number', 'currency', 'amount', 'reference'];

    public function key(): string
    {
        return 'generic_csv';
    }

    public function label(): string
    {
        return 'payroll.payments.formats.generic_csv';
    }

    public function methods(): array
    {
        return ['bank', 'mobile_money'];
    }

    public function build(array $rows, ?string $country): array
    {
        return ['content' => Csv::of(array_map(fn (string $c) => __('payroll.payments.columns.'.$c), self::COLUMNS), array_map(
            fn (array $row) => array_map(fn (string $c) => $row[$c] ?? '', self::COLUMNS),
            $rows,
        )), 'error' => null, 'needs_confirmation' => false];
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }
}
