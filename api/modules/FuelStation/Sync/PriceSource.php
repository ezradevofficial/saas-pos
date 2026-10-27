<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Station;

/**
 * FUEL-08, FUEL-22, NFR-04: the prices per litre of the till's station: for
 * each product the one in force now and every scheduled one, so a till that
 * is offline over a price change sells at the right price by the sale time
 * (the latest row whose `effective_from` is not after it). The server wins
 * prices; a sale at another price is flagged (`price_differs`).
 */
class PriceSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_prices';
    }

    public function module(): string
    {
        return FuelStationServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $stationId = Station::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->value('id');
        $prices = $stationId === null ? collect() : Price::query()->where('station_id', $stationId)->whereNull('archived_at')->orderBy('product_id')->orderBy('effective_from')->orderBy('id')->get();
        $rows = [];

        foreach ($prices->groupBy('product_id') as $own) {
            $current = $own->filter(fn (Price $p) => $p->effective_from->lessThanOrEqualTo($scope->at))->last();

            foreach ($own as $price) {
                if ($price->effective_from->greaterThan($scope->at) || $price->id === $current?->id) {
                    $rows[] = [
                        'id' => $price->id,
                        'product_id' => $price->product_id,
                        'price_minor' => (string) $price->price_minor,
                        'currency' => $price->currency,
                        'effective_from' => $price->effective_from->utc()->format('Y-m-d\TH:i:s\Z'),
                    ];
                }
            }
        }

        return $rows;
    }
}
