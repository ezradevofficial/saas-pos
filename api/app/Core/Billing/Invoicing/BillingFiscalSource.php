<?php

namespace App\Core\Billing\Invoicing;

use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\FiscalDocument;

/**
 * SUB-03: the operator's invoices as fiscal documents (source `billing`),
 * read in the operator tenant's context by the fiscal queue. Each positive
 * line is one service line (quantity 1) with its share of the discounts,
 * its net amount and the tax included in it.
 */
class BillingFiscalSource implements FiscalDocumentSource
{
    public const KEY = 'billing';

    public function key(): string
    {
        return self::KEY;
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        $invoice = OperatorInvoice::query()->findOrFail($documentId);
        $lines = [];
        $number = 0;

        foreach ($invoice->lines as $line) {
            if ((int) $line['amount'] <= 0) {
                continue;
            }

            $code = strtoupper((string) ($line['key'] ?? $line['type']));
            $lines[] = [
                'line_no' => ++$number,
                'item_id' => $code,
                'item_code' => $code,
                'item_name' => self::name($line),
                'qty' => '1',
                'unit_price_minor' => (int) $line['amount'],
                'discount_minor' => (int) ($line['discount_minor'] ?? 0),
                'tax_code_id' => $invoice->tax_code_id,
                'tax_rate' => $line['tax_rate'] ?? null,
                'tax_minor' => (int) ($line['tax_minor'] ?? 0),
                'total_minor' => (int) ($line['net_minor'] ?? $line['amount']),
            ];
        }

        return FiscalDocument::fromArray([
            'type' => 'sale',
            'id' => $invoice->id,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'company_id' => $invoice->company_id,
            'currency' => $invoice->currency,
            'customer' => ['tin' => $invoice->customer['tax_id'] ?? null, 'name' => $invoice->customer['name'] ?? null],
            'payment_type' => 'other',
            'lines' => $lines,
            'totals' => ['tax_minor' => $invoice->tax_minor, 'total_minor' => $invoice->total_minor],
        ]);
    }

    /** The line's name for the authority, in English (the catalogue's name, else the line type's label). */
    public static function name(array $line, string $locale = 'en'): string
    {
        $name = $line['name'] ?? null;

        if (is_array($name) && ($name[$locale] ?? $name['en'] ?? null) !== null) {
            return (string) ($name[$locale] ?? $name['en']);
        }

        return __('billing.lines.'.$line['type'], ['key' => (string) ($line['key'] ?? '')], $locale);
    }
}
