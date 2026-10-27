<?php

namespace Modules\VanSales\Sync\Sources;

use App\Core\Identity\Models\User;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Support\Settings;
use Modules\VanSales\VanSalesServiceProvider;

/** VAN-02, VAN-24, NFR-04: the van whose location the device sits at (none: the device is not a van's and the app stays a plain till). */
class VanSource implements SnapshotSource
{
    public function key(): string
    {
        return 'van_sales_van';
    }

    public function module(): string
    {
        return VanSalesServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $van = self::vanOf($scope);

        if ($van === null) {
            return [];
        }

        $settings = Settings::for($van->company_id);

        return [[
            'id' => $van->id,
            'name' => $van->name,
            'plate' => $van->plate,
            'rep_id' => $van->rep_id,
            'rep_name' => User::query()->whereKey($van->rep_id)->value('name'),
            'location_id' => $van->location_id,
            'gps_checkin' => $settings['gps_checkin'],
            'gps_retention_days' => $settings['gps_retention_days'],
        ]];
    }

    public static function vanOf(DeviceScope $scope): ?Van
    {
        $van = Van::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->first();

        return $van === null || ($van->device_id !== null && $van->device_id !== $scope->device->id) ? null : $van;
    }
}
