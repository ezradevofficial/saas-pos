<?php

namespace App\Core\Tenancy;

use App\Core\Currency\TenantCurrencies;
use App\Core\Onboarding\TenantSlugs;
use App\Core\Tenancy\Events\CompanyCreated;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Tenant;

/**
 * A new tenant's skeleton (TEN-02..TEN-05, ONB-01): the tenant (and its
 * subdomain when asked), one company in the chosen country (its currency and time
 * zone, CUR-01, and the country pack's taxes, CP-01), a main branch and a
 * main outlet, named in the chosen language. Runs in the caller's
 * transaction and inside the new tenant's context (self sign-up, AUTH-01;
 * assisted onboarding, ONB-08). The caller creates the owner and raises
 * TenantProvisioned.
 */
class TenantProvisioner
{
    /** Per-country company defaults: the country sets the currency and time zone (ONB-01). */
    public const COUNTRIES = [
        'KE' => ['currency' => 'KES', 'timezone' => 'Africa/Nairobi'],
        'CD' => ['currency' => 'USD', 'timezone' => 'Africa/Kinshasa'],
    ];

    public function __construct(
        private readonly TenantCurrencies $currencies,
        private readonly TenantSlugs $slugs,
    ) {}

    /** @return array{tenant: Tenant, company: Company} */
    public function provision(string $tenantId, string $businessName, string $country, string $locale, bool $withSubdomain = false): array
    {
        $defaults = self::COUNTRIES[$country];

        $tenant = Tenant::provision([
            'id' => $tenantId,
            'name' => $businessName,
            'default_locale' => $locale,
        ]);
        // ONB-01: a self sign-up gets its subdomain once the owner verifies (no squatting).
        if ($withSubdomain) {
            $this->slugs->assign($tenant, $businessName);
        }

        $company = Company::create([
            'name' => $businessName,
            'legal_name' => $businessName,
            'country' => $country,
            'base_currency' => $defaults['currency'],
            'fiscal_year_start_month' => 1,
            'timezone' => $defaults['timezone'],
        ]);

        // CUR-01: KE uses KES and USD; CD uses USD and CDF.
        $this->currencies->provisionFor($company);
        // CP-01: the country pack's tax codes.
        event(new CompanyCreated($company));

        $branch = $company->branches()->create([
            'name' => __('core.defaults.branch', [], $locale),
            'code' => 'MAIN',
        ]);

        $branch->locations()->create([
            'name' => __('core.defaults.location', [], $locale),
            'type' => 'outlet',
        ]);

        return ['tenant' => $tenant, 'company' => $company];
    }
}
