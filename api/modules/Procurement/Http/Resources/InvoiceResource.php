<?php

namespace Modules\Procurement\Http\Resources;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\Invoice;
use Modules\Procurement\Models\InvoiceLine;
use Modules\Procurement\Support\Places;

/**
 * PRC-15: a supplier invoice and, in the detail, its three-way match view:
 * per line what was ordered (quantity and price), received and accepted,
 * invoiced before, and this invoice's quantity and price, with the line's
 * result and variances; and what the reader may do.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($request->user(), "procurement.invoice.{$permission}", $invoice->branch_id);
        $open = in_array($invoice->status, [Invoice::DRAFT, Invoice::MATCHED, Invoice::EXCEPTION], true);

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'supplier_reference' => $invoice->supplier_reference,
            'status' => $invoice->status,
            'order' => $invoice->order === null ? null : ['id' => $invoice->order->id, 'number' => $invoice->order->number],
            'party' => Refs::named($invoice->party),
            'company' => Refs::named($invoice->company),
            'branch' => Refs::named($invoice->branch),
            'invoice_date' => Refs::date($invoice->invoice_date),
            'due_date' => Refs::date($invoice->due_date),
            'currency' => $invoice->currency,
            'fx' => Refs::fx($invoice->fx),
            'tax_inclusive' => (bool) $invoice->tax_inclusive,
            'subtotal' => Refs::money($invoice->subtotal_minor, $invoice->currency),
            'tax' => Refs::money($invoice->tax_minor, $invoice->currency),
            'total' => Refs::money($invoice->total_minor, $invoice->currency),
            'base_total' => Refs::money($invoice->base_total_minor, $invoice->base_currency),
            'base_grni' => Refs::money($invoice->base_grni_minor, $invoice->base_currency),
            'override_reason' => $invoice->override_reason,
            'created_by' => Refs::user($invoice->creator),
            'approved_by' => Refs::user($invoice->approver),
            'approved_at' => Refs::at($invoice->approved_at),
            'cancelled_at' => Refs::at($invoice->cancelled_at),
            'cancel_reason' => $invoice->cancel_reason,
            'created_at' => Refs::at($invoice->created_at),
            ...($this->detail ? [
                'lines' => $invoice->lines->map(function (InvoiceLine $line) use ($invoice) {
                    $orderLine = $line->orderLine;
                    $invoicedBefore = BigDecimal::of((string) $orderLine->invoiced_qty);

                    if ($invoice->status === Invoice::APPROVED) {
                        $invoicedBefore = $invoicedBefore->minus((string) $line->qty);
                    }

                    return [
                        'id' => $line->id,
                        'line_no' => $line->line_no,
                        'order_line_id' => $line->order_line_id,
                        'description' => $orderLine->description,
                        'ordered_qty' => Refs::qty($orderLine->qty),
                        'ordered_unit_price' => Refs::money($orderLine->unit_price_minor, $invoice->currency),
                        'received_qty' => Refs::qty($orderLine->received_qty),
                        'accepted_qty' => Refs::qty((string) BigDecimal::of((string) $orderLine->received_qty)->minus((string) $orderLine->rejected_qty)),
                        'invoiced_before_qty' => Refs::qty((string) $invoicedBefore),
                        'qty' => Refs::qty($line->qty),
                        'unit_price' => Refs::money($line->unit_price_minor, $invoice->currency),
                        'tax_code' => $line->taxCode === null ? null : ['id' => $line->taxCode->id, 'code' => $line->taxCode->code, 'name' => $line->taxCode->name],
                        'tax_rate' => Refs::qty($line->tax_rate),
                        'net' => Refs::money($line->net_minor, $invoice->currency),
                        'tax' => Refs::money($line->tax_minor, $invoice->currency),
                        'total' => Refs::money($line->total_minor, $invoice->currency),
                        'match_status' => $line->match_status,
                        'price_variance' => Refs::money($line->price_variance_minor, $invoice->currency),
                        'qty_variance' => Refs::qty($line->qty_variance),
                    ];
                })->all(),
                'can' => [
                    'edit' => $open && $at('create'),
                    'approve' => in_array($invoice->status, [Invoice::MATCHED, Invoice::EXCEPTION], true) && $at('approve')
                        && ($invoice->status === Invoice::MATCHED || $at('override')),
                    'override' => $at('override'),
                    'cancel' => $open && $at('cancel'),
                ],
            ] : []),
        ];
    }
}
