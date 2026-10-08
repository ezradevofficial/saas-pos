<?php

namespace App\Core\Sync\Sources;

use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * TEN-03..TEN-05, NFR-04: where the device sits, for the till's header and
 * receipts: the device, its location, branch and company (names, legal
 * name, tax ID, address, country), the time zone sales are dated in (the
 * branch's, else the company's), and the company's base and reporting
 * currencies. One row, id `device`. POS settings (receipt texts, number
 * ranges) are the POS module's own entities.
 */
class SettingsSource implements SnapshotSource
{
    public function key(): string
    {
        return 'settings';
    }

    public function module(): string
    {
        return ModuleRegistry::CORE;
    }

    public function version(): int
    {
        return 1;
    }

    public function rows(DeviceScope $scope): array
    {
        $company = $scope->company;
        $branch = $scope->branch;

        return [[
            'id' => 'device',
            'device' => ['id' => $scope->device->id, 'name' => $scope->device->name],
            'location' => ['id' => $scope->location->id, 'name' => $scope->location->name, 'type' => $scope->location->type],
            'branch' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'address' => (object) ($branch->address ?? []),
            ],
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'legal_name' => $company->legal_name,
                'tax_id' => $company->tax_id,
                'country' => $company->country,
                'address' => (object) ($company->address ?? []),
                'base_currency' => $company->base_currency,
                'reporting_currencies' => CompanyCurrency::query()->where('company_id', $company->id)->orderBy('position')->pluck('code')->all(),
            ],
            'timezone' => $scope->timezone(),
        ]];
    }
}
