<?php

namespace App\Core\Currency\Tender;

use App\Core\Currency\CashRounding;
use App\Core\Currency\Converter;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * CUR-06: a sale paid in several currencies, change in a chosen one.
 *
 * Each tender is converted into the due currency exactly, at the company's
 * current rate (shop, else reference) in its stored direction: one chosen
 * rate row per pair for the whole calculation (and the same row
 * amountDueIn uses at the same time). Tender and change both use mid; buy
 * and sell are reserved (ADR 003). The rules:
 *
 * - paid: the exact sum, rounded DOWN to the due currency's minor unit;
 *   remaining = due - paid (never negative).
 * - an amount still due, asked for in another currency (amountDueIn), is
 *   rounded UP to that currency's cash rounding: the customer never
 *   underpays.
 * - change: the exact overpayment in the change currency, rounded DOWN to
 *   its cash rounding: the shop never over-gives, change is never negative.
 * - lines: each tender's share of paid in the due currency, by largest
 *   remainder (each within one minor unit of its exact value; they sum to
 *   paid exactly).
 * - rounding_minor: what the shop keeps (exact overpayment minus the change
 *   given, in minor units of the due currency, half up).
 */
class TenderCalculator
{
    public function __construct(
        private readonly ExchangeRates $rates,
        private readonly Converter $converter,
        private readonly CashRounding $cash,
    ) {}

    /** @param list<TenderLine> $tenders */
    public function calculate(Money $due, array $tenders, string $changeCurrency, Company $company, ?DateTimeInterface $at = null): TenderResult
    {
        if ($due->isNegative()) {
            throw new InvalidArgumentException('The amount due cannot be negative.');
        }

        // One time and one chosen rate row per pair for the whole calculation.
        $at = ExchangeRates::utc($at);
        $chosen = [];
        $rateFor = function (string $a, string $b) use ($company, $at, &$chosen): ?Rate {
            if ($a === $b) {
                return null;
            }

            $key = implode('/', collect([$a, $b])->sort()->all());

            return $chosen[$key] ??= $this->rates->stored($company, $a, $b, $at);
        };

        $currency = $due->currency();
        $paidExact = BigDecimal::zero();
        $lines = [];

        foreach ($tenders as $tender) {
            if (! $tender instanceof TenderLine) {
                throw new InvalidArgumentException('Tenders must be TenderLine instances.');
            }

            $from = $tender->amount->currency();
            $rate = $rateFor($from, $currency);
            $exact = $this->converter->exact(BigDecimal::of($tender->amount->minor()), $from, $currency, $rate);

            $paidExact = $paidExact->plus($exact);
            $lines[] = ['tender' => $tender, 'in_due' => null, 'rate' => $rate, 'exact' => $exact];
        }

        $paid = $this->minor($paidExact, $currency, RoundingMode::Down);
        $lines = $this->allocate($lines, $paid);
        $remaining = $paid->minus($due)->isNegative() ? $due->minus($paid) : Money::ofMinor(0, $currency);
        $overpayExact = $paidExact->minus($due->minor());

        $change = Money::ofMinor(0, $changeCurrency);
        $rounding = BigDecimal::zero();

        if ($overpayExact->isPositive()) {
            $changeRate = $rateFor($currency, $changeCurrency);
            $change = $this->cash->round(
                $this->converter->exact($overpayExact, $currency, $changeCurrency, $changeRate),
                $changeCurrency,
                RoundingMode::Down,
            );
            $changeInDue = $this->converter->exact(BigDecimal::of($change->minor()), $changeCurrency, $currency, $changeRate);
            $rounding = $overpayExact->minus($changeInDue);
        }

        return new TenderResult(
            due: $due,
            paidInDue: $paid,
            remaining: $remaining,
            change: $change,
            overpaid: BigDecimal::of($paid->minor())->isGreaterThan($due->minor()),
            roundingMinor: (string) $rounding->toScale(0, RoundingMode::HalfUp),
            lines: $lines,
        );
    }

    /**
     * What to ask for when $remaining is paid in $currency: converted at the
     * current rate and rounded UP to $currency's cash rounding (USD 8.50 at
     * 2,850 is CDF 24,225, asked as CDF 24,250).
     */
    public function amountDueIn(Money $remaining, string $currency, Company $company, ?DateTimeInterface $at = null): Money
    {
        $from = $remaining->currency();
        $rate = $from === $currency ? null : $this->rates->stored($company, $from, $currency, ExchangeRates::utc($at));
        $exact = $this->converter->exact(BigDecimal::of($remaining->minor()), $from, $currency, $rate);

        return $this->cash->round($exact, $currency, RoundingMode::Up);
    }

    /**
     * Each line's share of paid in the due currency, by largest remainder:
     * every line is floored, then the minor units still missing to reach
     * paid (fewer than the number of lines) go one each to the lines with
     * the largest fractional parts, earlier lines first on a tie. Each line
     * is within one minor unit of its own exact value, and the lines sum to
     * paid exactly.
     *
     * @param  list<array{tender: TenderLine, in_due: null, rate: ?Rate, exact: BigDecimal}>  $lines
     * @return list<array{tender: TenderLine, in_due: Money, rate: ?Rate}>
     */
    private function allocate(array $lines, Money $paid): array
    {
        $currency = $paid->currency();
        $floors = [];
        $fractions = [];

        foreach ($lines as $i => $line) {
            $floors[$i] = $line['exact']->toScale(0, RoundingMode::Floor);
            $fractions[$i] = $line['exact']->minus($floors[$i]);
        }

        $missing = BigDecimal::of($paid->minor())->minus(array_reduce($floors, fn (BigDecimal $sum, BigDecimal $f) => $sum->plus($f), BigDecimal::zero()))->toInt();

        $order = array_keys($lines);
        usort($order, fn (int $x, int $y) => $fractions[$y]->compareTo($fractions[$x]) ?: $x <=> $y);

        foreach (array_slice($order, 0, $missing) as $i) {
            $floors[$i] = $floors[$i]->plus(1);
        }

        return array_map(fn (int $i) => [
            'tender' => $lines[$i]['tender'],
            'in_due' => Money::ofMinor((string) $floors[$i], $currency),
            'rate' => $lines[$i]['rate'],
        ], array_keys($lines));
    }

    private function minor(BigDecimal $exact, string $currency, RoundingMode $mode): Money
    {
        return Money::ofMinor((string) $exact->toScale(0, $mode), $currency);
    }
}
