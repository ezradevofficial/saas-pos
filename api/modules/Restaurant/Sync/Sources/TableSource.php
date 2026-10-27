<?php

namespace Modules\Restaurant\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\RestaurantServiceProvider;

/** REST-02, NFR-04: the floor plan of the till's location (tables with their area and manual status). */
class TableSource implements SnapshotSource
{
    public function key(): string
    {
        return 'restaurant_tables';
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
        return Table::query()->join('rest_areas', 'rest_areas.id', '=', 'rest_tables.area_id')
            ->where('rest_tables.location_id', $scope->location->id)->whereNull('rest_tables.archived_at')->whereNull('rest_areas.archived_at')
            ->orderBy('rest_areas.sort')->orderBy('rest_areas.name')->orderBy('rest_tables.sort')->orderBy('rest_tables.name')->orderBy('rest_tables.id')
            ->get(['rest_tables.*', 'rest_areas.name as area_name'])
            ->map(fn ($t) => [
                'id' => $t->id, 'area_id' => $t->area_id, 'area_name' => $t->area_name, 'name' => $t->name, 'seats' => (int) $t->seats,
                'x' => (int) $t->plan_x, 'y' => (int) $t->plan_y, 'width' => (int) $t->plan_width, 'height' => (int) $t->plan_height, 'shape' => $t->shape, 'status' => $t->manual_status,
            ])->all();
    }
}
