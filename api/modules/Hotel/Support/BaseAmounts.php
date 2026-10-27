<?php

namespace Modules\Hotel\Support;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * CUR-04: a folio line in a currency other than the company's base keeps the
 * rate used and its base-currency amount. A line in the base currency keeps
 * the identity rate. When no rate exists the line is kept without one (the
 * folio is money owed, not refused) and Accounting parks its posting.
 */
class BaseAmounts
{
    public function __construct(private readonly Converter $converter) {}

    /** @return array{fx: ?FxSnapshot, base_amount_minor: ?int} */
    public function for(string $companyId, string $currency, int $amount, CarbonImmutable $at): array
    {
        $company = Company::query()->findOrFail($companyId);

        if ($currency === $company->base_currency) {
            return ['fx' => FxSnapshot::identity($currency), 'base_amount_minor' => $amount];
        }

        try {
            $converted = $this->converter->toBase(Money::ofMinor($amount, $currency), $company, $at);
        } catch (RateUnavailable) {
            return ['fx' => null, 'base_amount_minor' => null];
        }

        return ['fx' => $converted['snapshot'], 'base_amount_minor' => (int) $converted['base']->minor()];
    }
}
