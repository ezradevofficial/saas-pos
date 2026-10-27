<?php

namespace Modules\FuelStation\Sync;

use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;
use Modules\FuelStation\FuelStationServiceProvider;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-20, NFR-04: the company's fleet vehicles (registration, customer,
 * fuel type, daily limit, blocked or not), so the till finds a vehicle by
 * its registration offline. Only at a station; no personal data beyond the
 * customer party id.
 */
class VehicleSource implements SnapshotSource
{
    public function key(): string
    {
        return 'fuel_vehicles';
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
        if (! Station::query()->where('location_id', $scope->location->id)->whereNull('archived_at')->exists()) {
            return [];
        }

        return Vehicle::query()->where('company_id', $scope->companyId())->whereNull('archived_at')->orderBy('registration')->orderBy('id')->get()->map(fn (Vehicle $v) => [
            'id' => $v->id,
            'party_id' => $v->party_id,
            'registration' => $v->registration,
            'label' => $v->label,
            'fuel_type' => $v->fuel_type,
            'daily_litre_limit' => Litres::show($v->daily_litre_limit),
            'status' => $v->status,
        ])->all();
    }
}
