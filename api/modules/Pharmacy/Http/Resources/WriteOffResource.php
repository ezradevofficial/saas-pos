<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\WriteOff;
use Modules\Pharmacy\Models\WriteOffLine;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-12: a write-off, its reason and status (a failed one says why) and,
 * in the detail, its lines.
 *
 * @mixin WriteOff
 */
class WriteOffResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var WriteOff $writeOff */
        $writeOff = $this->resource;

        return [
            'id' => $writeOff->id,
            'number' => $writeOff->number,
            'status' => $writeOff->status,
            'reason' => $writeOff->reason,
            'note' => $writeOff->note,
            'failure' => $writeOff->failure,
            'company' => Refs::place($writeOff->company),
            'branch' => Refs::place($writeOff->branch),
            'location' => Refs::place($writeOff->location),
            'requested_by' => Refs::user($writeOff->requester),
            'lines_count' => $writeOff->lines_count ?? $writeOff->lines()->count(),
            'decided_at' => Refs::at($writeOff->decided_at),
            'posted_at' => Refs::at($writeOff->posted_at),
            'cancelled_at' => Refs::at($writeOff->cancelled_at),
            'cancel_reason' => $writeOff->cancel_reason,
            'created_at' => Refs::at($writeOff->created_at),
            ...($this->detail ? [
                'lines' => $writeOff->lines->map(fn (WriteOffLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'item' => Refs::item($line->item),
                    'lot' => ['id' => $line->lot_id, 'batch_number' => $line->lot?->batch_number, 'expires_on' => $line->lot?->expires_on->toDateString()],
                    'qty' => Qty::show($line->qty),
                ])->values()->all(),
            ] : []),
        ];
    }
}
