<?php

namespace Modules\FixedAssets\Tests;

use Carbon\CarbonImmutable;
use Modules\FixedAssets\Depreciation\AssetBook;
use Modules\FixedAssets\Depreciation\Calculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// FA-07 to FA-11: the depreciation arithmetic of every method and
// convention, in integer minor units: charges add up to cost less residual
// value exactly over the life, pro rata by days or half months, a change of
// estimate applies from the next charge, and units of production follow
// the meter.
class DepreciationMathTest extends TestCase
{
    private Calculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new Calculator;
    }

    private static function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /**
     * Charges month by month from the in-service month until nothing is left
     * (at most $months months).
     *
     * @return list<int>
     */
    private function monthly(AssetBook $book, string $convention, int $months = 400): array
    {
        $charges = [];
        $month = $book->inService->startOfMonth();
        $from = $book->inService;

        for ($i = 0; $i < $months && $book->depreciable() > 0; $i++) {
            $to = $month->endOfMonth()->startOfDay();
            $charge = $this->calculator->charge($book, $from, $to, $convention)->amount;
            $charges[] = $charge;
            $book = $book->after($charge);
            $month = $month->addMonthNoOverflow();
            $from = $to->addDay();
        }

        return $charges;
    }

    public static function lives(): array
    {
        return [
            'straight line by days, mid-month start, odd cost' => [AssetBook::STRAIGHT_LINE, Calculator::DAYS, '2026-01-17', 36, 1_000_003, 100_001, null],
            'straight line by half months' => [AssetBook::STRAIGHT_LINE, Calculator::MID_MONTH, '2026-03-20', 60, 12_345_679, 0, null],
            'straight line, CDF-like whole units' => [AssetBook::STRAIGHT_LINE, Calculator::DAYS, '2026-02-28', 7, 997, 3, null],
            'declining balance by days' => [AssetBook::DECLINING_BALANCE, Calculator::DAYS, '2026-05-09', 48, 4_800_000, 300_000, '40'],
            'declining balance by half months' => [AssetBook::DECLINING_BALANCE, Calculator::MID_MONTH, '2026-01-01', 24, 999_999, 11, '62.5'],
        ];
    }

    #[DataProvider('lives')]
    public function test_charges_sum_exactly_to_cost_less_residual_over_the_life(string $method, string $convention, string $start, int $life, int $cost, int $residual, ?string $rate): void
    {
        $book = new AssetBook($method, self::d($start), $life, $cost, residual: $residual, rate: $rate);
        $charges = $this->monthly($book, $convention);

        $this->assertSame($cost - $residual, array_sum($charges));
        $this->assertTrue(collect($charges)->every(fn (int $c) => $c >= 0));
        // The life ends on time: the last charge falls in the month the life ends (days: in-service + life - 1 day; half months: life months later).
        $this->assertLessThanOrEqual($life + 1, count($charges));
    }

    public function test_straight_line_by_days_prorates_the_first_and_last_months(): void
    {
        // 365 000 over 12 months from 2026-01-11: life ends 2027-01-10, 365 days, 1 000 a day.
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-01-11'), 12, 365_000);

        $this->assertSame(21_000, $this->calculator->charge($book, self::d('2026-01-11'), self::d('2026-01-31'), Calculator::DAYS)->amount);
        $this->assertSame('21', $this->calculator->charge($book, self::d('2026-01-11'), self::d('2026-01-31'), Calculator::DAYS)->units);
        $charges = $this->monthly($book, Calculator::DAYS);
        $this->assertCount(13, $charges);
        $this->assertSame(10_000, end($charges));
        $this->assertSame(365_000, array_sum($charges));
    }

    public function test_mid_month_takes_half_a_month_first_and_last(): void
    {
        // 240 000 over 12 months: 24 halves of 10 000; the first and the thirteenth month take one half each.
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-04-28'), 12, 240_000);
        $charges = $this->monthly($book, Calculator::MID_MONTH);

        $this->assertSame([10_000, ...array_fill(0, 11, 20_000), 10_000], $charges);
    }

    public function test_mid_month_disposal_takes_half_its_month_and_none_in_the_first_month(): void
    {
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-04-28'), 12, 240_000);

        // Disposed in June after April and May were charged (10 000 + 20 000): half of June.
        $after = $book->after(30_000);
        $this->assertSame(10_000, $this->calculator->charge($after, self::d('2026-06-01'), self::d('2026-06-10'), Calculator::MID_MONTH, disposal: true)->amount);
        // Disposed in its first month: nothing.
        $this->assertSame(0, $this->calculator->charge($book, self::d('2026-04-28'), self::d('2026-04-30'), Calculator::MID_MONTH, disposal: true)->amount);
    }

    public function test_residual_value_is_never_depreciated(): void
    {
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-01-01'), 10, 100_000, residual: 20_000);
        $charges = $this->monthly($book, Calculator::DAYS);

        $this->assertSame(80_000, array_sum($charges));
        $this->assertSame(0, $this->calculator->charge($book->after(80_000), self::d('2027-01-01'), self::d('2027-01-31'), Calculator::DAYS)->amount);
    }

    public function test_declining_balance_charges_rate_on_book_value_then_switches_to_straight_line(): void
    {
        // 25 % a year on 1 200 000 (no residual), 120 months, days: January 2026 = 1 200 000 x 25 % x 31/365.
        $book = new AssetBook(AssetBook::DECLINING_BALANCE, self::d('2026-01-01'), 120, 1_200_000, rate: '25');
        $january = $this->calculator->charge($book, self::d('2026-01-01'), self::d('2026-01-31'), Calculator::DAYS)->amount;
        $this->assertSame(intdiv(1_200_000 * 25 * 31 * 2 + 365 * 100, 365 * 100 * 2), $january);

        // Late in the life straight line gives more, so the asset still ends at its residual value on time.
        $charges = $this->monthly($book, Calculator::DAYS);
        $this->assertCount(120, $charges);
        $this->assertSame(1_200_000, array_sum($charges));
        $this->assertGreaterThan($charges[0], $january * 2);
    }

    public function test_units_of_production_follows_the_meter_and_ends_at_the_total(): void
    {
        $book = new AssetBook(AssetBook::UNITS_OF_PRODUCTION, self::d('2026-01-01'), null, 1_000_000, residual: 100_000, totalUnits: '3000');

        $first = $this->calculator->charge($book, self::d('2026-01-01'), self::d('2026-01-31'), Calculator::DAYS, unitsUsed: '1000.5', unitsBefore: '0');
        // 900 000 x 1000.5 / 3000 = 300 150.
        $this->assertSame(300_150, $first->amount);
        $this->assertSame('1000.5', $first->units);
        $book = $book->after($first->amount);

        $this->assertSame(0, $this->calculator->charge($book, self::d('2026-02-01'), self::d('2026-02-28'), Calculator::DAYS, unitsUsed: '0', unitsBefore: '1000.5')->amount);
        // Using more than is left takes exactly what remains.
        $last = $this->calculator->charge($book, self::d('2026-03-01'), self::d('2026-03-31'), Calculator::DAYS, unitsUsed: '2500', unitsBefore: '1000.5');
        $this->assertSame(599_850, $last->amount);
        $this->assertSame(900_000, $first->amount + $last->amount);
    }

    public function test_none_never_depreciates(): void
    {
        $land = new AssetBook(AssetBook::NONE, self::d('2026-01-01'), null, 50_000_000);

        $this->assertSame(0, $this->calculator->charge($land, self::d('2026-01-01'), self::d('2026-12-31'), Calculator::DAYS)->amount);
    }

    public function test_a_change_of_estimate_applies_prospectively_and_still_sums_exactly(): void
    {
        // 600 000 over 60 months straight line; after 12 months the life becomes 36 months in all and the residual 60 000.
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-01-01'), 60, 600_000);
        $first = $this->monthly($book, Calculator::DAYS, 12);
        // By days: 365 of the life's 1 826 days.
        $this->assertSame(intdiv(600_000 * 365 * 2 + 1826, 1826 * 2), array_sum($first));

        $changed = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-01-01'), 36, 600_000, accumulated: array_sum($first), residual: 60_000);
        $from = self::d('2027-01-01');
        $rest = [];

        for ($month = $from; $changed->depreciable() > 0; $month = $month->addMonthNoOverflow()) {
            $charge = $this->calculator->charge($changed, $month, $month->endOfMonth()->startOfDay(), Calculator::DAYS)->amount;
            $rest[] = $charge;
            $changed = $changed->after($charge);
        }

        // What is left above the new residual value over the 24 months left, ending exactly at it.
        $this->assertCount(24, $rest);
        $this->assertSame(600_000 - 60_000 - array_sum($first), array_sum($rest));
        $this->assertSame(600_000 - 60_000, array_sum($first) + array_sum($rest));
    }

    public function test_a_life_shortened_to_the_past_takes_what_is_left_at_once(): void
    {
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2024-01-01'), 12, 120_000, accumulated: 50_000);

        $this->assertSame(70_000, $this->calculator->charge($book, self::d('2026-01-01'), self::d('2026-01-31'), Calculator::DAYS)->amount);
    }

    public function test_a_declining_balance_catch_up_across_years_compounds_with_each_years_length(): void
    {
        // Review #13: 40 % a year over 120 months; a catch-up from 2027-11-01 to 2028-02-29 is 2027's part
        // on 365 days, then 2028's part on 366 days from the reduced book value.
        $book = new AssetBook(AssetBook::DECLINING_BALANCE, self::d('2027-01-01'), 120, 10_000_000, rate: '40');
        $book = $book->after(3_000_000);
        $late2027 = $this->calculator->charge($book, self::d('2027-11-01'), self::d('2027-12-31'), Calculator::DAYS)->amount;
        $early2028 = $this->calculator->charge($book->after($late2027), self::d('2028-01-01'), self::d('2028-02-29'), Calculator::DAYS)->amount;

        $this->assertSame(intdiv(7_000_000 * 40 * 61 * 2 + 36_500, 36_500 * 2), $late2027);
        $this->assertSame(intdiv((7_000_000 - $late2027) * 40 * 60 * 2 + 36_600, 36_600 * 2), $early2028);
        $this->assertSame($late2027 + $early2028, $this->calculator->charge($book, self::d('2027-11-01'), self::d('2028-02-29'), Calculator::DAYS)->amount);
    }

    public function test_a_span_over_several_months_catches_up(): void
    {
        // Activated in March, first charged in the May run: March 15 to May 31 at 1 000 a day.
        $book = new AssetBook(AssetBook::STRAIGHT_LINE, self::d('2026-03-15'), 12, 365_000);

        $this->assertSame(78_000, $this->calculator->charge($book, self::d('2026-03-15'), self::d('2026-05-31'), Calculator::DAYS)->amount);
    }
}
