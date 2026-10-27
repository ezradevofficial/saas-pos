<?php

namespace Modules\Pharmacy\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\LotBalance;
use Modules\Pharmacy\Support\CompanyDays;
use Modules\Pharmacy\Support\Qty;

/**
 * PHA-05, PHA-24: the stock of one lot at one location, with its lot's
 * state today. `last_sold_at` and `days_since_sale` are present on the
 * slow-movers report.
 *
 * @mixin LotBalance
 */
class StockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LotBalance $balance */
        $balance = $this->resource;
        $lot = $balance->lot;
        $days = app(CompanyDays::class);
        $lastSold = $balance->getAttribute('last_sold_at');

        return [
            'id' => $balance->id,
            'lot_id' => $lot->id,
            'item' => Refs::item($balance->item),
            'location' => Refs::place($balance->location),
            'batch_number' => $lot->batch_number,
            'manufactured_on' => $lot->manufactured_on?->toDateString(),
            'expires_on' => $lot->expires_on->toDateString(),
            'days_left' => $days->daysLeft($lot),
            'state' => $days->state($lot),
            'qty' => Qty::show($balance->qty),
            ...(array_key_exists('last_sold_at', $balance->getAttributes()) ? [
                'last_sold_at' => $lastSold === null ? null : CarbonImmutable::parse($lastSold)->utc()->format('Y-m-d\TH:i:s\Z'),
                'days_since_sale' => $lastSold === null ? null : (int) CarbonImmutable::parse($lastSold)->diffInDays(now()),
            ] : []),
        ];
    }
}
