<?php

namespace Tests\Concerns;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * CUR-03: rates and currencies for exchange-rate tests. Use with
 * BuildsOrganisation; methods enter the owner's tenant themselves.
 */
trait BuildsExchangeRates
{
    /** A DR Congo set-up: Acme based in USD, CDF active with cash rounding 50. */
    protected function congoCurrencies(): void
    {
        $this->inTenant(function () {
            $this->acme->forceFill(['base_currency' => 'USD', 'country' => 'CD'])->save();
            app(TenantCurrencies::class)->provisionFor($this->acme);
            TenantCurrency::where('code', 'CDF')->update(['cash_rounding_minor' => 50]);
        });
    }

    protected function rate(string $base, string $quote, string $mid, string $kind = 'shop', string $at = '-1 hour', array $extra = [], ?Company $company = null): ExchangeRate
    {
        return $this->inTenant(fn () => ExchangeRate::create([
            'company_id' => ($company ?? $this->acme)->id,
            'base' => $base,
            'quote' => $quote,
            'kind' => $kind,
            'mid' => $mid,
            'effective_at' => CarbonImmutable::parse($at),
            'source' => $kind === 'shop' ? 'manual' : 'test',
        ] + $extra));
    }
}
