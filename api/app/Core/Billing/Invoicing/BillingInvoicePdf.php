<?php

namespace App\Core\Billing\Invoicing;

use App\Core\Billing\Models\BillingInvoice;
use App\Core\Currency\CurrencyDecimals;
use App\Core\DocumentTemplates\DefaultTemplates;
use App\Core\DocumentTemplates\PdfRenderer;

/**
 * SUB-03: an invoice of the platform as a PDF, from the customer's copy
 * (never the operator's tables), through the document templates' default
 * A4 invoice (TPL-01): black on white, the seller's legal name and tax id,
 * the period, lines, discounts, tax and total.
 */
class BillingInvoicePdf
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly CurrencyDecimals $decimals,
    ) {}

    public function render(BillingInvoice $invoice, string $locale): string
    {
        $locale = in_array($locale, ['en', 'fr'], true) ? $locale : 'en';

        return $this->pdf->pdf('sales.invoice', $this->template($locale), $this->data($invoice, $locale), false);
    }

    /**
     * The default A4 invoice, with the service period where a sales invoice
     * prints its reference: that field is labelled for refunds ("Original
     * receipt"), never right for a subscription period.
     */
    public function template(string $locale): array
    {
        $swap = function (array $node) use (&$swap): array {
            if (($node['field'] ?? null) === 'document.reference') {
                $node['field'] = 'document.period';
            }

            foreach ($node as $key => $child) {
                if (is_array($child)) {
                    $node[$key] = $swap($child);
                }
            }

            return $node;
        };

        return $swap(DefaultTemplates::for('sales.invoice', $locale));
    }

    public function fileName(BillingInvoice $invoice): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', 'invoice-'.$invoice->number), '-').'.pdf';
    }

    /** The invoice in the document templates' data shape (DataSource). */
    public function data(BillingInvoice $invoice, string $locale): array
    {
        $currency = $invoice->currency;
        $money = fn (int $minor) => ['minor' => (string) $minor, 'currency' => $currency];
        $lines = [];

        foreach ($invoice->lines as $line) {
            if ((int) $line['amount'] <= 0) {
                continue;
            }

            $lines[] = [
                'item_name' => BillingFiscalSource::name($line, $locale).self::detail($line, $locale),
                'item_code' => strtoupper((string) ($line['key'] ?? $line['type'])),
                'qty' => (string) ($line['quantity'] ?? 1),
                'unit' => isset($line['unit']) ? __('billing.units.'.$line['unit'], [], $locale) : '',
                'unit_price' => $money((int) ($line['unit_amount'] ?? $line['amount'])),
                'discount' => $money((int) ($line['discount_minor'] ?? 0)),
                'tax_rate' => $line['tax_rate'] ?? '',
                'tax' => $money((int) ($line['tax_minor'] ?? 0)),
                'total' => $money((int) ($line['net_minor'] ?? $line['amount'])),
            ];
        }

        $seller = $invoice->seller ?? [];
        $customer = $invoice->customer ?? [];

        return [
            'currencies' => [$currency => $this->decimals->for($currency)],
            'company' => [
                'name' => $seller['name'] ?? '',
                'legal_name' => $seller['legal_name'] ?? ($seller['name'] ?? ''),
                'tax_id' => $seller['tax_id'] ?? null,
                'address' => self::address($seller['address'] ?? null),
                'phone' => null,
                'email' => null,
                'country' => $seller['country'] ?? null,
                'logo' => null,
                'custom' => [],
            ],
            'branch' => null,
            'location' => null,
            'document' => [
                'number' => $invoice->number,
                'date' => $invoice->issued_at->toDateString(),
                'currency' => $currency,
                'due_date' => $invoice->due_at->toDateString(),
                'period' => $invoice->period_start->toDateString().' – '.$invoice->period_end->subDay()->toDateString(),
            ],
            'customer' => [
                'name' => $customer['name'] ?? '',
                'tax_id' => $customer['tax_id'] ?? null,
                'phone' => null,
                'email' => null,
                'address' => self::address($customer['address'] ?? null),
                'tags' => [],
                'custom' => [],
            ],
            'lines' => $lines,
            'totals' => [
                'subtotal' => $money($invoice->subtotal_minor),
                'discount' => $money($invoice->discount_minor),
                'tax' => $money($invoice->tax_minor),
                'total' => $money($invoice->total_minor),
                'tax_lines' => [],
            ],
            'payments' => [],
            'change' => null,
            'fiscal' => null,
        ];
    }

    private static function detail(array $line, string $locale): string
    {
        return match ($line['type']) {
            'proration' => ' ('.__('billing.lines.proration_days', ['days' => (int) ($line['quantity'] ?? 0), 'period' => (int) ($line['period_days'] ?? 0)], $locale).')',
            default => isset($line['months']) && (int) $line['months'] > 1 ? ' ('.__('billing.lines.months', ['months' => (int) $line['months']], $locale).')' : '',
        };
    }

    private static function address(mixed $address): ?string
    {
        if (is_string($address)) {
            return $address;
        }

        return is_array($address) ? implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $address))) : null;
    }
}
