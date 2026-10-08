<?php

namespace Tests\Feature\Core\Currency;

use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Currency\Tender\TenderCalculator;
use App\Core\Currency\Tender\TenderLine;
use App\Core\Currency\Tender\TenderResult;
use InvalidArgumentException;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CUR-06, review focus 5: mixed-currency payment and change (PosPayment screen).
class TenderCalculatorTest extends TestCase
{
    use BuildsExchangeRates, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->congoCurrencies();
        // 1 USD = 2,850 CDF; CDF cash rounding 50.
        $this->rate('USD', 'CDF', '2850', 'shop');
    }

    /** @param list<array{0: int, 1: string}> $tenders minor amount, currency */
    private function calculate(Money $due, array $tenders, string $changeCurrency): TenderResult
    {
        return $this->inTenant(fn () => app(TenderCalculator::class)->calculate(
            $due,
            array_map(fn (array $t) => new TenderLine(Money::ofMinor($t[0], $t[1])), $tenders),
            $changeCurrency,
            $this->acme,
        ));
    }

    private function assertMoney(int $minor, string $currency, Money $money): void
    {
        $this->assertSame([(string) $minor, $currency], [$money->minor(), $money->currency()]);
    }

    public function test_the_screen_case_usd_20_and_cdf_57000_towards_usd_48_50_leaves_usd_8_50_asked_as_cdf_24250(): void
    {
        $result = $this->calculate(Money::ofMinor(4850, 'USD'), [[2000, 'USD'], [57000, 'CDF']], 'CDF');

        $this->assertMoney(4000, 'USD', $result->paidInDue);
        $this->assertMoney(850, 'USD', $result->remaining);
        $this->assertMoney(0, 'CDF', $result->change);
        $this->assertFalse($result->overpaid);
        $this->assertFalse($result->isSettled());
        $this->assertSame('0', $result->roundingMinor);
        $this->assertMoney(2000, 'USD', $result->lines[1]['in_due']);

        // USD 8.50 = CDF 24,225, asked rounded UP to the CDF cash rounding.
        $this->assertMoney(24250, 'CDF', $this->inTenant(fn () => app(TenderCalculator::class)->amountDueIn($result->remaining, 'CDF', $this->acme)));
        $this->assertMoney(850, 'USD', $this->inTenant(fn () => app(TenderCalculator::class)->amountDueIn($result->remaining, 'USD', $this->acme)));
    }

    public function test_paying_the_asked_cdf_settles_with_no_change_and_the_rounding_reported(): void
    {
        $result = $this->calculate(Money::ofMinor(4850, 'USD'), [[2000, 'USD'], [57000, 'CDF'], [24250, 'CDF']], 'CDF');

        $this->assertTrue($result->isSettled());
        $this->assertMoney(4850, 'USD', $result->paidInDue);
        $this->assertMoney(0, 'USD', $result->remaining);
        // CDF 25 over (USD 0.0088): below the CDF cash rounding, so no change; the shop keeps 1 cent.
        $this->assertMoney(0, 'CDF', $result->change);
        $this->assertFalse($result->overpaid);
        $this->assertSame('1', $result->roundingMinor);
    }

    public function test_change_in_cdf_is_rounded_down_to_50(): void
    {
        // USD 20 + CDF 85,000 = USD 49.8246 towards USD 48.50: CDF 3,775 over.
        $result = $this->calculate(Money::ofMinor(4850, 'USD'), [[2000, 'USD'], [85000, 'CDF']], 'CDF');

        $this->assertTrue($result->overpaid);
        $this->assertMoney(4982, 'USD', $result->paidInDue);
        $this->assertMoney(0, 'USD', $result->remaining);
        $this->assertMoney(3750, 'CDF', $result->change);
        // CDF 25 kept = USD 0.0088 -> 1 cent.
        $this->assertSame('1', $result->roundingMinor);
    }

    public function test_overpay_in_usd_with_change_in_usd_or_in_cdf(): void
    {
        $inUsd = $this->calculate(Money::ofMinor(4850, 'USD'), [[5000, 'USD']], 'USD');
        $this->assertMoney(150, 'USD', $inUsd->change);
        $this->assertSame('0', $inUsd->roundingMinor);
        $this->assertTrue($inUsd->overpaid);

        // USD 1.50 = CDF 4,275 -> CDF 4,250 given; CDF 25 kept.
        $inCdf = $this->calculate(Money::ofMinor(4850, 'USD'), [[5000, 'USD']], 'CDF');
        $this->assertMoney(4250, 'CDF', $inCdf->change);
        $this->assertSame('1', $inCdf->roundingMinor);
    }

    public function test_overpay_in_one_currency_with_a_cdf_sale(): void
    {
        $due = Money::ofMinor(10000, 'CDF');

        $same = $this->calculate($due, [[20000, 'CDF']], 'CDF');
        $this->assertMoney(10000, 'CDF', $same->change);
        $this->assertSame('0', $same->roundingMinor);

        // CDF 10,000 = USD 3.5088 -> USD 3.50; CDF 25 kept.
        $usd = $this->calculate($due, [[20000, 'CDF']], 'USD');
        $this->assertMoney(350, 'USD', $usd->change);
        $this->assertSame('25', $usd->roundingMinor);

        // A USD 5 note for a CDF 10,000 sale: CDF 14,250 paid, CDF 4,250 back.
        $note = $this->calculate($due, [[500, 'USD']], 'CDF');
        $this->assertMoney(14250, 'CDF', $note->paidInDue);
        $this->assertMoney(4250, 'CDF', $note->change);
    }

    public function test_exact_and_short_payments_never_give_negative_change(): void
    {
        $exact = $this->calculate(Money::ofMinor(4850, 'USD'), [[4850, 'USD']], 'CDF');
        $this->assertMoney(0, 'CDF', $exact->change);
        $this->assertFalse($exact->overpaid);
        $this->assertTrue($exact->isSettled());

        $nothing = $this->calculate(Money::ofMinor(4850, 'USD'), [], 'CDF');
        $this->assertMoney(4850, 'USD', $nothing->remaining);
        $this->assertMoney(0, 'CDF', $nothing->change);
        $this->assertMoney(0, 'USD', $nothing->paidInDue);
    }

    public function test_a_tender_without_a_rate_is_refused(): void
    {
        $this->expectException(RateUnavailable::class);
        $this->calculate(Money::ofMinor(4850, 'USD'), [[1000, 'KES']], 'USD');
    }

    public function test_negative_amounts_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TenderLine(Money::ofMinor(-1, 'USD'));
    }

    public function test_the_result_serialises_amounts_as_strings(): void
    {
        $json = json_decode(json_encode($this->calculate(Money::ofMinor(4850, 'USD'), [[5000, 'USD']], 'CDF')), true);

        $this->assertSame(['amount_minor' => '4250', 'currency' => 'CDF'], $json['change']);
        $this->assertSame('1', $json['rounding_minor']);
        $this->assertNull($json['lines'][0]['rate']);
    }
}
