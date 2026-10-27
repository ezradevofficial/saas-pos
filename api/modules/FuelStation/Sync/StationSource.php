<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Station;

/**
 * FUEL-02, FUEL-22, NFR-04: whether the till's location is a forecourt
 * (`fuel_station`: one row, its currency and the tolerances the till shows
 * its attendants). A location that is not a station gets no rows, and none
 * of the other fuel entities either.
 */
class StationSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_station';
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
        $station = Station::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->first();

        if ($station === null) {
            return [];
        }

        $settings = Settings::for($station->company_id);

        return [[
            'id' => $station->id,
            'currency' => $station->currency,
            'litre_tolerance' => (string) $settings->litre_tolerance,
            'cash_variance_tolerance_minor' => (string) $settings->cash_variance_tolerance_minor,
        ]];
    }
}
