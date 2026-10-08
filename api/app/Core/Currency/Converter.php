<?php

namespace App\Core\Currency;

use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Currency conversion (CUR-03, CUR-04, CUR-08). A rate converts in both
 * directions: an amount in its base is multiplied by value(), an amount in
 * its quote divided by it. The result is rounded once, to the target's
 * minor unit, HALF_UP unless told otherwise.
 *
 * Rounding drift (ADR 003): a document converts each line, rounds it, then
 * sums the rounded lines. The sum may differ from converting the document
 * total by at most half a minor unit per line; documents store the summed
 * line amounts, never a recomputed total.
 */
class Converter
{
    /** Exact quotients carry this many decimals of a minor unit before the final rounding. */
    public const EXACT_SCALE = 20;

    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly ExchangeRates $rates,
    ) {}

    public function convert(Money $money, string $to, Rate $rate, RoundingMode $rounding = RoundingMode::HalfUp): Money
    {
        if ($money->currency() === $to) {
            return $money;
        }

        // Divisions carry 20 decimals before this one rounding (as FxSnapshot::convert).
        $minor = $this->exact(BigDecimal::of($money->minor()), $money->currency(), $to, $rate)->toScale(0, $rounding);

        return Money::ofMinor((string) $minor, $to);
    }

    /**
     * $minor units of $from (possibly fractional) in minor units of $to,
     * before rounding (20 decimals when divided). $rate may be null only
     * when the currencies are the same.
     */
    public function exact(BigDecimal $minor, string $from, string $to, ?Rate $rate): BigDecimal
    {
        if ($from === $to) {
            return $minor;
        }

        if ($rate === null) {
            throw new InvalidArgumentException("Converting {$from} to {$to} needs a rate.");
        }

        [$numerator, $divisor] = $this->terms($minor, $from, $to, $rate);

        return $divisor === null ? $numerator : $numerator->dividedBy($divisor, self::EXACT_SCALE, RoundingMode::HalfUp);
    }

    /**
     * CUR-04: $money in the company's base currency, with the rate used
     * (the current shop or reference rate in its stored direction). Posting
     * modules call BaseCurrencyLock::lock() first and store both results.
     *
     * @return array{base: Money, snapshot: FxSnapshot}
     */
    public function toBase(Money $money, Company $company, ?DateTimeInterface $at = null): array
    {
        $base = $company->base_currency;

        if ($money->currency() === $base) {
            return ['base' => $money, 'snapshot' => FxSnapshot::identity($base)];
        }

        $snapshot = FxSnapshot::fromRate($this->rates->stored($company, $money->currency(), $base, ExchangeRates::utc($at)));

        return ['base' => $snapshot->convert($money), 'snapshot' => $snapshot];
    }

    /**
     * $minor scaled to $to's decimals (times the rate when $from is the
     * rate's base), and the divisor when $from is its quote.
     *
     * @return array{0: BigDecimal, 1: ?string}
     */
    private function terms(BigDecimal $minor, string $from, string $to, Rate $rate): array
    {
        $scaled = $minor->withPointMovedRight($this->decimals->for($to) - $this->decimals->for($from));

        return match (true) {
            $from === $rate->base && $to === $rate->quote => [$scaled->multipliedBy($rate->value()), null],
            $from === $rate->quote && $to === $rate->base => [$scaled, $rate->value()],
            default => throw new InvalidArgumentException("A {$rate->pair()} rate cannot convert {$from} to {$to}."),
        };
    }
}
