<?php

namespace App\Core\Currency;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;

/**
 * CUR-04: the rate a document used, frozen on the document. `rate` is
 * quoted as stored: 1 `baseCurrency` (the rate's base, which is either the
 * document's currency or the company's base currency) = `rate` of the
 * other one. `kind` is reference or shop; an identity snapshot (document
 * already in the base currency) has rate 1 and no kind or time.
 *
 * Columns come from the `fxSnapshot('fx')` migration macro: fx_rate,
 * fx_base_currency, fx_rate_kind, fx_rate_effective_at. Cast on a model as
 * `'fx' => FxSnapshot::class.':fx'`. Later rate changes never touch it.
 */
final class FxSnapshot implements Castable, JsonSerializable
{
    public function __construct(
        public readonly string $rate,
        public readonly string $baseCurrency,
        public readonly ?string $kind,
        public readonly ?CarbonImmutable $effectiveAt,
    ) {}

    public static function fromRate(Rate $rate): self
    {
        return new self(Rate::normalise($rate->value()), $rate->base, $rate->kind, $rate->effectiveAt);
    }

    public static function identity(string $currency): self
    {
        return new self(Rate::normalise(1), $currency, null, null);
    }

    public function isIdentity(): bool
    {
        return $this->kind === null;
    }

    /** Re-apply the frozen rate (same rounding as Converter::convert). */
    public function convert(Money $money, string $to): Money
    {
        if ($money->currency() === $to) {
            return $money;
        }

        $other = match ($this->baseCurrency) {
            $money->currency() => $to,
            $to => $money->currency(),
            default => throw new InvalidArgumentException("A snapshot based on {$this->baseCurrency} cannot convert {$money->currency()} to {$to}."),
        };

        $rate = new Rate($this->baseCurrency, $other, $this->rate, null, null, $this->kind ?? 'reference', $this->effectiveAt ?? CarbonImmutable::now());

        return app(Converter::class)->convert($money, $to, $rate);
    }

    /** @return array{rate: string, base_currency: string, kind: ?string, effective_at: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'rate' => $this->rate,
            'base_currency' => $this->baseCurrency,
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
                    $attributes["{$this->prefix}_base_currency"],
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
                    "{$this->prefix}_base_currency" => $value?->baseCurrency,
                    "{$this->prefix}_rate_kind" => $value?->kind,
                    "{$this->prefix}_rate_effective_at" => $value?->effectiveAt?->utc()->format('Y-m-d H:i:s.uP'),
                ];
            }
        };
    }
}
