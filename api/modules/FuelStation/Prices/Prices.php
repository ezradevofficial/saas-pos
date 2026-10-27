<?php

namespace Modules\FuelStation\Prices;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Support\FuelError;

/**
 * FUEL-08: prices per litre, per station and product, effective-dated and
 * immutable. The price of a sale is the latest live row that started at or
 * before the sale time; nothing is built in (the tenant types what the
 * regulator publishes). Every create and archive is audited (the model).
 */
class Prices
{
    /** A new price may start this far in the past (clock drift of the person typing), not further. */
    public const BACKDATE_MINUTES = 5;

    public function at(string $stationId, string $productId, CarbonInterface $at): ?Price
    {
        return Price::query()->where('station_id', $stationId)->where('product_id', $productId)->whereNull('archived_at')
            ->where('effective_from', '<=', $at)->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    /**
     * @param  array{station_id: string, product_id: string, price_minor: string|int, effective_from?: ?string, reference?: ?string}  $data
     */
    public function create(array $data, User $by): Price
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $by) {
            $station = Station::query()->whereKey($data['station_id'])->whereNull('archived_at')->lockForUpdate()->first() ?? throw FuelError::make(422, 'not_a_station', field: 'station_id');
            $product = Product::query()->whereKey($data['product_id'])->whereNull('archived_at')->first() ?? throw FuelError::make(422, 'product_unknown', field: 'product_id');

            if ($product->company_id !== $station->company_id) {
                throw FuelError::make(422, 'product_unknown', field: 'product_id');
            }

            $from = isset($data['effective_from']) ? CarbonImmutable::parse($data['effective_from'])->utc() : CarbonImmutable::now();
            $first = ! Price::query()->where('station_id', $station->id)->where('product_id', $product->id)->exists();

            if (! $first && $from->lessThan(CarbonImmutable::now()->subMinutes(self::BACKDATE_MINUTES))) {
                throw FuelError::make(422, 'price_in_past', field: 'effective_from');
            }

            if (Price::query()->where('station_id', $station->id)->where('product_id', $product->id)->whereNull('archived_at')->where('effective_from', $from)->exists()) {
                throw FuelError::make(422, 'price_exists', field: 'effective_from');
            }

            return Price::create([
                'company_id' => $station->company_id,
                'station_id' => $station->id,
                'product_id' => $product->id,
                'price_minor' => (string) $data['price_minor'],
                'currency' => $station->currency,
                'effective_from' => $from,
                'reference' => $data['reference'] ?? null,
                'entered_by' => $by->id,
            ])->refresh();
        });
    }

    /** Only a price that has not started can be withdrawn; one in force is superseded by a newer price. */
    public function archive(Price $price): Price
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($price) {
            $price = Price::query()->whereKey($price->id)->lockForUpdate()->firstOrFail();

            if ($price->archived_at === null) {
                if ($price->effective_from->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw FuelError::make(409, 'price_started');
                }

                $price->archive();
            }

            return $price;
        });
    }
}
