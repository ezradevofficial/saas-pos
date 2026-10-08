<?php

namespace App\Core\MasterData\PaymentMethods;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\TenantCurrencies;
use App\Core\Tenancy\Models\Company;

/**
 * MD-04: the payment methods a company starts with. Cash in each currency
 * of its country's default set that is active in the tenant (KE: KES, USD;
 * CD: USD, CDF), switched on; the country's mobile money wallets and a
 * card method, switched off until their provider is configured. Names in
 * English and French from `core.payment_method.defaults.*`.
 *
 * Idempotent: a cash currency or provider the company has ever had,
 * archived included, is never added again. Call inside the tenant's
 * context and a transaction; the company row is locked so two seeders do
 * not add the same entry.
 */
class DefaultPaymentMethods
{
    public function __construct(private readonly PaymentProviders $providers) {}

    /** Seed $company's missing defaults; returns how many were created. */
    public function seed(Company $company): int
    {
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

        $existing = PaymentMethod::query()->where('company_id', $company->id)->get(['type', 'currency', 'provider']);
        $cash = $existing->where('type', 'cash')->pluck('currency')->all();
        $providers = $existing->pluck('provider')->filter()->all();
        $active = TenantCurrency::query()->where('active', true)->pluck('code')->all();
        $position = (int) PaymentMethod::query()->where('company_id', $company->id)->max('position');
        $created = 0;

        foreach (array_keys(TenantCurrencies::COUNTRY_DEFAULTS[$company->country] ?? []) as $currency) {
            if (in_array($currency, $cash, true) || ! in_array($currency, $active, true)) {
                continue;
            }

            PaymentMethod::create([
                'company_id' => $company->id,
                'type' => 'cash',
                'currency' => $currency,
                'name_en' => __('core.payment_method.defaults.cash', ['currency' => $currency], 'en'),
                'name_fr' => __('core.payment_method.defaults.cash', ['currency' => $currency], 'fr'),
                'active' => true,
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
                'name_en' => __("core.payment_method.defaults.{$provider}", [], 'en'),
                'name_fr' => __("core.payment_method.defaults.{$provider}", [], 'fr'),
                'active' => false,
                'position' => ++$position,
            ]);
            $created++;
        }

        return $created;
    }
}
