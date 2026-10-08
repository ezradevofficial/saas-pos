<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Rate;
use App\Core\Currency\RateUnavailable;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-03: which rate is in force for a pair at a time.
class ExchangeRatesTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
    }

    private function current(string $from, string $to, string $side = 'mid', ?string $at = null): Rate
    {
        return $this->inTenant(fn () => app(ExchangeRates::class)->current($this->acme, $from, $to, $side, $at === null ? null : CarbonImmutable::parse($at)));
    }

    public function test_the_latest_shop_rate_wins_over_any_reference_rate(): void
    {
        $this->rate('USD', 'CDF', '2800', 'shop', '-3 days');
        $this->rate('USD', 'CDF', '2850', 'shop', '-2 days');
        $this->rate('USD', 'CDF', '2900', 'reference', '-1 hour');

        $rate = $this->current('USD', 'CDF');

        $this->assertSame('2850.00000000', $rate->mid);
        $this->assertSame('shop', $rate->kind);
        $this->assertFalse($rate->inverted);
    }

    public function test_the_latest_reference_rate_applies_when_the_company_has_no_shop_rate(): void
    {
        $this->rate('USD', 'CDF', '2800', 'reference', '-2 days');
        $this->rate('USD', 'CDF', '2830', 'reference', '-1 day');

        $this->assertSame('2830.00000000', $this->current('USD', 'CDF')->mid);
        $this->assertSame('reference', $this->current('USD', 'CDF')->kind);
    }

    public function test_rates_effective_after_the_time_asked_are_ignored(): void
    {
        $this->rate('USD', 'CDF', '2800', 'shop', '2026-10-01 08:00');
        $this->rate('USD', 'CDF', '2850', 'shop', '2026-10-05 08:00');
        $this->rate('USD', 'CDF', '2900', 'shop', '+1 day');

        $this->assertSame('2800.00000000', $this->current('USD', 'CDF', at: '2026-10-04 23:59')->mid);
        $this->assertSame('2850.00000000', $this->current('USD', 'CDF', at: '2026-10-05 08:00')->mid);
        $this->assertSame('2850.00000000', $this->current('USD', 'CDF')->mid);
    }

    public function test_only_the_inverse_pair_is_inverted_at_eight_decimals_with_buy_and_sell_swapped(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour', ['buy' => '2800', 'sell' => '2900']);

        $rate = $this->current('CDF', 'USD');

        $this->assertTrue($rate->inverted);
        $this->assertSame('CDF/USD', $rate->pair());
        $this->assertSame('0.00035088', $rate->mid); // 1/2850 = 0.000350877...
        $this->assertSame('0.00034483', $rate->buy); // 1/2900
        $this->assertSame('0.00035714', $rate->sell); // 1/2800
        $this->assertSame('0.00034483', $this->current('CDF', 'USD', 'buy')->value());
    }

    public function test_the_shop_rate_wins_whichever_direction_it_is_stored_in(): void
    {
        // A reference rate as asked (newer) and a shop rate stored the other way.
        $this->rate('CDF', 'USD', '0.00035', 'shop', '-1 day');
        $this->rate('USD', 'CDF', '2900', 'reference', '-1 minute');

        $rate = $this->current('USD', 'CDF');
        $this->assertSame('shop', $rate->kind);
        $this->assertTrue($rate->inverted);
        $this->assertSame('2857.14285714', $rate->mid); // 1/0.00035

        // The stored row is the same whichever way the pair is asked.
        $stored = fn (string $a, string $b) => $this->inTenant(fn () => app(ExchangeRates::class)->stored($this->acme, $a, $b));
        $this->assertSame($stored('USD', 'CDF')->id, $stored('CDF', 'USD')->id);
        $this->assertSame('CDF/USD', $stored('USD', 'CDF')->pair());
    }

    public function test_the_latest_rate_of_a_kind_wins_across_directions(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-2 days');
        $this->rate('CDF', 'USD', '0.00035', 'shop', '-1 day');

        $this->assertSame('0.00035000', $this->current('CDF', 'USD')->mid);
        $this->assertSame('2857.14285714', $this->current('USD', 'CDF')->mid);
    }

    public function test_a_non_utc_time_is_compared_in_utc(): void
    {
        $this->rate('USD', 'CDF', '2800', 'shop', '2026-10-08 08:00:00Z');
        // Between the Nairobi wall-clock reading (12:00) and the true UTC time (09:00).
        $this->rate('USD', 'CDF', '2900', 'shop', '2026-10-08 10:00:00Z');

        $at = CarbonImmutable::parse('2026-10-08 12:00:00', 'Africa/Nairobi');
        $rate = $this->inTenant(fn () => app(ExchangeRates::class)->current($this->acme, 'USD', 'CDF', 'mid', $at));

        $this->assertSame('2800.00000000', $rate->mid);
    }

    public function test_microseconds_count_when_choosing(): void
    {
        $this->rate('USD', 'CDF', '2800', 'shop', '2026-10-08 10:00:00.200000Z');
        $this->rate('USD', 'CDF', '2900', 'shop', '2026-10-08 10:00:00.700000Z');

        $this->assertSame('2800.00000000', $this->current('USD', 'CDF', at: '2026-10-08 10:00:00.500000Z')->mid);
        $this->assertSame('2900.00000000', $this->current('USD', 'CDF', at: '2026-10-08 10:00:00.700000Z')->mid);
    }

    public function test_sides_fall_back_to_mid_when_buy_or_sell_is_missing(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour', ['buy' => '2820']);

        $this->assertSame('2820.00000000', $this->current('USD', 'CDF', 'buy')->value());
        $this->assertSame('2850.00000000', $this->current('USD', 'CDF', 'sell')->value());
        $this->assertSame('2850.00000000', $this->current('USD', 'CDF')->value());
    }

    public function test_no_rate_throws_rate_unavailable_with_a_422_code(): void
    {
        $this->rate('USD', 'CDF', '2850', 'shop', '+1 hour');

        try {
            $this->current('USD', 'CDF');
            $this->fail('expected RateUnavailable');
        } catch (RateUnavailable $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('rate_unavailable', $e->errorCode);
        }
    }

    public function test_another_companys_rates_are_not_used(): void
    {
        $other = $this->inTenant(fn () => $this->company('Other'));
        $this->rate('USD', 'CDF', '2850', 'shop', '-1 hour', company: $other);

        $this->expectException(RateUnavailable::class);
        $this->current('USD', 'CDF');
    }
}
