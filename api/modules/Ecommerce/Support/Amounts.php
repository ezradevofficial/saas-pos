<?php

namespace Modules\Ecommerce\Support;

use App\Core\Currency\CurrencyDecimals;
use Brick\Math\BigDecimal;

/** An amount for messages: the currency code first, then the value in its decimals ("KES 1,250.00", "CDF 135,000"). */
final class Amounts
{
    public static function show(int|string $minor, string $currency): string
    {
        $decimals = app(CurrencyDecimals::class)->for($currency);
        $value = BigDecimal::ofUnscaledValue((string) $minor, $decimals);

        return $currency.' '.number_format((float) (string) $value, $decimals, '.', ',');
    }
}
