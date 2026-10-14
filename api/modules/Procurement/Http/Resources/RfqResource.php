<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\Quote;
use Modules\Procurement\Models\QuoteLine;
use Modules\Procurement\Models\Rfq;
use Modules\Procurement\Models\RfqLine;
use Modules\Procurement\Models\RfqSupplier;
use Modules\Procurement\Support\Places;

/**
 * PRC-03, PRC-04: an RFQ; in the detail its lines, invited suppliers, the
 * quotes typed in and what the reader may do.
 *
 * @mixin Rfq
 */
class RfqResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Rfq $rfq */
        $rfq = $this->resource;
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($request->user(), "procurement.rfq.{$permission}", $rfq->branch_id);

        return [
            'id' => $rfq->id,
            'number' => $rfq->number,
            'status' => $rfq->status,
            'title' => $rfq->title,
            'note' => $rfq->note,
            'deadline_at' => Refs::at($rfq->deadline_at),
            'company' => Refs::named($rfq->company),
            'branch' => Refs::named($rfq->branch),
            'requisition' => $rfq->requisition === null ? null : ['id' => $rfq->requisition->id, 'number' => $rfq->requisition->number],
            'created_by' => Refs::user($rfq->creator),
            'suppliers_count' => $rfq->suppliers_count ?? $rfq->suppliers()->count(),
            'quotes_count' => $rfq->quotes_count ?? $rfq->quotes()->count(),
            'issued_at' => Refs::at($rfq->issued_at),
            'closed_at' => Refs::at($rfq->closed_at),
            'cancelled_at' => Refs::at($rfq->cancelled_at),
            'cancel_reason' => $rfq->cancel_reason,
            'created_at' => Refs::at($rfq->created_at),
            ...($this->detail ? [
                'lines' => $rfq->lines->map(fn (RfqLine $line) => [
                    'id' => $line->id, 'line_no' => $line->line_no, 'item' => Refs::item($line->item), 'description' => $line->description,
                    'uom' => Refs::uom($line->uom), 'qty' => Refs::qty($line->qty), 'requisition_line_id' => $line->requisition_line_id,
                ])->all(),
                'suppliers' => $rfq->suppliers->map(fn (RfqSupplier $supplier) => Refs::named($supplier->party))->all(),
                'quotes' => $rfq->quotes->map(fn (Quote $quote) => self::quote($quote))->all(),
                // PRC-05: the draft POs the award made.
                'orders' => Order::query()->whereIn('quote_id', $rfq->quotes->pluck('id'))->orderBy('created_at')->get(['id', 'number', 'status', 'party_id'])
                    ->map(fn (Order $order) => ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'party_id' => $order->party_id])->all(),
                'can' => [
                    'edit' => $rfq->status === Rfq::DRAFT && $at('create'),
                    'issue' => $rfq->status === Rfq::DRAFT && $at('create'),
                    'quote' => $rfq->status === Rfq::ISSUED && $at('create'),
                    'award' => $rfq->status === Rfq::ISSUED && $at('create'),
                    'cancel' => in_array($rfq->status, [Rfq::DRAFT, Rfq::ISSUED], true) && $at('cancel'),
                ],
            ] : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function quote(Quote $quote): array
    {
        return [
            'id' => $quote->id,
            'party' => Refs::named($quote->party),
            'reference' => $quote->reference,
            'quote_date' => Refs::date($quote->quote_date),
            'valid_until' => Refs::date($quote->valid_until),
            'status' => $quote->status,
            'currency' => $quote->currency,
            'fx' => Refs::fx($quote->fx),
            'total' => Refs::money($quote->total_minor, $quote->currency),
            'base_total' => Refs::money($quote->base_total_minor, $quote->base_currency),
            'note' => $quote->note,
            'lines' => $quote->lines->map(fn (QuoteLine $line) => [
                'id' => $line->id, 'rfq_line_id' => $line->rfq_line_id, 'qty' => Refs::qty($line->qty),
                'unit_price' => Refs::money($line->unit_price_minor, $quote->currency),
                'base_unit_price' => Refs::money($line->base_unit_price_minor, $quote->base_currency),
                'lead_time_days' => $line->lead_time_days, 'note' => $line->note, 'selected' => $line->selected,
            ])->values()->all(),
        ];
    }
}
