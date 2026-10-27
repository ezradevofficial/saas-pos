<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Pump;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-07, FUEL-16, FUEL-22, NFR-04: the station's nozzles with their pump,
 * tank and product and the last accepted meter reading, so the till can
 * check readings (they never go down) and sell by nozzle offline.
 */
class NozzleSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_nozzles';
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
        $nozzles = Nozzle::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->orderBy('id')->get();
        $pumps = Pump::query()->whereKey($nozzles->pluck('pump_id')->unique())->get()->keyBy('id');
        $tanks = Tank::query()->whereKey($nozzles->pluck('tank_id')->unique())->get()->keyBy('id');
        $products = Product::query()->whereKey($tanks->pluck('product_id')->unique())->get()->keyBy('id');

        return $nozzles->map(function (Nozzle $n) use ($pumps, $tanks, $products) {
            $pump = $pumps->get($n->pump_id);
            $tank = $tanks->get($n->tank_id);
            $product = $products->get($tank->product_id);

            return [
                'id' => $n->id,
                'code' => $n->code,
                'pump_id' => $pump->id,
                'pump_code' => $pump->code,
                'pump_name' => $pump->name,
                'tank_id' => $tank->id,
                'tank_code' => $tank->code,
                'product_id' => $product->id,
                'item_id' => $product->item_id,
                'fuel_type' => $product->fuel_type,
                'grade' => $product->grade,
                'last_reading_litres' => Litres::show($n->last_reading_litres),
                'last_reading_at' => $n->last_reading_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        })->sortBy(fn ($r) => $r['pump_code'].'|'.$r['code'], SORT_NATURAL)->values()->all();
    }
}
