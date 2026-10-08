<?php

namespace App\Core\Currency\Tender;

use App\Core\Currency\CashRounding;
use App\Core\Currency\Converter;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Money;
use App\Core\Tenancy\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * CUR-06: a sale paid in several currencies, change in a chosen one.
 *
 * Each tender is converted into the due currency exactly, at the company's
 * current rate (shop, else reference; mid) in its stored direction. The
 * rules (ADR 003):
 *
 * - paid: the exact sum, rounded DOWN to the due currency's minor unit;
 *   remaining = due - paid (never negative).
 * - an amount still due, asked for in another currency (amountDueIn), is
 *   rounded UP to that currency's cash rounding: the customer never
 *   underpays.
 * - change: the exact overpayment in the change currency, rounded DOWN to
 *   its cash rounding: the shop never over-gives, change is never negative.
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
    public function calculate(Money $due, array $tenders, string $changeCurrency, Company $company, ?CarbonImmutable $at = null): TenderResult
    {
        if ($due->isNegative()) {
            throw new InvalidArgumentException('The amount due cannot be negative.');
        }

        $currency = $due->currency();
        $paidExact = BigDecimal::zero();
        $lines = [];

        foreach ($tenders as $tender) {
            if (! $tender instanceof TenderLine) {
                throw new InvalidArgumentException('Tenders must be TenderLine instances.');
            }

            $from = $tender->amount->currency();
            $rate = $from === $currency ? null : $this->rates->stored($company, $from, $currency, $at);
            $exact = $this->converter->exact(BigDecimal::of($tender->amount->minor()), $from, $currency, $rate);

            $paidExact = $paidExact->plus($exact);
            $lines[] = ['tender' => $tender, 'in_due' => $this->minor($exact, $currency, RoundingMode::Down), 'rate' => $rate];
        }

        $paid = $this->minor($paidExact, $currency, RoundingMode::Down);
        $remaining = $paid->minus($due)->isNegative() ? $due->minus($paid) : Money::ofMinor(0, $currency);
        $overpayExact = $paidExact->minus($due->minor());

        $change = Money::ofMinor(0, $changeCurrency);
        $rounding = BigDecimal::zero();

        if ($overpayExact->isPositive()) {
            $changeRate = $changeCurrency === $currency ? null : $this->rates->stored($company, $currency, $changeCurrency, $at);
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
    public function amountDueIn(Money $remaining, string $currency, Company $company, ?CarbonImmutable $at = null): Money
    {
        $from = $remaining->currency();
        $rate = $from === $currency ? null : $this->rates->stored($company, $from, $currency, $at);
        $exact = $this->converter->exact(BigDecimal::of($remaining->minor()), $from, $currency, $rate);

        return $this->cash->round($exact, $currency, RoundingMode::Up);
    }

    private function minor(BigDecimal $exact, string $currency, RoundingMode $mode): Money
    {
        return Money::ofMinor((string) $exact->toScale(0, $mode), $currency);
    }
}
