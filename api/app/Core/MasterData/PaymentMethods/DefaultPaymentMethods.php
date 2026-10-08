<?php

namespace App\Core\MasterData\PaymentMethods;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Localisation\TenantLocale;
use App\Core\Tenancy\Models\Company;

/**
 * MD-04: the payment methods a company starts with. Cash in each currency
 * of its country's default set that is active in the tenant (KE: KES, USD;
 * CD: USD, CDF), switched on when seeded at company creation and off
 * when added later (a back-fill must not open new tills); the country's mobile money wallets and a
 * card method, switched off until their provider is configured. Named
 * once in the tenant's language (TenantLocale) from
 * `core.payment_method.defaults.*`; the business can rename them after.
 *
 * Idempotent: a cash currency or provider the company has ever had,
 * archived included, is never added again. Call inside the tenant's
 * context and a transaction; the company row is locked so two seeders do
 * not add the same entry.
 */
class DefaultPaymentMethods
{
    public function __construct(
        private readonly PaymentProviders $providers,
        private readonly TenantLocale $locale,
    ) {}

    /**
     * Seed $company's missing defaults; returns how many were created.
     * $activateCash: true at company creation; false for a back-fill.
     */
    public function seed(Company $company, bool $activateCash = true): int
    {
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

        $existing = PaymentMethod::query()->where('company_id', $company->id)->get(['type', 'currency', 'provider']);
        $cash = $existing->where('type', 'cash')->pluck('currency')->all();
        $providers = $existing->pluck('provider')->filter()->all();
        $active = TenantCurrency::query()->where('active', true)->pluck('code')->all();
        $position = (int) PaymentMethod::query()->where('company_id', $company->id)->max('position');
        $locale = $this->locale->current();
        $created = 0;

        foreach (array_keys(TenantCurrencies::COUNTRY_DEFAULTS[$company->country] ?? []) as $currency) {
            if (in_array($currency, $cash, true) || ! in_array($currency, $active, true)) {
                continue;
            }

            PaymentMethod::create([
                'company_id' => $company->id,
                'type' => 'cash',
                'currency' => $currency,
                'name' => __('core.payment_method.defaults.cash', ['currency' => $currency], $locale),
                'active' => $activateCash,
                'position' => ++$position,
            ]);
            $created++;
        }

        foreach ($this->providers->forCountry($company->country) as $provider) {
            if (in_array($provider, $providers, true)) {
                continue;
            }

            PaymentMethod::create([
                'company_id' => $company->id,
                'type' => $this->providers->typeOf($provider),
                'provider' => $provider,
                'name' => __("core.payment_method.defaults.{$provider}", [], $locale),
                'active' => false,
                'position' => ++$position,
            ]);
            $created++;
        }

        return $created;
    }
}
