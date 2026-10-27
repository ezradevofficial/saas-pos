<?php

namespace Modules\Restaurant\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Orders\OrderView;
use Modules\Restaurant\RestaurantServiceProvider;

/** REST-04, REST-17, NFR-04: the open orders and tabs of the till's location, with lines and ticket states. */
class OrderSource implements SnapshotSource
{
    public function key(): string
    {
        return 'restaurant_orders';
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
        $orders = Order::query()->where('location_id', $scope->location->id)->where('status', 'open')->orderBy('opened_at')->orderBy('id')->get();

        return array_map(function (array $row) {
            $row['tickets'] = array_map(fn (array $t) => ['id' => $t['id'], 'station_id' => $t['station_id'], 'status' => $t['status'], 'kind' => $t['kind'], 'number' => $t['number']], $row['tickets']);
            unset($row['settlements']);

            return $row;
        }, app(OrderView::class)->many($orders));
    }
}
