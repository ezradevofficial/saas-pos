<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\StrappingChart;
use Modules\FuelStation\Models\StrappingPoint;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-05, FUEL-06, FUEL-23, NFR-04: the station's tanks with their
 * calibration charts (the three latest: the chart in force is the latest one
 * that started before the dip), so the attendant sees the litres of a dip
 * offline. The server converts again when the dip arrives and wins.
 */
class TankSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_tanks';
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
        $tanks = Tank::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->orderBy('code')->orderBy('id')->get();
        $charts = StrappingChart::query()->whereIn('tank_id', $tanks->pluck('id'))->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('tank_id')->map(fn ($c) => $c->take(3));
        $points = StrappingPoint::query()->whereIn('chart_id', $charts->flatten()->pluck('id'))->orderBy('dip_cm')->get()->groupBy('chart_id');

        return $tanks->map(fn (Tank $t) => [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            'product_id' => $t->product_id,
            'capacity_litres' => Litres::show($t->capacity_litres),
            'strapping' => ($charts->get($t->id) ?? collect())->map(fn (StrappingChart $c) => [
                'id' => $c->id,
                'effective_from' => $c->effective_from->utc()->format('Y-m-d\TH:i:s\Z'),
                'points' => ($points->get($c->id) ?? collect())->map(fn (StrappingPoint $p) => [(string) $p->dip_cm, Litres::show($p->litres)])->values()->all(),
            ])->values()->all(),
        ])->all();
    }
}
