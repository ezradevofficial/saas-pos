<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Cash rounding (CUR-01): the smallest amount a till hands over, in minor
 * units (CDF 50). The tenant's setting, else 1 (no cash rounding).
 */
class CashRounding
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function step(string $code): int
    {
        if ($this->tenants->id() === null) {
            return 1;
        }

        return (int) (TenantCurrency::query()->where('code', $code)->value('cash_rounding_minor') ?? 1);
    }

    /** $minor (exact, possibly fractional) rounded to a multiple of the currency's step. */
    public function round(BigDecimal|BigInteger|string $minor, string $code, RoundingMode $mode): Money
    {
        $step = $this->step($code);
        $steps = BigDecimal::of($minor)->dividedBy($step, 0, $mode);

        return Money::ofMinor((string) $steps->multipliedBy($step), $code);
    }
}
