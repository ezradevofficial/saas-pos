<?php

namespace Modules\Pharmacy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Support\CompanyDays;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-04, PHA-05: a lot, its state today in its company's day (`expired`,
 * `recalled`, `quarantined`, `near_expiry` or `active`) and, in the detail,
 * its stock per location.
 *
 * @mixin Lot
 */
class LotResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Lot $lot */
        $lot = $this->resource;
        $days = app(CompanyDays::class);

        return [
            'id' => $lot->id,
            'item' => Refs::item($lot->item),
            'company' => Refs::place($lot->company),
            'batch_number' => $lot->batch_number,
            'manufactured_on' => $lot->manufactured_on?->toDateString(),
            'expires_on' => $lot->expires_on->toDateString(),
            'days_left' => $days->daysLeft($lot),
            'state' => $days->state($lot),
            'status' => $lot->status,
            'quarantine_reason' => $lot->quarantine_reason,
            'quarantined_at' => Refs::at($lot->quarantined_at),
            'recall_id' => $lot->recall_id,
            'supplier' => Refs::place($lot->supplier),
            ...($this->detail ? [
                'balances' => $lot->balances->map(fn ($balance) => ['location' => Refs::place($balance->location), 'qty' => Qty::show($balance->qty)])->values()->all(),
            ] : []),
        ];
    }
}
