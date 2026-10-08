<?php

namespace App\Core\Sync\Sources;

use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * MD-03, NFR-04: the device's company's active price lists (currency,
 * tax-inclusive or not, the default per currency). Item prices are not
 * stored anywhere yet (a gap reported in phase 4): when they are, they
 * sync as their own incremental entity.
 */
class PriceListSource implements SnapshotSource
{
    public function key(): string
    {
        return 'price_lists';
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
        return PriceList::query()
            ->where('company_id', $scope->companyId())
            ->whereNull('archived_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'currency', 'tax_inclusive', 'is_default'])
            ->map(fn (PriceList $list) => [
                'id' => $list->id,
                'name' => $list->name,
                'currency' => $list->currency,
                'tax_inclusive' => (bool) $list->tax_inclusive,
                'is_default' => (bool) $list->is_default,
            ])
            ->values()
            ->all();
    }
}
