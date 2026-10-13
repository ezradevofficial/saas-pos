<?php

namespace Tests\Unit\Core\Billing;

use App\Core\Billing\Catalogue\Pricing\PriceCalculator;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SUB-02: pro-rated upgrade charges, by day, in integer minor units,
 * rounded half up to whole steps (KES: whole shillings).
 */
class ProrationTest extends TestCase
{
    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator;
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}> old, new, period days, remaining, step, expected */
    public static function cases(): array
    {
        return [
            // Starter KES 3,900 + HR at its KES 1,500 minimum, 10 of 30 days left: KES 500.00.
            'a third of the period' => [390000, 540000, 30, 10, 100, 50000],
            // KES 1,000.00 more, 7 of 31 days: 225.81 rounds to KES 226.
            'whole shillings' => [390000, 490000, 31, 7, 100, 22600],
            // The same difference in cents (step 1): 22,580.645 rounds to 22,581.
            'cents' => [390000, 490000, 31, 7, 1, 22581],
            'half rounds up' => [0, 1, 2, 1, 1, 1],
            'just under half rounds down' => [0, 1, 3, 1, 1, 0],
            'the whole period' => [390000, 990000, 30, 30, 100, 600000],
            'remaining days beyond the period are clamped' => [390000, 990000, 30, 45, 100, 600000],
            'no day left' => [390000, 990000, 30, 0, 100, 0],
            'a downgrade is not charged now' => [990000, 390000, 30, 15, 100, 0],
            'no change' => [390000, 390000, 30, 15, 100, 0],
            // An annual period of 365 days with 100 left, CD in cents: USD 600 more a year.
            'annual' => [30000, 90000, 365, 100, 1, 16438],
            'leap year' => [30000, 90000, 366, 100, 1, 16393],
        ];
    }

    #[DataProvider('cases')]
    public function test_prorate(int $old, int $new, int $days, int $remaining, int $step, int $expected): void
    {
        $this->assertSame($expected, $this->calculator->prorate($old, $new, $days, $remaining, $step));
    }

    public function test_a_period_of_no_days_charges_nothing(): void
    {
        $this->assertSame(0, $this->calculator->prorate(0, 100000, 0, 0, 100));
    }

    public function test_quotes_feed_the_proration_with_exact_amounts(): void
    {
        $catalogue = PriceCalculatorTest::catalogue();
        $old = $this->calculator->quote($catalogue, new QuoteRequest(['pos', 'inventory', 'loyalty'], ['outlet' => 1], bundle: 'starter'));
        $new = $this->calculator->quote($catalogue, new QuoteRequest(['pos', 'inventory', 'loyalty', 'payroll'], ['outlet' => 1, 'employee' => 20], bundle: 'starter'));

        // Payroll for 20 employees is KES 3,000 (above its 2,000 minimum).
        $this->assertSame([390000, 690000], [$old['total'], $new['total']]);
        $this->assertSame(150000, $this->calculator->prorate($old['total'], $new['total'], 30, 15, 100));
    }

    public function test_the_charge_never_exceeds_the_difference_and_grows_with_the_days_left(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $days = random_int(28, 366);
            $old = random_int(0, 5_000_000);
            $new = $old + random_int(1, 5_000_000);
            $step = [1, 100][random_int(0, 1)];
            $previous = 0;

            for ($remaining = 0; $remaining <= $days; $remaining += random_int(1, 40)) {
                $charge = $this->calculator->prorate($old, $new, $days, $remaining, $step);
                $this->assertSame(0, $charge % $step);
                $this->assertGreaterThanOrEqual($previous, $charge);
                $this->assertLessThanOrEqual($new - $old + $step, $charge);
                $previous = $charge;
            }
        }
    }
}
