<?php

namespace Modules\FuelStation\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ArchivePriceRequest;
use Modules\FuelStation\Http\Requests\ListPricesRequest;
use Modules\FuelStation\Http\Requests\StorePriceRequest;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Prices\Prices;
use Modules\FuelStation\Support\Access;

/** FUEL-08: prices per litre per station and product, effective-dated and immutable; every create and archive is audited with who entered it. */
class PriceController
{
    public function __construct(private readonly Access $access, private readonly Present $present, private readonly Prices $prices) {}

    public function index(ListPricesRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Price::query()->join('fuel_stations', 'fuel_stations.id', '=', 'fuel_prices.station_id'), $request->user(), 'fuel.price.view', 'fuel_stations.location_id')
            ->select('fuel_prices.*')->orderByDesc('fuel_prices.effective_from')->orderByDesc('fuel_prices.id');
        $request->boolean('archived') ? $query->whereNotNull('fuel_prices.archived_at') : $query->whereNull('fuel_prices.archived_at');

        if ($request->filled('location_id')) {
            $query->where('fuel_stations.location_id', $request->validated('location_id'));
        }

        if ($request->filled('product_id')) {
            $query->where('fuel_prices.product_id', $request->validated('product_id'));
        }

        if ($request->boolean('current')) {
            // The price in force now: the latest live row that started, per station and product.
            $query->whereRaw('fuel_prices.effective_from = (select max(p2.effective_from) from fuel_prices p2 where p2.station_id = fuel_prices.station_id and p2.product_id = fuel_prices.product_id and p2.archived_at is null and p2.effective_from <= ?)', [CarbonImmutable::now()]);
        }

        return Paged::respond($query, $request, fn (Price $p) => $this->present->price($p));
    }

    public function store(StorePriceRequest $request): JsonResponse
    {
        $station = Station::query()->where('location_id', $request->validated('location_id'))->firstOrFail();
        $price = $this->prices->create([...$request->validated(), 'station_id' => $station->id], $request->user());

        return response()->json(['data' => $this->present->price($price)], 201);
    }

    public function archive(ArchivePriceRequest $request, Price $fuelPrice): JsonResponse
    {
        return response()->json(['data' => $this->present->price($this->prices->archive($fuelPrice))]);
    }
}
