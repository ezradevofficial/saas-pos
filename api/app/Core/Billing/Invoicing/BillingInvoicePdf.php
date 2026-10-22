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

            [$qty, $unitPrice, $perUnit] = self::quantityAndPrice($line);
            $lines[] = [
                'item_name' => BillingFiscalSource::name($line, $locale).self::detail($line, $perUnit, $locale),
                'item_code' => $line['type'] === 'app' ? 'APP' : strtoupper((string) ($line['key'] ?? $line['type'])),
                'qty' => (string) $qty,
                'unit' => $perUnit && isset($line['unit']) ? __('billing.units.'.$line['unit'], [], $locale) : '',
                'unit_price' => $money($unitPrice),
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

    /**
     * What the line prints so that quantity × unit price is its amount: the
     * billable units at the price for the months of the cycle when that
     * multiplies out; otherwise (a bundle with its outlets, a minimum
     * charge, overage by days, a proration) one unit at the amount, the
     * quantities spelled out in the name.
     *
     * @return array{0: int, 1: int, 2: bool} quantity, unit price, whether priced per unit
     */
    public static function quantityAndPrice(array $line): array
    {
        $amount = (int) $line['amount'];

        if ($line['type'] === 'module' && isset($line['billable'], $line['unit_amount'])) {
            $billable = (int) $line['billable'];
            $price = (int) $line['unit_amount'] * max(1, (int) ($line['months'] ?? 1));

            if ($billable > 0 && $billable * $price === $amount) {
                return [$billable, $price, true];
            }
        }

        return [1, $amount, false];
    }

    private static function detail(array $line, bool $perUnit, string $locale): string
    {
        $quantity = (int) ($line['quantity'] ?? 0);
        $billable = (int) ($line['billable'] ?? $quantity);
        $parts = [];

        if ($line['type'] === 'proration' || ($line['type'] === 'app' && isset($line['period_days']))) {
            $parts[] = __('billing.lines.proration_days', ['days' => $quantity, 'period' => (int) ($line['period_days'] ?? 0)], $locale);
        } elseif ($line['type'] === 'overage') {
            $parts[] = __('billing.lines.overage_days', ['quantity' => $quantity, 'unit' => __('billing.units.'.($line['unit'] ?? 'device'), [], $locale), 'days' => (int) ($line['days'] ?? 0), 'period' => (int) ($line['period_days'] ?? 0)], $locale);
        } elseif ($line['type'] === 'bundle') {
            $parts[] = __('billing.lines.bundle_outlets', ['outlets' => $quantity, 'included' => $quantity - $billable, 'extra' => $billable], $locale);
        } elseif ($line['type'] === 'module') {
            if (! $perUnit) {
                $parts[] = $quantity.' '.__('billing.units.'.($line['unit'] ?? 'tenant'), [], $locale);
                $parts[] = __('billing.lines.minimum', [], $locale);
            }

            if ($quantity > $billable) {
                $parts[] = __('billing.lines.included', ['count' => $quantity - $billable], $locale);
            }
        }

        if (isset($line['months']) && (int) $line['months'] > 1 && in_array($line['type'], ['module', 'bundle'], true)) {
            $parts[] = __('billing.lines.months', ['months' => (int) $line['months']], $locale);
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private static function address(mixed $address): ?string
    {
        if (is_string($address)) {
            return $address;
        }

        return is_array($address) ? implode(', ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $address))) : null;
    }
}
