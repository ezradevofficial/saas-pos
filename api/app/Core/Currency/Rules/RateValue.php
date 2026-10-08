<?php

namespace App\Core\Currency\Rules;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An exchange rate typed by a person (CUR-03): a decimal string above zero
 * that fits numeric(18,8), at most 10 digits before the point and 8 after.
 * Floats are refused, as for MoneyAmount.
 */
final class RateValue implements ValidationRule
{
    private const PATTERN = '/^\d{1,10}(\.\d{1,8})?\z/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1 || BigDecimal::of($value)->isZero()) {
            $fail('core.exchange_rate.invalid_rate')->translate();
        }
    }
}
