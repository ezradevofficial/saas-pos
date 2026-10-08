<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\ExchangeRate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An exchange rate (CUR-03): 1 $base = $mid $quote, with optional buy and
 * sell, 8 decimals (numeric(18,8)). $side is the side a caller asked for
 * (ExchangeRates::current); value() returns it, falling back to mid when
 * the rate has no buy or sell. $inverted marks a rate computed from the
 * stored inverse pair.
 */
final class Rate
{
    public const SCALE = 8;

    public const KINDS = ['reference', 'shop'];

    public const SIDES = ['mid', 'buy', 'sell'];

    public function __construct(
        public readonly string $base,
        public readonly string $quote,
        public readonly string $mid,
        public readonly ?string $buy,
        public readonly ?string $sell,
        public readonly string $kind,
        public readonly CarbonImmutable $effectiveAt,
        public readonly string $source = '',
        public readonly ?string $id = null,
        public readonly string $side = 'mid',
        public readonly bool $inverted = false,
    ) {
        if ($base === $quote) {
            throw new InvalidArgumentException("A rate needs two currencies, got {$base}/{$quote}.");
        }

        if (! in_array($side, self::SIDES, true)) {
            throw new InvalidArgumentException("Unknown rate side [{$side}].");
        }

        if (BigDecimal::of($mid)->isNegativeOrZero()) {
            throw new InvalidArgumentException('A rate must be above zero.');
        }
    }

    public static function fromModel(ExchangeRate $rate, string $side = 'mid'): self
    {
        return new self(
            base: $rate->base,
            quote: $rate->quote,
            mid: self::normalise($rate->mid),
            buy: $rate->buy === null ? null : self::normalise($rate->buy),
            sell: $rate->sell === null ? null : self::normalise($rate->sell),
            kind: $rate->kind,
            effectiveAt: CarbonImmutable::parse($rate->effective_at)->utc(),
            source: (string) $rate->source,
            id: $rate->id,
            side: $side,
        );
    }

    /** "2850" or "2850.5" as "2850.00000000"; more than 8 decimals round half up. */
    public static function normalise(string|int $value): string
    {
        return (string) BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /** "USD/CDF" */
    public function pair(): string
    {
        return "{$this->base}/{$this->quote}";
    }

    /** The side asked for; mid when the rate has no buy or sell. */
    public function value(): string
    {
        return match ($this->side) {
            'buy' => $this->buy ?? $this->mid,
            'sell' => $this->sell ?? $this->mid,
            default => $this->mid,
        };
    }

    public function withSide(string $side): self
    {
        return new self($this->base, $this->quote, $this->mid, $this->buy, $this->sell, $this->kind, $this->effectiveAt, $this->source, $this->id, $side, $this->inverted);
    }

    /**
     * The same rate for quote/base: mid inverted, buy and sell swapped and
     * inverted (the shop buys the quote at 1/sell), 8 decimals half up.
     */
    public function invert(): self
    {
        $inverse = fn (?string $value) => $value === null ? null : (string) BigDecimal::one()->dividedBy($value, self::SCALE, RoundingMode::HalfUp);

        return new self(
            base: $this->quote,
            quote: $this->base,
            mid: $inverse($this->mid),
            buy: $inverse($this->sell),
            sell: $inverse($this->buy),
            kind: $this->kind,
            effectiveAt: $this->effectiveAt,
            source: $this->source,
            id: $this->id,
            side: $this->side,
            inverted: ! $this->inverted,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'pair' => $this->pair(),
            'base' => $this->base,
            'quote' => $this->quote,
            'kind' => $this->kind,
            'buy' => $this->buy,
            'sell' => $this->sell,
            'mid' => $this->mid,
            'side' => $this->side,
            'value' => $this->value(),
            'inverted' => $this->inverted,
            'effective_at' => $this->effectiveAt->toIso8601String(),
            'source' => $this->source,
        ];
    }
}
