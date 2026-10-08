<?php

namespace App\Core\Currency;

use App\Core\Currency\Models\ExchangeRate;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * A company's rate for a pair at a time (CUR-03), under row-level security.
 *
 * Precedence (ADR 003): among rates effective on or before $at, the latest
 * `shop` rate of the pair as asked; when the company has none, the latest
 * `reference` rate. Only when no rate of either kind exists in the asked
 * direction is the inverse pair used, inverted at 8 decimals half up.
 */
class ExchangeRates
{
    /**
     * The rate to convert $from into $to: 1 $from = value() $to.
     *
     * @param  'mid'|'buy'|'sell'  $side
     *
     * @throws RateUnavailable
     */
    public function current(Company $company, string $from, string $to, string $side = 'mid', ?CarbonImmutable $at = null): Rate
    {
        $rate = $this->stored($company, $from, $to, $at);

        return ($rate->base === $from ? $rate : $rate->invert())->withSide($side);
    }

    /**
     * The same choice as current(), in the direction it is stored (1 base =
     * mid quote, never inverted). Converter and FxSnapshot work in either
     * direction, so conversions with it lose nothing to an 8-decimal
     * inverse (1/2850 = 0.00035088 keeps only five significant digits).
     *
     * @throws RateUnavailable
     */
    public function stored(Company $company, string $a, string $b, ?CarbonImmutable $at = null): Rate
    {
        if ($a === $b) {
            throw new InvalidArgumentException("No rate is needed from {$a} to {$a}.");
        }

        $row = $this->pairQuery($company, $a, $b)
            ->where('effective_at', '<=', $at ?? CarbonImmutable::now())
            ->orderByRaw('(base = ?) desc', [$a])
            ->orderByRaw("(kind = 'shop') desc")
            ->orderByDesc('effective_at')
            ->first();

        return $row === null ? throw RateUnavailable::for($a, $b) : Rate::fromModel($row);
    }

    /**
     * CUR-07: the rate a new one of $base/$quote follows, of either kind
     * and stored in either direction, expressed as 1 $base = mid $quote.
     */
    public function previous(Company $company, string $base, string $quote, CarbonImmutable $at, ?string $exceptId = null): ?Rate
    {
        $row = $this->pairQuery($company, $base, $quote)
            ->where('effective_at', '<=', $at)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('effective_at')
            ->orderByRaw('(base = ?) desc', [$base])
            ->orderByRaw("(kind = 'shop') desc")
            ->first();

        if ($row === null) {
            return null;
        }

        $rate = Rate::fromModel($row);

        return $rate->base === $base ? $rate : $rate->invert();
    }

    /**
     * The current rate of every pair the company has rates for, each in its
     * stored direction (a pair stored both ways is listed once).
     *
     * @return list<Rate>
     */
    public function all(Company $company, ?CarbonImmutable $at = null): array
    {
        $pairs = ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where('effective_at', '<=', $at ?? CarbonImmutable::now())
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

    private function pairQuery(Company $company, string $a, string $b): Builder
    {
        return ExchangeRate::query()
            ->where('company_id', $company->id)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('base', $a)->where('quote', $b))
                ->orWhere(fn (Builder $q) => $q->where('base', $b)->where('quote', $a)));
    }
}
