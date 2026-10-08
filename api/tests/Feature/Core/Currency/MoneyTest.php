<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\CurrencyMismatch;
use App\Core\Currency\Money;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-01, ADR 003: amounts are integers of minor units with a currency.
class MoneyTest extends TestCase
{
    use RefreshTenantDatabase;

    private function decimals(): CurrencyDecimals
    {
        return app(CurrencyDecimals::class);
    }

    public function test_of_minor_accepts_ints_and_digit_strings_and_keeps_a_string(): void
    {
        $this->assertSame('12345', Money::ofMinor(12345, 'KES')->minor());
        $this->assertSame('-5', Money::ofMinor('-5', 'USD')->minor());
        $this->assertSame('KES', Money::ofMinor(1, 'KES')->currency());

        // Beyond 2^63: CDF turnovers must never pass through a float or int.
        $this->assertSame('92233720368547758070', Money::ofMinor('92233720368547758070', 'CDF')->minor());
    }

    public function test_of_minor_refuses_non_integers_and_bad_codes(): void
    {
        foreach ([['12.5', 'KES'], ['1e3', 'KES'], ['', 'KES'], ['10', 'kes'], ['10', 'KESX']] as [$minor, $code]) {
            try {
                Money::ofMinor($minor, $code);
                $this->fail("accepted {$minor} {$code}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_parse_uses_the_currency_decimals_with_cdf_at_zero(): void
    {
        $this->assertSame('1245000', Money::parse('12450.00', 'KES', $this->decimals())->minor());
        $this->assertSame('1245000', Money::parse('12450', 'KES', $this->decimals())->minor());
        $this->assertSame('135000', Money::parse('135000', 'CDF', $this->decimals())->minor());
        $this->assertSame('-150', Money::parse('-1.5', 'USD', $this->decimals())->minor());
    }

    public function test_parse_refuses_more_decimals_than_the_currency_has(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse('135000.5', 'CDF', $this->decimals());
    }

    public function test_a_trailing_newline_is_refused_everywhere(): void
    {
        $attempts = [
            fn () => Money::ofMinor("5\n", 'KES'),
            fn () => Money::ofMinor(5, "KES\n"),
            fn () => Money::parse("5\n", 'KES', $this->decimals()),
            fn () => Money::ofMinor(5, 'KES')->multiply("2\n"),
            fn () => Money::ofMinor(5, 'KES')->allocate(["1\n"]),
        ];

        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                $this->fail("attempt {$i} accepted a trailing newline");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_to_decimal_string(): void
    {
        $this->assertSame('12450.00', Money::ofMinor(1245000, 'KES')->toDecimalString());
        $this->assertSame('135000', Money::ofMinor(135000, 'CDF')->toDecimalString());
        $this->assertSame('-0.05', Money::ofMinor(-5, 'USD')->toDecimalString($this->decimals()));
    }

    public function test_plus_minus_and_predicates(): void
    {
        $a = Money::ofMinor(1000, 'KES');
        $b = Money::ofMinor(250, 'KES');

        $this->assertSame('1250', $a->plus($b)->minor());
        $this->assertSame('750', $a->minus($b)->minor());
        $this->assertSame('-750', $b->minus($a)->minor());
        $this->assertTrue($b->minus($a)->isNegative());
        $this->assertTrue($a->minus($a)->isZero());
        $this->assertFalse($a->isZero());
        $this->assertFalse($a->isNegative());

        // Immutable.
        $this->assertSame('1000', $a->minor());
    }

    public function test_mixing_currencies_throws(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::ofMinor(100, 'USD')->plus(Money::ofMinor(100, 'CDF'));
    }

    public function test_minus_with_another_currency_throws_too(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::ofMinor(100, 'USD')->minus(Money::ofMinor(100, 'KES'));
    }

    public function test_multiply_rounds_half_up_by_default_and_honours_a_mode(): void
    {
        // 16% VAT on KES 10.05 = 160.8 minor -> 161.
        $this->assertSame('161', Money::ofMinor(1005, 'KES')->multiply('0.16')->minor());
        // 2.5 -> 3 half up, 2 half even / down.
        $this->assertSame('3', Money::ofMinor(5, 'USD')->multiply('0.5')->minor());
        $this->assertSame('2', Money::ofMinor(5, 'USD')->multiply('0.5', RoundingMode::HalfEven)->minor());
        $this->assertSame('2', Money::ofMinor(5, 'USD')->multiply('0.5', RoundingMode::Down)->minor());
        // Negative half up rounds away from zero.
        $this->assertSame('-3', Money::ofMinor(-5, 'USD')->multiply('0.5')->minor());
        // CDF (0 dp) at an 8-decimal rate.
        $this->assertSame('2843', Money::ofMinor(1, 'CDF')->multiply('2842.51234567')->minor());
    }

    public function test_allocate_distributes_the_remainder_by_largest_fraction_and_keeps_the_total(): void
    {
        $parts = Money::ofMinor(100, 'CDF')->allocate([1, 1, 1]);
        $this->assertSame(['34', '33', '33'], array_map(fn (Money $m) => $m->minor(), $parts));

        $parts = Money::ofMinor(1000, 'KES')->allocate([70, 30]);
        $this->assertSame(['700', '300'], array_map(fn (Money $m) => $m->minor(), $parts));

        // 5 split 0:1:1 never gives a minor unit to the zero share.
        $parts = Money::ofMinor(5, 'USD')->allocate([0, 1, 1]);
        $this->assertSame(['0', '3', '2'], array_map(fn (Money $m) => $m->minor(), $parts));

        // Largest fractional remainder first: 10 * (1/6, 2/6, 3/6) = 1.67, 3.33, 5.
        $parts = Money::ofMinor(10, 'CDF')->allocate([1, 2, 3]);
        $this->assertSame(['2', '3', '5'], array_map(fn (Money $m) => $m->minor(), $parts));

        // Negative totals mirror the positive split.
        $parts = Money::ofMinor(-100, 'CDF')->allocate([1, 1, 1]);
        $this->assertSame(['-34', '-33', '-33'], array_map(fn (Money $m) => $m->minor(), $parts));

        foreach ([[100, [1, 1, 1]], [99999, [3, 7, 11, 13]], [-7, [2, 5]], ['92233720368547758071', [1, 2]]] as [$total, $ratios]) {
            $sum = Money::ofMinor(0, 'CDF');
            foreach (Money::ofMinor($total, 'CDF')->allocate($ratios) as $part) {
                $this->assertSame('CDF', $part->currency());
                $sum = $sum->plus($part);
            }
            $this->assertSame((string) $total, $sum->minor());
        }
    }

    public function test_allocate_refuses_bad_ratios(): void
    {
        foreach ([[], [0, 0], [1, -1], [1.5, 1], ['a']] as $ratios) {
            try {
                Money::ofMinor(100, 'KES')->allocate($ratios);
                $this->fail('accepted ratios '.json_encode($ratios));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_json_serialises_the_minor_amount_as_a_string(): void
    {
        $this->assertSame(
            '{"amount_minor":"12345","currency":"KES"}',
            json_encode(Money::ofMinor(12345, 'KES')),
        );
    }

    public function test_equality(): void
    {
        $this->assertTrue(Money::ofMinor(5, 'KES')->equals(Money::ofMinor('5', 'KES')));
        $this->assertFalse(Money::ofMinor(5, 'KES')->equals(Money::ofMinor(5, 'USD')));
    }
}
