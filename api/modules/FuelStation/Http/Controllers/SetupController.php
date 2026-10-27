<?php

namespace Modules\FuelStation\Http\Controllers;

use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Modules\FuelStation\Http\Paged;
use Modules\FuelStation\Http\Present;
use Modules\FuelStation\Http\Requests\ArchiveNozzleRequest;
use Modules\FuelStation\Http\Requests\ArchiveProductRequest;
use Modules\FuelStation\Http\Requests\ArchivePumpRequest;
use Modules\FuelStation\Http\Requests\ArchiveStationRequest;
use Modules\FuelStation\Http\Requests\ArchiveTankRequest;
use Modules\FuelStation\Http\Requests\DipConvertRequest;
use Modules\FuelStation\Http\Requests\ListNozzlesRequest;
use Modules\FuelStation\Http\Requests\ListProductsRequest;
use Modules\FuelStation\Http\Requests\ListPumpsRequest;
use Modules\FuelStation\Http\Requests\ListStationsRequest;
use Modules\FuelStation\Http\Requests\ListStrappingRequest;
use Modules\FuelStation\Http\Requests\ListTanksRequest;
use Modules\FuelStation\Http\Requests\ShowTankRequest;
use Modules\FuelStation\Http\Requests\StoreNozzleRequest;
use Modules\FuelStation\Http\Requests\StoreProductRequest;
use Modules\FuelStation\Http\Requests\StorePumpRequest;
use Modules\FuelStation\Http\Requests\StoreStationRequest;
use Modules\FuelStation\Http\Requests\StoreStrappingRequest;
use Modules\FuelStation\Http\Requests\StoreTankRequest;
use Modules\FuelStation\Http\Requests\UpdateNozzleRequest;
use Modules\FuelStation\Http\Requests\UpdateProductRequest;
use Modules\FuelStation\Http\Requests\UpdatePumpRequest;
use Modules\FuelStation\Http\Requests\UpdateStationRequest;
use Modules\FuelStation\Http\Requests\UpdateTankRequest;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Pump;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\StrappingChart;
use Modules\FuelStation\Models\StrappingPoint;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\Access;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Tanks\Strapping;

/**
 * FUEL-02, FUEL-04 to FUEL-07: the forecourt set-up: stations, fuel
 * products, tanks with their strapping charts, pumps and nozzles. Creates,
 * updates and archives are audited by the models (AUD-01); archived, never
 * deleted (TEN-06).
 */
class SetupController
{
    /** The base unit codes that mean the litre (FUEL-04). */
    private const LITRE_CODES = ['L', 'LT', 'LTR', 'LITRE', 'LITER'];

    public function __construct(private readonly Access $access, private readonly Present $present, private readonly Strapping $strapping) {}

    // --- stations (FUEL-02)

