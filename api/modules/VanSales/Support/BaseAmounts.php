<?php

namespace Modules\VanSales\Support;

use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonInterface;

/** CUR-04: an amount in the company's base currency at the rate in force at $at; null when no rate is stored. */
class BaseAmounts
{
    public function __construct(private readonly ExchangeRates $rates) {}

    public function minor(Company $company, string $currency, int $minor, CarbonInterface $at): ?int
    {
        if ($minor === 0 || $currency === $company->base_currency) {
            return $minor;
        }

        try {
            $snapshot = FxSnapshot::fromRate($this->rates->stored($company, $currency, (string) $company->base_currency, $at));

            return (int) $snapshot->convert(Money::ofMinor((string) $minor, $currency))->minor();
        } catch (RateUnavailable) {
            return null;
        }
    }
}
