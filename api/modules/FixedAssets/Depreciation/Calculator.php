<?php

namespace Modules\FixedAssets\Depreciation;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * FA-07 to FA-10 (docs/modules/fixed-assets.md, "Depreciation: the
 * arithmetic"): the charge of one span `[from, to]` of an asset's life.
 * Pure integer arithmetic on minor units: each charge divides exact
 * integers once and rounds half up, and the span that reaches the end of
 * the life (or the asset's total units) takes the exact remainder, so the
 * charges of a life add up to its cost less its residual value. Every
 * method works from the book as it is now (net book value, life left), so
 * a change of estimate (FA-11) applies prospectively by itself.
 */
final class Calculator
{
    public const DAYS = 'days';

    public const MID_MONTH = 'mid_month';

    public const CONVENTIONS = [self::DAYS, self::MID_MONTH];

    /** Decimal places of rates (numeric(7,4)) and units (numeric(20,6)) turned into integers. */
    private const RATE_SCALE = 4;

    private const UNIT_SCALE = 6;

    /**
     * The charge of $book from $from to $to (both included). $disposal: the
     * span ends at a disposal (`mid_month` counts half its last month).
     * Units of production need $unitsUsed (in the span) and $unitsBefore
     * (used since acquisition before the span).
     */
    public function charge(AssetBook $book, CarbonImmutable $from, CarbonImmutable $to, string $convention, bool $disposal = false,
        ?string $unitsUsed = null, ?string $unitsBefore = null): Charge
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $depreciable = $book->depreciable();

        if ($depreciable === 0 || $from > $to || $book->method === AssetBook::NONE) {
            return new Charge(0);
        }

        if ($book->method === AssetBook::UNITS_OF_PRODUCTION) {
            return $this->units($book, $depreciable, $unitsUsed, $unitsBefore);
        }

        if ($book->lifeMonths === null) {
            return new Charge(0);
        }

        // FA-08 (review #13): a declining-balance span across years compounds year by year, each on its own length.
        if ($book->method === AssetBook::DECLINING_BALANCE && $from->year !== $to->year) {
            $total = 0;
            $units = 0;

            for ($start = $from; $start <= $to; $start = $start->endOfYear()->startOfDay()->addDay()) {
                $end = $start->endOfYear()->startOfDay() < $to ? $start->endOfYear()->startOfDay() : $to;
                $part = $this->charge($book->after($total), $start, $end, $convention, $disposal && $end == $to);
                $total += $part->amount;
                $units += (int) $part->units;
            }

            return new Charge($total, (string) $units);
        }

        [$span, $left] = $convention === self::MID_MONTH
            ? $this->halves($book, $from, $to, $disposal)
            : $this->days($book, $from, $to);

        $straight = match (true) {
            // Past the end of the life (a life shortened by a change of estimate): what is left, at once.
            $left === 0 => $depreciable,
            $span >= $left => $depreciable,
            $span === 0 => 0,
            default => self::ratio(BigInteger::of($depreciable), BigInteger::of($span), BigInteger::of($left)),
        };

        if ($book->method === AssetBook::DECLINING_BALANCE && $book->rate !== null && $span > 0 && $left > 0) {
            // FA-08: rate x the span's share of a year on the net book value, never below straight line.
            $yearUnits = $convention === self::MID_MONTH ? 24 : ($to->isLeapYear() ? 366 : 365);
            $rate = BigDecimal::of($book->rate)->withPointMovedRight(self::RATE_SCALE)->toScale(0, RoundingMode::HalfUp)->toBigInteger();
            $declining = self::ratio(
                BigInteger::of(max(0, $book->bookValue()))->multipliedBy($rate),
                BigInteger::of($span),
                BigInteger::of($yearUnits)->multipliedBy(100)->multipliedBy(BigInteger::ten()->power(self::RATE_SCALE)),
            );

            return new Charge(min($depreciable, max($declining, $straight)), (string) $span);
        }

        return new Charge($straight, (string) $span);
    }

    /** FA-09: the depreciable amount times the units used over the units left. */
    private function units(AssetBook $book, int $depreciable, ?string $unitsUsed, ?string $unitsBefore): Charge
    {
        if ($book->totalUnits === null || $unitsUsed === null) {
            return new Charge(0);
        }

        $scale = fn (string $value) => BigDecimal::of($value)->toScale(self::UNIT_SCALE, RoundingMode::HalfUp)->withPointMovedRight(self::UNIT_SCALE)->toBigInteger();
        $left = $scale($book->totalUnits)->minus($scale($unitsBefore ?? '0'));
        $used = $scale($unitsUsed);

        if ($left->isLessThanOrEqualTo(0)) {
            return new Charge($depreciable, $unitsUsed);
        }

        if ($used->isLessThanOrEqualTo(0)) {
            return new Charge(0, $unitsUsed);
        }

        if ($used->isGreaterThanOrEqualTo($left)) {
            return new Charge($depreciable, $unitsUsed);
        }

        return new Charge(self::ratio(BigInteger::of($depreciable), $used, $left), $unitsUsed);
    }

    /**
     * `days`: the span's days within the life, and the days left in the
     * life from the span's start (0 past its end).
     *
     * @return array{0: int, 1: int}
     */
    private function days(AssetBook $book, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $end = $book->lifeEnd();
        $start = $from < $book->inService ? $book->inService : $from;

        if ($end === null || $start > $end) {
            return [0, 0];
        }

        $last = $to < $end ? $to : $end;
        $span = $start > $last ? 0 : (int) $start->diffInDays($last) + 1;

        return [$span, (int) $start->diffInDays($end) + 1];
    }

    /**
     * `mid_month`: half months. The life holds 2 x life months: one in the
     * in-service month, two in each month after it, one in the month the
     * life ends. A span counts its months' halves (its last month only one
     * at a disposal, none in the in-service month itself); the halves left
     * run from the span's first month to the life's last.
     *
     * @return array{0: int, 1: int}
     */
    private function halves(AssetBook $book, CarbonImmutable $from, CarbonImmutable $to, bool $disposal): array
    {
        $first = self::month($book->inService);
        $last = $first + (int) $book->lifeMonths;
        $start = max(self::month($from), $first);
        $end = self::month($to);
        $halves = fn (int $month) => match (true) {
            $month === $first, $month === $last => 1,
            $month > $first && $month < $last => 2,
            default => 0,
        };

        if ($start > $last) {
            return [0, 0];
        }

        $span = 0;

        for ($month = $start; $month <= min($end, $last); $month++) {
            $span += $disposal && $month === $end ? ($month === $first ? 0 : min(1, $halves($month))) : $halves($month);
        }

        $left = 0;

        for ($month = $start; $month <= $last; $month++) {
            $left += $halves($month);
        }

        return [$span, $left];
    }

    private static function month(CarbonImmutable $date): int
    {
        return $date->year * 12 + $date->month - 1;
    }

    /** $amount x $numerator / $denominator, half up, as an int (never more than $amount when numerator <= denominator). */
    private static function ratio(BigInteger $amount, BigInteger $numerator, BigInteger $denominator): int
    {
        $product = $amount->multipliedBy($numerator);

        return $product->multipliedBy(2)->plus($denominator)->quotient($denominator->multipliedBy(2))->toInt();
    }
}
