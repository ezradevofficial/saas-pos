<?php

namespace Modules\Restaurant\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use App\Core\Tenancy\Models\Tenant;
use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\RestaurantServiceProvider;

/** REST-13, NFR-04: the company's service charge, its items and the tip presets, with the tenant's till mode. One row, id the company's. */
class SettingsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'restaurant_settings';
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
        $charges = app(Charges::class)->for($scope->company->id);

        return [[
            'id' => $scope->company->id,
            'pos_mode' => Tenant::query()->whereKey($scope->tenantId)->first()?->settings['pos_mode'] ?? null,
            'service_charge_percent' => $charges['percent'] ?? '0.0000',
            'service_charge_item_id' => $charges['service_item_id'],
            'tip_item_id' => $charges['tip_item_id'],
            'tip_presets' => $charges['tip_presets'],
        ]];
    }
}
