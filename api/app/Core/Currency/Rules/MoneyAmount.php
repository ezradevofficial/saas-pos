<?php

namespace App\Core\Currency\Rules;

use App\Core\Currency\CurrencyDecimals;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A money amount typed by a person, in major units (ADR 003): a decimal
 * string such as "12450.50" (or a whole number), with at most as many
 * decimals as the currency has (CurrencyDecimals: tenant, then catalogue),
 * optionally within [min, max]. Floats are refused, so a value never
 * passes through binary floating point. Convert the validated value with
 * Money::parse, which stays strict.
 *
 *     'amount' => ['required', MoneyAmount::fromField('currency')->min('0')],
 *     'price' => ['required', MoneyAmount::in('CDF')->min('1')],
 *
 * With fromField, a missing or malformed currency is left to that field's
 * own rules (no second error here).
 */
final class MoneyAmount implements DataAwareRule, ValidationRule
{
    private const PATTERN = '/^-?\d+(\.\d+)?$/';

    private array $data = [];

    private ?string $min = null;

    private ?string $max = null;

    private function __construct(
        private readonly ?string $currency,
        private readonly ?string $currencyField,
    ) {}

    /** The amount is in a fixed currency. */
    public static function in(string $currency): self
    {
        return new self($currency, null);
    }

    /** The amount is in the currency given by another input field (dot notation). */
    public static function fromField(string $field): self
    {
        return new self(null, $field);
    }

    public function min(string|int $amount): self
    {
        $this->min = (string) BigDecimal::of($amount);

        return $this;
    }

    public function max(string|int $amount): self
    {
        $this->max = (string) BigDecimal::of($amount);

        return $this;
    }

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            $fail('core.money.invalid')->translate();

            return;
        }

        $currency = $this->currency ?? data_get($this->data, $this->currencyField);

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return;
        }

        $amount = BigDecimal::of($value);
        $decimals = app(CurrencyDecimals::class)->for($currency);

        if ($amount->getScale() > $decimals && ! $amount->isEqualTo($amount->toScale($decimals, RoundingMode::Down))) {
            $fail('core.money.too_many_decimals')->translate(['currency' => $currency, 'decimals' => $decimals]);

            return;
        }

        if ($this->min !== null && $amount->isLessThan($this->min)) {
            $fail('core.money.min')->translate(['min' => $this->min, 'currency' => $currency]);
        } elseif ($this->max !== null && $amount->isGreaterThan($this->max)) {
            $fail('core.money.max')->translate(['max' => $this->max, 'currency' => $currency]);
        }
    }
}
