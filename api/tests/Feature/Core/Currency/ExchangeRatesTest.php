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

    public function test_a_rate_stored_in_the_asked_direction_wins_over_the_inverse(): void
    {
        $this->rate('CDF', 'USD', '0.00035', 'shop', '-1 minute');
        $this->rate('USD', 'CDF', '2850', 'reference', '-1 day');

        $rate = $this->current('USD', 'CDF');

        $this->assertFalse($rate->inverted);
        $this->assertSame('2850.00000000', $rate->mid);
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
