<?php

namespace Modules\Salon\Sync\Sources;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\SalonServiceProvider;
use Modules\Salon\Support\Settings;

/** SAL-29, NFR-04: the company's salon rules the till needs offline (the tip and fee items, the slot grid, fees) and the branch's salon floor. */
class SettingsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'salon_settings';
    }

    public function module(): string
    {
        return SalonServiceProvider::MODULE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $settings = Settings::for($scope->companyId());
        $branch = SalonBranch::query()->where('branch_id', $scope->branch->id)->first();

        return [[
            'id' => $scope->companyId(),
            'branch_id' => $scope->branch->id,
            'location_id' => $scope->location->id,
            'timezone' => $scope->timezone(),
            'slot_minutes' => $settings['slot_minutes'],
            'cancel_window_hours' => $settings['cancel_window_hours'],
            'late_cancel_fee_percent' => $settings['late_cancel_fee_percent'],
            'no_show_fee_percent' => $settings['no_show_fee_percent'],
            'deposit_percent' => $settings['deposit_percent'],
            'tip_item_id' => $settings['tip_item_id'],
            'fee_item_id' => $settings['fee_item_id'],
            'online_enabled' => (bool) ($branch?->online_enabled ?? false),
        ]];
    }
}
