<?php

namespace Modules\Restaurant\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\StationRoute;
use Modules\Restaurant\RestaurantServiceProvider;

/** REST-08, NFR-04: the stations of the till's location and the categories routed to each. */
class StationSource implements SnapshotSource
{
    public function key(): string
    {
        return 'restaurant_stations';
    }

    public function module(): string
    {
        return RestaurantServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $stations = Station::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->orderBy('sort')->orderBy('name')->orderBy('id')->get();
        $routes = StationRoute::query()->where('location_id', $scope->location->id)->whereIn('station_id', $stations->pluck('id'))->orderBy('item_category_id')->get()->groupBy('station_id');

        return $stations->map(fn (Station $s) => [
            'id' => $s->id, 'name' => $s->name, 'kind' => $s->kind, 'printer' => $s->printer, 'is_default' => (bool) $s->is_default,
            'category_ids' => ($routes[$s->id] ?? collect())->pluck('item_category_id')->values()->all(),
        ])->all();
    }
}
