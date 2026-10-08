<?php

namespace App\Core\Sync\Sources;

use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * MD-03, NFR-04: tax categories the device's company can use (shared or
 * its own, active), each with its default tax code for that company (null:
 * none, so its items cannot be sold).
 */
class TaxCategorySource implements SnapshotSource
{
    public function key(): string
    {
        return 'tax_categories';
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
        $codes = TaxCategoryCode::query()->where('company_id', $scope->companyId())->pluck('tax_code_id', 'tax_category_id');

        return TaxCategory::query()
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $scope->companyId()))
            ->whereNull('archived_at')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (TaxCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'tax_code_id' => $codes->get($category->id),
            ])
            ->values()
            ->all();
    }
}
