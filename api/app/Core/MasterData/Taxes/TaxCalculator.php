<?php

namespace App\Core\MasterData\Taxes;

use App\Core\Currency\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Tax on one line (MD-03), in minor units, rounded half-up once per line
 * (ADR 003). Rates are percentages, effective on $date (the document's
 * local date, CP-02). Several codes on a line add up; they never compound.
 *
 * Exclusive (price list without tax): each code's tax = net × r / 100;
 * gross = net + Σ tax.
 * Inclusive (price list with tax): the line's total tax is
 * gross × R / (100 + R) with R the sum of the rates, then split across the
 * codes in proportion to their rates (Money::allocate, so nothing is lost);
 * net = gross − tax.
 *
 * Exempt codes add no tax (and carry no rate). A code without a confirmed
 * rate on $date throws TaxRateMissing (422 `tax_rate_missing`).
 */
class TaxCalculator
{
    /** Rates have 4 decimals: whole-number allocation ratios are rate × 10^4. */
    private const RATIO_SCALE = 4;

    /** @param list<TaxCode> $codes */
    public function forLine(Money $amount, array $codes, bool $inclusive, CarbonImmutable $date): TaxLineResult
    {
        $applied = [];

        foreach (array_values($codes) as $code) {
            if (! $code instanceof TaxCode) {
                throw new InvalidArgumentException('Tax codes must be TaxCode models.');
            }

            if ($code->isExempt()) {
                $applied[] = [$code, null];

                continue;
            }

            $rate = $code->rateOn($date);

            if ($rate === null || $rate->isNeeded()) {
                throw TaxRateMissing::for($code, $date->toDateString());
            }

            $applied[] = [$code, BigDecimal::of($rate->rate)];
        }

        $zero = Money::ofMinor(0, $amount->currency());

        return $inclusive
            ? $this->inclusive($amount, $applied, $zero)
            : $this->exclusive($amount, $applied, $zero);
    }

    /** @param list<array{0: TaxCode, 1: ?BigDecimal}> $applied */
    private function exclusive(Money $net, array $applied, Money $zero): TaxLineResult
    {
        $taxes = [];
        $gross = $net;

        foreach ($applied as [$code, $rate]) {
            $tax = $rate === null ? $zero : Money::ofMinor(
                (string) BigDecimal::of($net->minor())->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp),
                $net->currency(),
            );
            $taxes[] = $this->amount($code, $rate, $tax);
            $gross = $gross->plus($tax);
        }

        return new TaxLineResult($net, $taxes, $gross);
    }

    /** @param list<array{0: TaxCode, 1: ?BigDecimal}> $applied */
    private function inclusive(Money $gross, array $applied, Money $zero): TaxLineResult
    {
        $combined = BigDecimal::zero();

        foreach ($applied as [, $rate]) {
            $combined = $combined->plus($rate ?? BigDecimal::zero());
        }

        $total = Money::ofMinor(
            (string) BigDecimal::of($gross->minor())->multipliedBy($combined)->dividedBy($combined->plus(100), 0, RoundingMode::HalfUp),
            $gross->currency(),
        );

        $parts = $combined->isZero()
            ? array_fill(0, count($applied), $zero)
            : $total->allocate(array_map(
                fn (array $entry) => (string) ($entry[1] ?? BigDecimal::zero())->toScale(self::RATIO_SCALE)->getUnscaledValue(),
                $applied,
            ));

        $taxes = [];

        foreach ($applied as $index => [$code, $rate]) {
            $taxes[] = $this->amount($code, $rate, $parts[$index]);
        }

        return new TaxLineResult($gross->minus($total), $taxes, $gross);
    }

    private function amount(TaxCode $code, ?BigDecimal $rate, Money $tax): TaxAmount
    {
        return new TaxAmount($code->id, $code->code, $code->kind, $rate === null ? null : (string) $rate->toScale(4), $tax);
    }
}
