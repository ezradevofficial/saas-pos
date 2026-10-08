<?php

namespace App\Core\Currency;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;

/**
 * CUR-04: the rate a document used, frozen on the document and
 * self-describing: 1 `base` = `rate` `quote`, in the direction the rate was
 * stored (never an 8-decimal inverse). `kind` is reference or shop; an
 * identity snapshot (document already in the base currency) has base =
 * quote, rate 1 and no kind or time.
 *
 * Columns come from the `fxSnapshot('fx')` migration macro: fx_rate,
 * fx_rate_base, fx_rate_quote, fx_rate_kind, fx_rate_effective_at. Cast on
 * a model as `'fx' => FxSnapshot::class.':fx'`. Later rate changes never
 * touch it.
 */
final class FxSnapshot implements Castable, JsonSerializable
{
    public function __construct(
        public readonly string $rate,
        public readonly string $base,
        public readonly string $quote,
        public readonly ?string $kind,
        public readonly ?CarbonImmutable $effectiveAt,
    ) {
        if (BigDecimal::of($rate)->isNegativeOrZero()) {
            throw new InvalidArgumentException('A snapshot rate must be above zero.');
        }
    }

    public static function fromRate(Rate $rate): self
    {
        return new self(Rate::normalise($rate->value()), $rate->base, $rate->quote, $rate->kind, $rate->effectiveAt);
    }

    public static function identity(string $currency): self
    {
        return new self(Rate::normalise(1), $currency, $currency, null, null);
    }

    public function base(): string
    {
        return $this->base;
    }

    public function quote(): string
    {
        return $this->quote;
    }

    public function isIdentity(): bool
    {
        return $this->base === $this->quote;
    }

    /**
     * $from converted into the snapshot's other currency: multiplied when
     * $from is in the base, divided (20 decimals) when in the quote, then
     * rounded half up to the target's minor unit. Converter::convert gives
     * the same result for the same rate.
     */
    public function convert(Money $from): Money
    {
        if ($this->isIdentity() && $from->currency() === $this->base) {
            return $from;
        }

        $decimals = app(CurrencyDecimals::class);

        [$to, $exact] = match ($from->currency()) {
            $this->base => [$this->quote, fn (BigDecimal $m) => $m->multipliedBy($this->rate)],
            $this->quote => [$this->base, fn (BigDecimal $m) => $m->dividedBy($this->rate, Converter::EXACT_SCALE, RoundingMode::HalfUp)],
            default => throw new InvalidArgumentException("A {$this->base}/{$this->quote} snapshot cannot convert {$from->currency()}."),
        };

        $minor = BigDecimal::of($from->minor())->withPointMovedRight($decimals->for($to) - $decimals->for($from->currency()));

        return Money::ofMinor((string) $exact($minor)->toScale(0, RoundingMode::HalfUp), $to);
    }

    /** @return array{rate: string, base: string, quote: string, kind: ?string, effective_at: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'rate' => $this->rate,
            'base' => $this->base,
            'quote' => $this->quote,
            'kind' => $this->kind,
            'effective_at' => $this->effectiveAt?->toIso8601String(),
        ];
    }

    /** @param array{0?: string} $arguments the column prefix, `fx` by default */
    public static function castUsing(array $arguments): CastsAttributes
    {
        $prefix = $arguments[0] ?? 'fx';

        return new class($prefix) implements CastsAttributes
        {
            public function __construct(private readonly string $prefix) {}

            public function get(Model $model, string $key, mixed $value, array $attributes): ?FxSnapshot
            {
                $rate = $attributes["{$this->prefix}_rate"] ?? null;

                if ($rate === null) {
                    return null;
                }

                $effectiveAt = $attributes["{$this->prefix}_rate_effective_at"] ?? null;

                return new FxSnapshot(
                    Rate::normalise((string) $rate),
                    $attributes["{$this->prefix}_rate_base"],
                    $attributes["{$this->prefix}_rate_quote"],
                    $attributes["{$this->prefix}_rate_kind"] ?? null,
                    $effectiveAt === null ? null : CarbonImmutable::parse($effectiveAt)->utc(),
                );
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): array
            {
                if ($value !== null && ! $value instanceof FxSnapshot) {
                    throw new InvalidArgumentException('Set an FxSnapshot or null.');
                }

                return [
                    "{$this->prefix}_rate" => $value?->rate,
                    "{$this->prefix}_rate_base" => $value?->base,
                    "{$this->prefix}_rate_quote" => $value?->quote,
                    "{$this->prefix}_rate_kind" => $value?->kind,
                    "{$this->prefix}_rate_effective_at" => $value?->effectiveAt?->utc()->format('Y-m-d H:i:s.uP'),
                ];
            }
        };
    }
}
