<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * A company's rate for a pair at a time (CUR-03), under row-level security.
 *
 * Precedence (ADR 003): among the pair's rates effective on or before $at
 * (UTC), stored in EITHER direction, the latest `shop` rate; when the
 * company has none, the latest `reference` rate. Direction is only the
 * last tie-break (alphabetical base, so the choice is the same whichever
 * way the pair is asked). The chosen row is returned in its stored
 * direction (stored()); current() inverts it for display at 8 decimals
 * half up when it is stored the other way.
 */
class ExchangeRates
{
    /**
     * The rate to show for $from into $to: 1 $from = value() $to.
     *
     * @param  'mid'|'buy'|'sell'  $side
     *
     * @throws RateUnavailable
     */
    public function current(Company $company, string $from, string $to, string $side = 'mid', ?DateTimeInterface $at = null): Rate
    {
        $rate = $this->stored($company, $from, $to, $at);

        return ($rate->base === $from ? $rate : $rate->invert())->withSide($side);
    }

    /**
     * The chosen rate row of the pair {$a, $b}, in the direction it is
     * stored (1 base = mid quote, never inverted). Converter, FxSnapshot
     * and TenderCalculator convert with it in either direction, so nothing
     * is lost to an 8-decimal inverse (1/2850 = 0.00035088).
     *
     * @throws RateUnavailable
     */
    public function stored(Company $company, string $a, string $b, ?DateTimeInterface $at = null): Rate
    {
        if ($a === $b) {
            throw new InvalidArgumentException("No rate is needed from {$a} to {$a}.");
        }

        $row = $this->pairQuery($company, $a, $b)
            ->where('effective_at', '<=', self::bound($at))
            ->orderByRaw("(kind = 'shop') desc")
            ->orderByDesc('effective_at')
            ->orderBy('base')
            ->orderByDesc('id')
            ->first();

        return $row === null ? throw RateUnavailable::for($a, $b) : Rate::fromModel($row);
    }

    /**
     * CUR-07: the rate a new one of $base/$quote follows, of either kind
     * and stored in either direction, expressed as 1 $base = mid $quote.
     */
    public function previous(Company $company, string $base, string $quote, DateTimeInterface $at, ?string $exceptId = null): ?Rate
    {
        $row = $this->pairQuery($company, $base, $quote)
            ->where('effective_at', '<=', self::bound($at))
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('effective_at')
            ->orderByRaw("(kind = 'shop') desc")
            ->orderBy('base')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $rate = Rate::fromModel($row);

        return $rate->base === $base ? $rate : $rate->invert();
    }

    /**
     * The current rate of every pair the company has rates for whose two
     * currencies are active in the tenant, each in its stored direction (a
     * pair stored both ways is listed once).
     *
     * @return list<Rate>
     */
    public function all(Company $company, ?DateTimeInterface $at = null): array
    {
        $at = self::utc($at);
        $active = TenantCurrency::query()->where('active', true)->pluck('code')->all();

        $pairs = ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where('effective_at', '<=', self::bound($at))
            ->whereIn('base', $active)
            ->whereIn('quote', $active)
            ->distinct()
            ->orderBy('base')->orderBy('quote')
            ->get(['base', 'quote']);

        $rates = [];

        foreach ($pairs as $pair) {
            $key = implode('/', collect([$pair->base, $pair->quote])->sort()->all());
            $rates[$key] ??= $this->stored($company, $pair->base, $pair->quote, $at);
        }

        ksort($rates);

        return array_values($rates);
    }

    /** $at (now by default) in UTC: the column is timestamptz, compared in UTC. */
    public static function utc(?DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? CarbonImmutable::now())->utc();
    }

    /** The UTC time as a timestamptz literal, microseconds kept (the default binding drops them). */
    private static function bound(?DateTimeInterface $at): string
    {
        return self::utc($at)->format('Y-m-d H:i:s.uP');
    }

    private function pairQuery(Company $company, string $a, string $b): Builder
    {
        return ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('base', $a)->where('quote', $b))
                ->orWhere(fn (Builder $q) => $q->where('base', $b)->where('quote', $a)));
    }
}
