<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\Receipt;
use Modules\Procurement\Models\ReceiptLine;
use Modules\Procurement\Support\Places;

/**
 * PRC-14: a goods received note; in the detail its lines (with the PO
 * line's ordered quantity), values and landed costs, and what the reader
 * may do.
 *
 * @mixin Receipt
 */
class ReceiptResource extends JsonResource
{
    use HidesValues;

    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Receipt $receipt */
        $receipt = $this->resource;
        // RBAC-05, decision 27: money only for holders of procurement.value.view.
        $this->seeValues($request, $receipt->branch_id, $receipt->location_id);
        $places = app(Places::class);
        $at = fn (string $permission) => $places->atLocation($request->user(), "procurement.receipt.{$permission}", $receipt->location_id);

        return [
            'id' => $receipt->id,
            'number' => $receipt->number,
            'status' => $receipt->status,
            'order' => $receipt->order === null ? null : ['id' => $receipt->order->id, 'number' => $receipt->order->number],
            'party' => Refs::named($receipt->party),
            'company' => Refs::named($receipt->company),
            'branch' => Refs::named($receipt->branch),
            'location' => Refs::named($receipt->location),
            'received_at' => Refs::at($receipt->received_at),
            'delivery_note' => $receipt->delivery_note,
            'note' => $receipt->note,
            'currency' => $receipt->currency,
            'fx' => Refs::fx($receipt->fx),
            'value' => $this->money($receipt->value_minor, $receipt->currency),
            'base_value' => $this->money($receipt->base_value_minor, $receipt->base_currency),
            'landed' => $this->money($receipt->landed_minor, $receipt->base_currency),
            'created_by' => Refs::user($receipt->creator),
            'posted_at' => Refs::at($receipt->posted_at),
            'cancelled_at' => Refs::at($receipt->cancelled_at),
            'cancel_reason' => $receipt->cancel_reason,
            'created_at' => Refs::at($receipt->created_at),
            ...($this->detail ? [
                'lines' => $receipt->lines->map(fn (ReceiptLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'order_line_id' => $line->order_line_id,
                    'item' => Refs::item($line->item),
                    'description' => $line->orderLine?->description,
                    'uom' => Refs::uom($line->uom),
                    'ordered_qty' => Refs::qty($line->orderLine?->qty),
                    'received_qty' => Refs::qty($line->received_qty),
                    'rejected_qty' => Refs::qty($line->rejected_qty),
                    'accepted_qty' => Refs::qty($line->accepted_qty),
                    'reject_reason' => $line->reject_reason,
                    'batch_number' => $line->batch_number,
                    'expires_on' => Refs::date($line->expires_on),
                    'serials' => $line->serials ?? [],
                    'weight' => Refs::qty($line->weight),
                    'value' => $this->money($line->value_minor, $receipt->currency),
                    'base_value' => $this->money($line->base_value_minor, $receipt->base_currency),
                    'landed' => $this->money($line->landed_minor, $receipt->base_currency),
                    'on_time' => $line->on_time,
                    // Stage 7B closing review: what Inventory or Stores could not receive of the line.
                    'receive_exceptions' => $line->receive_exceptions ?? [],
                ])->all(),
                'can' => [
                    'edit' => $receipt->status === Receipt::DRAFT && $at('create'),
                    'post' => $receipt->status === Receipt::DRAFT && $at('create'),
                    'cancel' => $receipt->status === Receipt::DRAFT && $at('cancel'),
                ],
            ] : []),
        ];
    }
}
