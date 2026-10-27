<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Receipt;
use Modules\Pharmacy\Models\ReceiptLine;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-06: a back-office receipt and the lots it brought in.
 *
 * @mixin Receipt
 */
class ReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Receipt $receipt */
        $receipt = $this->resource;

        return [
            'id' => $receipt->id,
            'reference' => $receipt->reference,
            'company' => Refs::place($receipt->company),
            'branch' => Refs::place($receipt->branch),
            'location' => Refs::place($receipt->location),
            'supplier' => Refs::place($receipt->supplier),
            'received_at' => Refs::at($receipt->received_at),
            'note' => $receipt->note,
            'created_by' => Refs::user($receipt->creator),
            'lines' => $receipt->lines->map(fn (ReceiptLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item' => Refs::item($line->item),
                'lot' => ['id' => $line->lot_id, 'batch_number' => $line->lot?->batch_number, 'expires_on' => $line->lot?->expires_on->toDateString()],
                'entered_qty' => Qty::show($line->entered_qty),
                'uom' => $line->uom === null ? null : ['id' => $line->uom->id, 'code' => (string) $line->uom->code],
                'qty' => Qty::show($line->qty),
                'unit_cost_minor' => $line->unit_cost_minor === null ? null : (string) $line->unit_cost_minor,
                'currency' => $line->currency,
            ])->values()->all(),
        ];
    }
}
