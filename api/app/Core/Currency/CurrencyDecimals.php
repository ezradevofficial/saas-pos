<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\TenantContext;

/**
 * How many decimals a currency has (CUR-01): the current tenant's setting
 * when it uses the currency, else the catalogue default (ICU, CDF => 0),
 * else 2.
 */
class CurrencyDecimals
{
    public const FALLBACK = 2;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Currencies $catalogue,
    ) {}

    public function for(string $code): int
    {
        if ($this->tenants->id() !== null) {
            $decimals = TenantCurrency::query()->where('code', $code)->value('decimals');

            if ($decimals !== null) {
                return (int) $decimals;
            }
        }

        return $this->catalogue->find($code)['default_decimals'] ?? self::FALLBACK;
    }
}
