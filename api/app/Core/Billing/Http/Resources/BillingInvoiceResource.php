<?php

namespace App\Core\Billing\Http\Resources;

use App\Core\Billing\Invoicing\BillingFiscalSource;
use App\Core\Billing\Models\BillingInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SUB-03: an invoice from the platform, from the tenant's copy. Money is
 * `{amount_minor, currency}`; line names in the request's language.
 *
 * @mixin BillingInvoice
 */
class BillingInvoiceResource extends JsonResource
{
    public bool $withLines = false;

    public function toArray(Request $request): array
    {
        $money = fn (int $minor) => ['amount_minor' => $minor, 'currency' => $this->currency];
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => $money($this->subtotal_minor),
            'discount' => $money($this->discount_minor),
            'tax' => $money($this->tax_minor),
            'total' => $money($this->total_minor),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'due_at' => $this->due_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'seller' => ['name' => $this->seller['legal_name'] ?? $this->seller['name'] ?? null, 'tax_id' => $this->seller['tax_id'] ?? null],
            'lines' => $this->when($this->withLines, fn () => array_map(fn (array $line) => [
                'line_no' => $line['line_no'] ?? null,
                'type' => $line['type'],
                'key' => $line['key'] ?? null,
                'name' => BillingFiscalSource::name($line, $locale),
                'quantity' => $line['quantity'] ?? null,
                'unit' => $line['unit'] ?? null,
                'amount' => $money((int) $line['amount']),
            ], $this->lines)),
        ];
    }
}
