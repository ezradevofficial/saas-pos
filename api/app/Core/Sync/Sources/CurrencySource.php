<?php

namespace App\Core\Sync\Sources;

use App\Core\Currency\Models\CompanyCurrency;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\Contracts\SnapshotSource;
use App\Core\Sync\DeviceScope;

/**
 * CUR-01, CUR-02, NFR-04: the currencies the tenant has active, keyed by
 * ISO code: decimals (CDF 0, KES and USD 2), cash rounding in minor units
 * (CDF 50: the smallest cash amount handed over), and whether each is the
 * device's company's base or a reporting currency.
 */
class CurrencySource implements SnapshotSource
{
    public function key(): string
    {
        return 'currencies';
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
        $reporting = CompanyCurrency::query()->where('company_id', $scope->companyId())->pluck('position', 'code');

        return TenantCurrency::query()
            ->where('active', true)
            ->orderBy('code')
            ->get(['code', 'decimals', 'cash_rounding_minor'])
            ->map(fn (TenantCurrency $currency) => [
                'id' => $currency->code,
                'code' => $currency->code,
                'decimals' => $currency->decimals,
                'cash_rounding_minor' => (string) $currency->cash_rounding_minor,
                'is_base' => $currency->code === $scope->company->base_currency,
                'reporting_position' => $reporting->get($currency->code),
            ])
            ->values()
            ->all();
    }
}
