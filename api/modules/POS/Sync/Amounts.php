<?php

namespace Modules\POS\Sync;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Amounts and rates as the till sends them (ADR 003): minor units as
 * digit strings (or whole numbers), quantities as decimal strings, rates
 * as {rate, base, quote, kind?, effective_at?} meaning 1 base = rate quote
 * in the direction the rate is stored (CUR-04).
 */
final class Amounts
{
    public static function money(int|string $minor, string $currency): Money
    {
        return Money::ofMinor((string) $minor, $currency);
    }

    /** unit price × quantity, rounded half up once to the minor unit. */
    public static function extend(int|string $unitPriceMinor, string $qty): BigDecimal
    {
        return BigDecimal::of((string) $unitPriceMinor)->multipliedBy($qty)->toScale(0, RoundingMode::HalfUp);
    }

    /**
     * The till's rate between $a and $b as a snapshot; null when $a = $b.
     *
     * @param  array{rate?: string, base?: string, quote?: string, kind?: ?string, effective_at?: ?string}|null  $rate
     */
    public static function snapshot(?array $rate, string $a, string $b, string $field): ?FxSnapshot
    {
        if ($a === $b) {
            return null;
        }

        if ($rate === null) {
            throw new Rejection('rate_missing', $field);
        }

        $pair = [$rate['base'] ?? null, $rate['quote'] ?? null];
        sort($pair);
        $wanted = [$a, $b];
        sort($wanted);

        if ($pair !== $wanted) {
            throw new Rejection('rate_pair_mismatch', $field);
        }

        if (isset($rate['kind']) && ! in_array($rate['kind'], Rate::KINDS, true)) {
            throw new Rejection('rate_missing', $field);
        }

        try {
            return new FxSnapshot(
                Rate::normalise((string) $rate['rate']),
                (string) $rate['base'],
                (string) $rate['quote'],
                $rate['kind'] ?? null,
                isset($rate['effective_at']) ? CarbonImmutable::parse($rate['effective_at'])->utc() : null,
            );
        } catch (Throwable) {
            throw new Rejection('rate_missing', $field);
        }
    }

    /** $money in the snapshot's other currency, before rounding (minor units). */
    public static function exact(Money $money, FxSnapshot $snapshot): BigDecimal
    {
        $decimals = app(CurrencyDecimals::class);
        $to = $money->currency() === $snapshot->base ? $snapshot->quote : $snapshot->base;
        $minor = BigDecimal::of($money->minor())->withPointMovedRight($decimals->for($to) - $decimals->for($money->currency()));

        return $money->currency() === $snapshot->base
            ? $minor->multipliedBy($snapshot->rate)
            : $minor->dividedBy($snapshot->rate, 20, RoundingMode::HalfUp);
    }
}
