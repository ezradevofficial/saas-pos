<?php

namespace Modules\FixedAssets\Support;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * CUR-01, CUR-03, CUR-04: currencies an amount may be in (active in the
 * tenant), the company's rate on a date (end of that day in its time zone)
 * frozen as a snapshot, and amounts converted to base with it (half up). A
 * missing rate refuses with `rate_unavailable`, naming the pair and date.
 */
class Rates
{
    public function __construct(private readonly Converter $converter) {}

    public function currency(string $code, string $field = 'currency'): string
    {
        $code = strtoupper($code);

        if (! TenantCurrency::query()->where('code', $code)->where('active', true)->exists()) {
            throw FaError::make('currency_inactive', ['currency' => $code], $field);
        }

        return $code;
    }

    public function fx(Company $company, string $currency, CarbonImmutable|string $date, string $field = 'currency'): FxSnapshot
    {
        if ($currency === $company->base_currency) {
            return FxSnapshot::identity($currency);
        }

        $day = $date instanceof CarbonImmutable ? $date->toDateString() : substr($date, 0, 10);

        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, CarbonImmutable::parse($day, $company->timezone ?: 'UTC')->endOfDay())['snapshot'];
        } catch (RateUnavailable) {
            throw FaError::make('rate_unavailable', ['pair' => "{$currency}/{$company->base_currency}", 'date' => $day], $field);
        }
    }

    public static function toBase(FxSnapshot $fx, int|string $minor, string $currency): int
    {
        return $fx->isIdentity() ? (int) $minor : (int) $fx->convert(Money::ofMinor((string) $minor, $currency))->minor();
    }
}