    public function stations(ListStationsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Station::query(), $request->user(), 'fuel.setup.view')->orderBy('created_at');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->validated('company_id'));
        }

        return Paged::all($query, fn (Station $s) => $this->present->station($s));
    }

    public function storeStation(StoreStationRequest $request): JsonResponse
    {
        $location = Location::query()->with('branch')->findOrFail($request->validated('location_id'));
        $station = Station::create([
            'company_id' => $location->branch->company_id, 'branch_id' => $location->branch_id, 'location_id' => $location->id, 'currency' => $request->validated('currency'),
        ]);

        return response()->json(['data' => $this->present->station($station->refresh())], 201);
    }

    public function updateStation(UpdateStationRequest $request, Station $fuelStation): JsonResponse
    {
        if ($request->validated('currency') !== $fuelStation->currency
            && (Price::query()->where('station_id', $fuelStation->id)->exists() || Shift::query()->where('station_id', $fuelStation->id)->exists())) {
            throw FuelError::make(409, 'station_in_use', field: 'currency');
        }

        $fuelStation->fill($request->validated())->save();

        return response()->json(['data' => $this->present->station($fuelStation->refresh())]);
    }

    public function archiveStation(ArchiveStationRequest $request, Station $fuelStation): JsonResponse
    {
        if (Shift::query()->where('station_id', $fuelStation->id)->where('status', Shift::OPEN)->exists()) {
            throw FuelError::make(409, 'station_in_use');
        }

        $fuelStation->archive();

        return response()->json(['data' => $this->present->station($fuelStation->refresh())]);
    }

    // --- products (FUEL-04)

    public function products(ListProductsRequest $request): JsonResponse
    {
        $query = $this->access->scopeCompanies(Product::query(), $request->user(), 'fuel.setup.view')->orderBy('created_at');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        if ($request->filled('company_id')) {
            $query->where('company_id', $request->validated('company_id'));
        }

        return Paged::all($query, fn (Product $p) => $this->present->product($p));
    }

    public function storeProduct(StoreProductRequest $request): JsonResponse
    {
        $item = Item::query()->findOrFail($request->validated('item_id'));

        if ($item->company_id !== null && $item->company_id !== $request->validated('company_id')) {
            throw FuelError::make(422, 'item_other_company', field: 'item_id');
        }

        $uom = Uom::query()->find($item->base_uom_id);

        if ($uom === null || ! in_array(mb_strtoupper((string) $uom->code), self::LITRE_CODES, true)) {
            throw FuelError::make(422, 'item_not_litre', field: 'item_id');
        }

        $product = Product::create($request->validated());

        return response()->json(['data' => $this->present->product($product->refresh())], 201);
    }

    public function updateProduct(UpdateProductRequest $request, Product $fuelProduct): JsonResponse
    {
        $fuelProduct->fill($request->validated())->save();

        return response()->json(['data' => $this->present->product($fuelProduct->refresh())]);
    }

    public function archiveProduct(ArchiveProductRequest $request, Product $fuelProduct): JsonResponse
    {
        if (Tank::query()->where('product_id', $fuelProduct->id)->whereNull('archived_at')->exists()) {
            throw FuelError::make(409, 'product_in_use');
        }

        $fuelProduct->archive();

        return response()->json(['data' => $this->present->product($fuelProduct->refresh())]);
    }

    // --- tanks and strapping (FUEL-05, FUEL-06)

    public function tanks(ListTanksRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Tank::query(), $request->user(), 'fuel.setup.view')->orderBy('location_id')->orderBy('code');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        foreach (['location_id', 'product_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::all($query, fn (Tank $t) => $this->present->tank($t));
    }

    public function storeTank(StoreTankRequest $request): JsonResponse
    {
        $station = Station::query()->where('location_id', $request->validated('location_id'))->firstOrFail();
        $product = Product::query()->findOrFail($request->validated('product_id'));

        if ($product->company_id !== $station->company_id) {
            throw FuelError::make(422, 'product_unknown', field: 'product_id');
        }

        $data = $request->validated();
        unset($data['location_id']);

        try {
            $tank = Tank::create([...$data, 'company_id' => $station->company_id, 'branch_id' => $station->branch_id, 'location_id' => $station->location_id, 'station_id' => $station->id]);
        } catch (UniqueConstraintViolationException) {
            throw FuelError::make(422, 'code_used', field: 'code');
        }

        return response()->json(['data' => $this->present->tank($tank->refresh())], 201);
    }

    public function showTank(ShowTankRequest $request, Tank $fuelTank): JsonResponse
    {
        return response()->json(['data' => $this->present->tank($fuelTank)]);
    }

    public function updateTank(UpdateTankRequest $request, Tank $fuelTank): JsonResponse
    {
        $fuelTank->fill($request->validated())->save();

        return response()->json(['data' => $this->present->tank($fuelTank->refresh())]);
    }

    public function archiveTank(ArchiveTankRequest $request, Tank $fuelTank): JsonResponse
    {
        if (Nozzle::query()->where('tank_id', $fuelTank->id)->whereNull('archived_at')->exists()) {
            throw FuelError::make(409, 'tank_in_use');
        }

        $fuelTank->archive();

        return response()->json(['data' => $this->present->tank($fuelTank->refresh())]);
    }

    public function strapping(ListStrappingRequest $request, Tank $fuelTank): JsonResponse
    {
        return Paged::all(StrappingChart::query()->where('tank_id', $fuelTank->id)->orderByDesc('effective_from'), fn (StrappingChart $c) => $this->present->chart($c));
    }

    public function storeStrapping(StoreStrappingRequest $request, Tank $fuelTank): JsonResponse
    {
        $from = $request->filled('effective_from') ? CarbonImmutable::parse($request->validated('effective_from'))->utc() : CarbonImmutable::now();
        $chart = $this->strapping->create($fuelTank, $request->validated('points'), $from, $request->validated('note'), $request->user());

        return response()->json(['data' => $this->present->chart($chart)], 201);
    }

    public function dipConvert(DipConvertRequest $request, Tank $fuelTank): JsonResponse
    {
        $at = $request->filled('at') ? CarbonImmutable::parse($request->validated('at'))->utc() : CarbonImmutable::now();
        $chart = $this->strapping->chartAt($fuelTank, $at);

        if (! $request->filled('dip_cm')) {
            $points = StrappingPoint::query()->where('chart_id', $chart->id)->orderBy('dip_cm')->get(['dip_cm', 'litres']);

            return response()->json(['data' => ['tank_id' => $fuelTank->id, 'chart_id' => $chart->id, 'min_cm' => (string) $points->first()?->dip_cm, 'max_cm' => (string) $points->last()?->dip_cm, 'max_litres' => Litres::show($points->last()?->litres)]]);
        }

        [$chart, $litres] = $this->strapping->convert($fuelTank, $request->validated('dip_cm'), $at);

        return response()->json(['data' => ['tank_id' => $fuelTank->id, 'dip_cm' => $request->validated('dip_cm'), 'litres' => Litres::show($litres), 'chart_id' => $chart->id]]);
    }

    // --- pumps and nozzles (FUEL-07)

    public function pumps(ListPumpsRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Pump::query()->with('nozzles'), $request->user(), 'fuel.setup.view')->orderBy('location_id')->orderBy('code');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->validated('location_id'));
        }

        return Paged::all($query, fn (Pump $p) => $this->present->pump($p));
    }

    public function storePump(StorePumpRequest $request): JsonResponse
    {
        $station = Station::query()->where('location_id', $request->validated('location_id'))->firstOrFail();

        try {
            $pump = Pump::create([
                'company_id' => $station->company_id, 'branch_id' => $station->branch_id, 'location_id' => $station->location_id, 'station_id' => $station->id,
                'code' => $request->validated('code'), 'name' => $request->validated('name'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw FuelError::make(422, 'code_used', field: 'code');
        }

        return response()->json(['data' => $this->present->pump($pump->refresh())], 201);
    }

    public function updatePump(UpdatePumpRequest $request, Pump $fuelPump): JsonResponse
    {
        $fuelPump->fill($request->validated())->save();

        return response()->json(['data' => $this->present->pump($fuelPump->refresh())]);
    }

    public function archivePump(ArchivePumpRequest $request, Pump $fuelPump): JsonResponse
    {
        if (Nozzle::query()->where('pump_id', $fuelPump->id)->whereNull('archived_at')->exists()) {
            throw FuelError::make(409, 'pump_in_use');
        }

        $fuelPump->archive();

        return response()->json(['data' => $this->present->pump($fuelPump->refresh())]);
    }

    public function nozzles(ListNozzlesRequest $request): JsonResponse
    {
        $query = $this->access->scopeStations(Nozzle::query(), $request->user(), 'fuel.setup.view')->orderBy('location_id')->orderBy('pump_id')->orderBy('code');
        $request->boolean('archived') ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        foreach (['location_id', 'pump_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->validated($filter));
            }
        }

        return Paged::all($query, fn (Nozzle $n) => $this->present->nozzle($n));
    }

    public function storeNozzle(StoreNozzleRequest $request): JsonResponse
    {
        $pump = Pump::query()->findOrFail($request->validated('pump_id'));
        $tank = Tank::query()->findOrFail($request->validated('tank_id'));

        if ($tank->station_id !== $pump->station_id) {
            throw FuelError::make(422, 'nozzle_tank_station', field: 'tank_id');
        }

        try {
            $nozzle = Nozzle::create([
                'company_id' => $pump->company_id, 'branch_id' => $pump->branch_id, 'location_id' => $pump->location_id, 'station_id' => $pump->station_id,
                'pump_id' => $pump->id, 'tank_id' => $tank->id, 'code' => $request->validated('code'),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw FuelError::make(422, 'code_used', field: 'code');
        }

        return response()->json(['data' => $this->present->nozzle($nozzle->refresh())], 201);
    }

    public function updateNozzle(UpdateNozzleRequest $request, Nozzle $fuelNozzle): JsonResponse
    {
        if ($request->filled('tank_id') && $request->validated('tank_id') !== $fuelNozzle->tank_id) {
            $tank = Tank::query()->findOrFail($request->validated('tank_id'));

            if ($tank->station_id !== $fuelNozzle->station_id) {
                throw FuelError::make(422, 'nozzle_tank_station', field: 'tank_id');
            }

            if (ShiftNozzle::query()->where('nozzle_id', $fuelNozzle->id)->where('is_open', true)->exists()) {
                throw FuelError::make(409, 'nozzle_in_use');
            }

            $fuelNozzle->tank_id = $tank->id;
        }

        $fuelNozzle->save();

        return response()->json(['data' => $this->present->nozzle($fuelNozzle->refresh())]);
    }

    public function archiveNozzle(ArchiveNozzleRequest $request, Nozzle $fuelNozzle): JsonResponse
    {
        if (ShiftNozzle::query()->where('nozzle_id', $fuelNozzle->id)->where('is_open', true)->exists()) {
            throw FuelError::make(409, 'nozzle_in_use');
        }

        $fuelNozzle->archive();

        return response()->json(['data' => $this->present->nozzle($fuelNozzle->refresh())]);
    }
}
