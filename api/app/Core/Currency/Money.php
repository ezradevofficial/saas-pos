<?php

namespace App\Core\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;

/**
 * An immutable amount in minor units of one currency (ADR 003, CUR-01).
 *
 * Arithmetic is integer only (brick/math), so amounts beyond 2^63 (large
 * CDF totals) stay exact. Multiplication rounds once, explicitly, to the
 * minor unit (HALF_UP unless told otherwise). Combining two currencies
 * throws CurrencyMismatch. JSON: {"amount_minor": "12345", "currency": "KES"},
 * the amount as a string so clients never parse it into a float.
 */
final class Money implements JsonSerializable
{
    private function __construct(
        private readonly BigInteger $minor,
        private readonly string $currency,
    ) {}

    public static function ofMinor(int|string $minor, string $currency): self
    {
        if (is_string($minor) && preg_match('/^-?\d+$/', $minor) !== 1) {
            throw new InvalidArgumentException("Minor amount [{$minor}] is not an integer.");
        }

        return new self(BigInteger::of($minor), self::code($currency));
    }

    /**
     * A decimal string in major units ("12450.00", "135000"). More decimals
     * than the currency has are refused, never rounded away.
     */
    public static function parse(string $decimal, string $currency, CurrencyDecimals $decimals): self
    {
        $currency = self::code($currency);

        if (preg_match('/^-?\d+(\.\d+)?$/', $decimal) !== 1) {
            throw new InvalidArgumentException("Amount [{$decimal}] is not a decimal number.");
        }

        try {
            $minor = BigDecimal::of($decimal)
                ->toScale($decimals->for($currency), RoundingMode::Unnecessary)
                ->getUnscaledValue();
        } catch (MathException) {
            throw new InvalidArgumentException("Amount [{$decimal}] has more decimals than {$currency} allows.");
        }

        return new self($minor, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor->plus($other->minor), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor->minus($other->minor), $this->currency);
    }

    /** Multiply by a decimal factor (a rate, a percentage), rounded once to the minor unit. */
    public function multiply(string|int $factor, RoundingMode $rounding = RoundingMode::HalfUp): self
    {
        if (is_string($factor) && preg_match('/^-?\d+(\.\d+)?$/', $factor) !== 1) {
            throw new InvalidArgumentException("Factor [{$factor}] is not a decimal number.");
        }

        return new self(
            $this->minor->toBigDecimal()->multipliedBy($factor)->toScale(0, $rounding)->getUnscaledValue(),
            $this->currency,
        );
    }

    /**
     * Split by whole-number ratios without losing a minor unit: each part
     * gets its floored share, then the leftover units go one each to the
     * parts with the largest fractional remainder (ties to the earlier
     * part). A zero ratio never receives a unit. The parts always sum to
     * this amount; a negative amount mirrors the positive split.
     *
     * @param  list<int|string>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('Allocate needs at least one ratio.');
        }

        $weights = [];
        foreach (array_values($ratios) as $ratio) {
            if (! (is_int($ratio) || (is_string($ratio) && preg_match('/^\d+$/', $ratio) === 1)) || (int) $ratio < 0) {
                throw new InvalidArgumentException('Ratios must be whole numbers of zero or more.');
            }
            $weights[] = BigInteger::of($ratio);
        }

        $sum = BigInteger::sum(...$weights);
        if ($sum->isZero()) {
            throw new InvalidArgumentException('At least one ratio must be above zero.');
        }

        $negative = $this->minor->isNegative();
        $total = $this->minor->abs();

        $shares = [];
        $remainders = [];
        $allocated = BigInteger::zero();

        foreach ($weights as $i => $weight) {
            [$share, $remainder] = $total->multipliedBy($weight)->quotientAndRemainder($sum);
            $shares[$i] = $share;
            $remainders[$i] = $remainder;
            $allocated = $allocated->plus($share);
        }

        // Fewer leftover units than parts with a non-zero remainder.
        $left = $total->minus($allocated)->toInt();
        $order = array_keys($remainders);
        usort($order, fn (int $a, int $b) => $remainders[$b]->compareTo($remainders[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, $left) as $i) {
            $shares[$i] = $shares[$i]->plus(1);
        }

        return array_map(
            fn (BigInteger $share) => new self($negative ? $share->negated() : $share, $this->currency),
            $shares,
        );
    }

    public function isZero(): bool
    {
        return $this->minor->isZero();
    }

    public function isNegative(): bool
    {
        return $this->minor->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor->isEqualTo($other->minor);
    }

    /** The amount in minor units, as a string of digits. */
    public function minor(): string
    {
        return (string) $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** "12450.00" for KES, "135000" for CDF (decimals: tenant, then catalogue). */
    public function toDecimalString(?CurrencyDecimals $decimals = null): string
    {
        $decimals ??= app(CurrencyDecimals::class);

        return (string) BigDecimal::ofUnscaledValue($this->minor, $decimals->for($this->currency));
    }

    /** @return array{amount_minor: string, currency: string} */
    public function jsonSerialize(): array
    {
        return ['amount_minor' => $this->minor(), 'currency' => $this->currency];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    private static function code(string $currency): string
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Currency [{$currency}] is not an ISO 4217 code.");
        }

        return $currency;
    }
}
