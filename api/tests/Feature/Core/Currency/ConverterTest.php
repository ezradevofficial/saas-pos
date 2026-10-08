<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-03, CUR-04, CUR-08: conversion, rounding once to the target's minor unit.
class ConverterTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
    }

    private function usdCdf(string $mid): Rate
    {
        return new Rate('USD', 'CDF', Rate::normalise($mid), null, null, 'shop', CarbonImmutable::now());
    }

    private function convert(Money $money, string $to, Rate $rate, RoundingMode $mode = RoundingMode::HalfUp): Money
    {
        return $this->inTenant(fn () => app(Converter::class)->convert($money, $to, $rate, $mode));
    }

    public function test_base_to_quote_multiplies_and_quote_to_base_divides_with_decimals_respected(): void
    {
        $rate = $this->usdCdf('2850');

        // USD 48.50 (4850 minor) -> CDF 138,225 (0 decimals).
        $this->assertTrue(Money::ofMinor(138225, 'CDF')->equals($this->convert(Money::ofMinor(4850, 'USD'), 'CDF', $rate)));
        // CDF 57,000 -> USD 20.00.
        $this->assertTrue(Money::ofMinor(2000, 'USD')->equals($this->convert(Money::ofMinor(57000, 'CDF'), 'USD', $rate)));
        // CDF 1,000 -> USD 0.350877... -> 0.35 half up, 0.36 up.
        $this->assertSame('35', $this->convert(Money::ofMinor(1000, 'CDF'), 'USD', $rate)->minor());
        $this->assertSame('36', $this->convert(Money::ofMinor(1000, 'CDF'), 'USD', $rate, RoundingMode::Up)->minor());
        // The same currency is returned unchanged.
        $this->assertSame('99', $this->convert(Money::ofMinor(99, 'USD'), 'USD', $rate)->minor());
    }

    public function test_half_up_at_exactly_half_a_minor_unit(): void
    {
        // USD 0.01 at 129.5 = KES 1.295, i.e. 129.5 minor units.
        $rate = new Rate('USD', 'KES', Rate::normalise('129.5'), null, null, 'shop', CarbonImmutable::now());

        $this->assertSame('130', $this->convert(Money::ofMinor(1, 'USD'), 'KES', $rate)->minor());
        $this->assertSame('129', $this->convert(Money::ofMinor(1, 'USD'), 'KES', $rate, RoundingMode::Down)->minor());
        $this->assertSame('-130', $this->convert(Money::ofMinor(-1, 'USD'), 'KES', $rate)->minor());
    }

    public function test_converter_and_snapshot_round_the_same_way_in_both_directions(): void
    {
        $rate = $this->usdCdf('2850.12345678');
        $snapshot = FxSnapshot::fromRate($rate);
        mt_srand(7);

        foreach (range(1, 200) as $i) {
            foreach ([Money::ofMinor(mt_rand(1, 9_999_999), 'CDF'), Money::ofMinor(mt_rand(1, 999_999), 'USD')] as $money) {
                $to = $money->currency() === 'CDF' ? 'USD' : 'CDF';
                $this->assertTrue($this->convert($money, $to, $rate)->equals($this->inTenant(fn () => $snapshot->convert($money))));
            }
        }
    }

    public function test_a_rate_for_another_pair_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->convert(Money::ofMinor(100, 'KES'), 'CDF', $this->usdCdf('2850'));
    }

    /**
     * Review focus 1: converting many lines one by one (each rounded half
     * up) then summing stays within half a minor unit per line of
     * converting the exact total, both CDF -> USD and USD -> CDF, at a rate
     * using all 8 decimals.
     */
    public function test_rounding_drift_of_many_lines_is_bounded_by_half_a_unit_per_line(): void
    {
        $rate = $this->usdCdf('2850.12345678');
        mt_srand(42);

        foreach ([['CDF', 'USD'], ['USD', 'CDF']] as [$from, $to]) {
            $lines = array_map(fn () => Money::ofMinor(mt_rand(1, 5_000_000), $from), range(1, 250));
            $total = array_reduce($lines, fn (?Money $sum, Money $line) => $sum?->plus($line) ?? $line);

            $summed = array_reduce(
                array_map(fn (Money $line) => $this->convert($line, $to, $rate), $lines),
                fn (?Money $sum, Money $line) => $sum?->plus($line) ?? $line,
            );
            $whole = $this->convert($total, $to, $rate);

            $drift = BigInteger::of($summed->minor())->minus($whole->minor())->abs();
            $this->assertTrue($drift->isLessThanOrEqualTo(125), "{$from}->{$to} drift {$drift} exceeds half a unit per line");

            // The documented rule: a document stores the sum of its rounded lines.
            $this->assertSame($to, $summed->currency());
        }
    }

    public function test_to_base_uses_the_stored_direction_and_returns_the_snapshot_that_reproduces_it(): void
    {
        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        $this->rate('USD', 'CDF', '2850.12345678', 'shop', '2026-10-08 07:00');

        ['base' => $base, 'snapshot' => $snapshot] = $this->inTenant(fn () => app(Converter::class)->toBase(Money::ofMinor(138225, 'CDF'), $this->acme));

        // 138,225 / 2850.12345678 = 48.4979... -> USD 48.50, not the
        // 8-decimal inverse (0.00035086 * 138,225 = 48.497...).
        $this->assertTrue(Money::ofMinor(4850, 'USD')->equals($base));
        $this->assertSame('2850.12345678', $snapshot->rate);
        $this->assertSame(['USD', 'CDF'], [$snapshot->base(), $snapshot->quote()]);
        $this->assertSame('shop', $snapshot->kind);
        $this->assertSame('2026-10-08T07:00:00+00:00', $snapshot->effectiveAt->toIso8601String());
        $this->assertTrue($base->equals($this->inTenant(fn () => $snapshot->convert(Money::ofMinor(138225, 'CDF')))));
    }

    public function test_to_base_of_an_amount_already_in_the_base_is_an_identity_snapshot(): void
    {
        ['base' => $base, 'snapshot' => $snapshot] = $this->inTenant(fn () => app(Converter::class)->toBase(Money::ofMinor(4850, 'USD'), $this->acme));

        $this->assertSame('4850', $base->minor());
        $this->assertEquals(FxSnapshot::identity('USD'), $snapshot);
        $this->assertTrue($snapshot->isIdentity());
        $this->assertSame('1.00000000', $snapshot->rate);
    }
}
