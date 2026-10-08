<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\Models\Company;

/**
 * Provisions the tenant's currencies (CUR-01). A company brings its
 * country's currencies (KE: KES and USD; CD: USD and CDF, CDF cash rounding
 * 50) and its base currency. Rows the tenant already has are never
 * overwritten, except that the base currency is reactivated. Runs in the
 * tenant's context: on sign-up, on company creation, when a base currency
 * changes, and from `currencies:sync` for tenants created before CUR-01.
 */
class TenantCurrencies
{
    /** country => code => settings other than the catalogue's decimals */
    public const COUNTRY_DEFAULTS = [
        'KE' => ['KES' => [], 'USD' => []],
        'CD' => ['USD' => [], 'CDF' => ['cash_rounding_minor' => 50]],
    ];

    public function __construct(private readonly Currencies $catalogue) {}

    public function provisionFor(Company $company): void
    {
        foreach (self::COUNTRY_DEFAULTS[$company->country] ?? [] as $code => $settings) {
            $this->activate($code, $settings, reactivate: false);
        }

        $this->activate($company->base_currency);
    }

    /**
     * The tenant's row for $code, created with the catalogue's decimals and
     * $settings when missing; an existing row is only reactivated.
     *
     * @param  array{cash_rounding_minor?: int}  $settings
     */
    public function activate(string $code, array $settings = [], bool $reactivate = true): TenantCurrency
    {
        $currency = TenantCurrency::query()->where('code', $code)->first();

        if ($currency === null) {
            return TenantCurrency::create([
                'code' => $code,
                'decimals' => $this->catalogue->find($code)['default_decimals'] ?? CurrencyDecimals::FALLBACK,
                'cash_rounding_minor' => $settings['cash_rounding_minor'] ?? 1,
                'active' => true,
            ]);
        }

        if ($reactivate && ! $currency->active) {
            $currency->active = true;
            $currency->save();
        }

        return $currency;
    }
}
