<?php

namespace Modules\Payroll\Payments;

/**
 * PAY-20: a payment file format (pluggable: PaymentFormats::register). A
 * format says which payment methods it pays and turns payment rows into a
 * file. Rows: `{employee_number, name, method, provider, branch_code,
 * account_name, account_number, currency, amount (major units, decimal
 * string), amount_minor, reference}`. A format never calls a provider.
 */
interface PaymentFormat
{
    public function key(): string;

    /** Translation key of its name. */
    public function label(): string;

    /** @return list<string> the payment methods it pays (`bank`, `mobile_money`) */
    public function methods(): array;

    /**
     * The file, or null with the reason when the format cannot be built
     * for $country (e.g. its layout is not confirmed in the pack).
     *
     * @param  list<array<string, string|null>>  $rows
     * @return array{content: ?string, error: ?string, needs_confirmation: bool}
     */
    public function build(array $rows, ?string $country): array;

    public function extension(): string;

    public function contentType(): string;
}
