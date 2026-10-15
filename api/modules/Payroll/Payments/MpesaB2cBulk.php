<?php

namespace Modules\Payroll\Payments;

use Modules\Payroll\Statutory\PayrollPack;

/**
 * PAY-20: an M-Pesa B2C bulk payment file (Kenya), a file only: nothing
 * calls Safaricom. The columns and their order are pack data
 * (`payment_formats.mpesa_b2c_bulk.columns`, keys among SUPPORTED); while
 * the pack has none the file is refused (`format_needs_confirmation`),
 * and while the layout is unconfirmed the file is flagged.
 */
final class MpesaB2cBulk implements PaymentFormat
{
    public const KEY = 'mpesa_b2c_bulk';

    /** Pack column key => payment row field. */
    public const SUPPORTED = ['phone' => 'account_number', 'amount' => 'amount', 'name' => 'account_name', 'reference' => 'reference', 'employee_number' => 'employee_number'];

    public function __construct(private readonly PayrollPack $packs) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'payroll.payments.formats.mpesa_b2c_bulk';
    }

    public function methods(): array
    {
        return ['mobile_money'];
    }

    public function build(array $rows, ?string $country): array
    {
        $layout = $this->packs->paymentFormat($country, self::KEY);
        $columns = $layout['columns'] ?? null;

        if (! is_array($columns) || $columns === [] || array_diff($columns, array_keys(self::SUPPORTED)) !== []) {
            return ['content' => null, 'error' => 'format_needs_confirmation', 'needs_confirmation' => true];
        }

        return [
            'content' => Csv::of($columns, array_map(fn (array $row) => array_map(fn (string $c) => $row[self::SUPPORTED[$c]] ?? '', $columns), $rows)),
            'error' => null,
            'needs_confirmation' => (bool) ($layout['needs_confirmation'] ?? true),
        ];
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
