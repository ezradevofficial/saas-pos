<?php

namespace Tests\Unit\Core\Billing;

use App\Core\Billing\Catalogue\Pricing\BundleOffer;
use App\Core\Billing\Catalogue\Pricing\CatalogueSnapshot;
use App\Core\Billing\Catalogue\Pricing\Coupon;
use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use App\Core\Billing\Catalogue\Pricing\PriceCalculator;
use App\Core\Billing\Catalogue\Pricing\QuoteError;
use App\Core\Billing\Catalogue\Pricing\QuoteRequest;
use PHPUnit\Framework\TestCase;

/**
 * ONB-03, SUB-01: the price engine against concept note 8.4, in minor
 * units (KES 3,900.00 is 390000).
 */
class PriceCalculatorTest extends TestCase
{
    /** 8.4: [kind, unit, KES, USD, min KES, min USD, includes, requires] */
    private const MODULES = [
        'pos' => ['module', 'outlet', 250000, 2000, null, null, ['pos_device' => 2], null],
        'pos_device' => ['extra', 'device', 50000, 400, null, null, [], 'pos'],
        'inventory' => ['module', 'outlet', 150000, 1200, null, null, [], null],
        'stores' => ['module', 'outlet', 100000, 800, null, null, [], null],
        'loyalty' => ['module', 'outlet', 100000, 800, null, null, [], null],
        'ecommerce' => ['module', 'online_store', 250000, 2000, null, null, [], null],
        'accounting_essentials' => ['module', 'company', 350000, 2800, null, null, [], null],
        'accounting_pro' => ['module', 'company', 700000, 5500, null, null, [], null],
        'procurement' => ['module', 'company', 300000, 2500, null, null, [], null],
        'crm' => ['module', 'company', 200000, 1500, null, null, [], null],
        'fixed_assets' => ['module', 'company', 150000, 1200, null, null, [], null],
        'manufacturing' => ['module', 'company', 400000, 3000, null, null, [], null],
        'hr' => ['module', 'employee', 10000, 100, 150000, 1200, [], null],
        'payroll' => ['module', 'employee', 15000, 150, 200000, 1500, [], null],
        'brand_pack' => ['addon', 'tenant', 150000, 1200, null, null, [], null],
    ];

    private PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator;
    }

    public static function catalogue(string $country = 'KE'): CatalogueSnapshot
    {
        $usd = $country === 'CD';
        $modules = [];

        foreach (self::MODULES as $key => [$kind, $unit, $kes, $dollars, $minKes, $minUsd, $includes, $requires]) {
            $modules[$key] = new ModuleOffer($key, $kind, $unit, $usd ? $dollars : $kes, $usd ? $minUsd : $minKes, $includes, $requires);
        }

        $all = array_keys(array_filter(self::MODULES, fn ($m) => $m[0] === 'module' && ! in_array($m[1], ['employee'], true)));

        return new CatalogueSnapshot($country, $usd ? 'USD' : 'KES', $modules, [
            'starter' => new BundleOffer('starter', ['pos', 'inventory', 'loyalty'], ['brand_pack'], false, $usd ? 3000 : 390000),
            'business' => new BundleOffer('business', ['pos', 'inventory', 'stores', 'loyalty', 'accounting_essentials', 'procurement'], [], false, $usd ? 7900 : 990000, $usd ? 3200 : 400000),
            'enterprise' => new BundleOffer('enterprise', $all, [], true, $usd ? 32000 : 4000000),
        ], annualMonthsCharged: 10, monthsInCycle: 12);
    }

    private function quote(array $modules, array $quantities = [], string $cycle = 'monthly', ?string $bundle = null, ?Coupon $coupon = null, string $country = 'KE'): array
    {
        return $this->calculator->quote(self::catalogue($country), new QuoteRequest($modules, $quantities, $cycle, $bundle, $coupon));
    }

    private function line(array $quote, string $key): array
    {
        foreach ($quote['lines'] as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }

        $this->fail("No line for {$key}");
    }

    public function test_starter_is_3900_a_month(): void
    {
        $quote = $this->quote(['pos', 'inventory', 'loyalty'], ['outlet' => 1], bundle: 'starter');

        $this->assertSame(390000, $quote['total']);
        $this->assertSame('KES', $quote['currency']);
        $this->assertSame(['bundle', 'module'], array_column($quote['lines'], 'type'));
        // POS's 2 devices are included in the bundle too.
        $this->assertSame(['quantity' => 2, 'included' => 2, 'billable' => 0, 'amount' => 0], array_intersect_key($this->line($quote, 'pos_device'), array_flip(['quantity', 'included', 'billable', 'amount'])));
        $this->assertNull($quote['suggestion']);
    }

    public function test_pos_inventory_loyalty_a_la_carte_is_5000_and_starter_is_suggested_saving_1100(): void
    {
        $quote = $this->quote(['pos', 'inventory', 'loyalty'], ['outlet' => 1]);

        $this->assertSame(500000, $quote['total']);
        $this->assertNull($quote['bundle']);
        $this->assertSame(['bundle' => 'starter', 'total' => 390000, 'saving' => 110000, 'currency' => 'KES'], $quote['suggestion']);
    }

    public function test_three_extra_devices_cost_500_each_beyond_the_two_included(): void
    {
        $quote = $this->quote(['pos'], ['outlet' => 1, 'device' => 5]);

        $device = $this->line($quote, 'pos_device');
        $this->assertSame([5, 2, 3, 50000, 150000], [$device['quantity'], $device['included'], $device['billable'], $device['unit_amount'], $device['amount']]);
        $this->assertSame(250000 + 150000, $quote['total']);

        // Two outlets include four devices.
        $two = $this->quote(['pos'], ['outlet' => 2, 'device' => 5]);
        $this->assertSame([4, 1], [$this->line($two, 'pos_device')['included'], $this->line($two, 'pos_device')['billable']]);
        $this->assertSame(2 * 250000 + 50000, $two['total']);
    }

    public function test_hr_with_five_employees_is_charged_the_1500_minimum(): void
    {
        $quote = $this->quote(['hr'], ['employee' => 5]);
        $hr = $this->line($quote, 'hr');

        $this->assertTrue($hr['minimum_applied']);
        $this->assertSame([5, 10000, 150000, 150000], [$hr['quantity'], $hr['unit_amount'], $hr['minimum_amount'], $hr['amount']]);

        // 20 employees cost more than the minimum: 20 x 100.
        $this->assertSame(200000, $this->quote(['hr'], ['employee' => 20])['total']);
        $this->assertFalse($this->line($this->quote(['hr'], ['employee' => 20]), 'hr')['minimum_applied']);

        // Payroll: 2,000 minimum; HR and Payroll stay per employee with a bundle.
        $bundled = $this->quote(['pos', 'inventory', 'loyalty', 'payroll'], ['employee' => 3], bundle: 'starter');
        $this->assertSame(390000 + 200000, $bundled['total']);
    }

    public function test_annual_is_ten_times_monthly(): void
    {
        foreach ([['pos', 'inventory', 'loyalty'], ['hr', 'payroll', 'crm'], ['pos']] as $modules) {
            $monthly = $this->quote($modules, ['outlet' => 2, 'device' => 6, 'employee' => 40]);
            $annual = $this->quote($modules, ['outlet' => 2, 'device' => 6, 'employee' => 40], 'annual');

            $this->assertSame(10 * $monthly['total'], $annual['total']);
            $this->assertSame(12 * $monthly['total'], $annual['subtotal']);
            $this->assertSame(2 * $monthly['total'], $annual['annual_discount']);
            $this->assertSame([12, 10], [$annual['months'], $annual['months_charged']]);
        }

        $this->assertSame(3900000, $this->quote(['pos', 'inventory', 'loyalty'], cycle: 'annual', bundle: 'starter')['total']);
    }

    public function test_drc_is_priced_in_usd(): void
    {
        $quote = $this->quote(['pos', 'inventory', 'loyalty'], ['outlet' => 1, 'device' => 3], country: 'CD');

        $this->assertSame(['CD', 'USD'], [$quote['country'], $quote['currency']]);
        // 20 + 12 + 8 + one extra device at 4.
        $this->assertSame(2000 + 1200 + 800 + 400, $quote['total']);
        // Starter at 30 plus the extra device.
        $this->assertSame(['bundle' => 'starter', 'total' => 3400, 'saving' => 1000, 'currency' => 'USD'], $quote['suggestion']);

        // HR at 1.00 a head, minimum 12; Payroll 1.50, minimum 15.
        $this->assertSame(1200 + 1500, $this->quote(['hr', 'payroll'], ['employee' => 4], country: 'CD')['total']);
        $this->assertSame(3000 + 4500, $this->quote(['hr', 'payroll'], ['employee' => 30], country: 'CD')['total']);
    }

    public function test_business_bundle_charges_extra_outlets_at_4000(): void
    {
        $modules = ['pos', 'inventory', 'stores', 'loyalty', 'accounting_essentials', 'procurement'];
        $quote = $this->quote($modules, ['outlet' => 3, 'device' => 6], bundle: 'business');

        $bundle = $this->line($quote, 'business');
        $this->assertSame([3, 1, 2, 990000 + 2 * 400000], [$bundle['quantity'], $bundle['included'], $bundle['billable'], $bundle['amount']]);
        $this->assertSame(0, $this->line($quote, 'pos_device')['amount']);
        $this->assertSame(1790000, $quote['total']);

        // Starter has no extra-outlet price: it cannot cover two outlets.
        $this->assertSame('bundle_outlet_limit', $this->error(fn () => $this->quote(['pos'], ['outlet' => 2], bundle: 'starter'))->reason);

        // A la carte for one outlet the Business set costs 12,500: Business saves 2,600.
        $this->assertSame(['bundle' => 'business', 'total' => 990000, 'saving' => 260000, 'currency' => 'KES'], $this->quote($modules)['suggestion']);
    }

    public function test_a_second_company_on_a_bundle_pays_the_module_rate(): void
    {
        $quote = $this->quote(['pos', 'accounting_essentials'], ['company' => 2], bundle: 'business');

        $accounting = $this->line($quote, 'accounting_essentials');
        $this->assertSame([2, 1, 1, 350000], [$accounting['quantity'], $accounting['included'], $accounting['billable'], $accounting['amount']]);
    }

    public function test_coupons(): void
    {
        $modules = ['pos', 'inventory', 'loyalty'];

        $tenPercent = $this->quote($modules, coupon: new Coupon('TEN', Coupon::PERCENT, percent: 10));
        $this->assertSame(['code' => 'TEN', 'applied' => true, 'reason' => null, 'discount' => 50000], $tenPercent['coupon']);
        $this->assertSame(450000, $tenPercent['total']);

        // Only the POS line, over the 10 months charged of an annual cycle.
        $posOnly = $this->quote($modules, cycle: 'annual', coupon: new Coupon('POS', Coupon::PERCENT, percent: 50, modules: ['pos']));
        $this->assertSame(1250000, $posOnly['coupon_discount']);
        $this->assertSame(5000000 - 1250000, $posOnly['total']);

        // A fixed amount in the country's currency, never beyond what it applies to.
        $fixed = $this->quote($modules, coupon: new Coupon('FLAT', Coupon::FIXED, amount: 100000, currency: 'KES'));
        $this->assertSame(400000, $fixed['total']);
        $huge = $this->quote($modules, coupon: new Coupon('HUGE', Coupon::FIXED, amount: 99900000, currency: 'KES'));
        $this->assertSame(0, $huge['total']);
        $this->assertSame(500000, $huge['coupon_discount']);

        // A USD coupon does nothing in Kenya.
        $usd = $this->quote($modules, coupon: new Coupon('USD5', Coupon::FIXED, amount: 500, currency: 'USD'));
        $this->assertSame(['applied' => false, 'reason' => 'currency', 'discount' => 0], array_intersect_key($usd['coupon'], array_flip(['applied', 'reason', 'discount'])));
        $this->assertSame(500000, $usd['total']);

        // A coupon for a bundle applies to the bundle line only.
        $starter = $this->quote([...$modules, 'hr'], ['employee' => 5], bundle: 'starter', coupon: new Coupon('S', Coupon::PERCENT, percent: 100, bundles: ['starter']));
        $this->assertSame(150000, $starter['total']);
        $this->assertSame(['applied' => false, 'reason' => 'not_applicable'], array_intersect_key($this->quote(['hr'], coupon: new Coupon('S', Coupon::PERCENT, percent: 100, bundles: ['starter']))['coupon'], array_flip(['applied', 'reason'])));
    }

    public function test_enterprise_is_never_priced(): void
    {
        $quote = $this->quote(['pos', 'accounting_pro'], ['outlet' => 10], bundle: 'enterprise');

        $this->assertTrue($quote['quoted']);
        $this->assertSame([], $quote['lines']);
        $this->assertNull($quote['total']);
        $this->assertNull($quote['suggestion']);

        // Never suggested either, however large the selection.
        $everything = $this->quote(array_keys(array_filter(self::MODULES, fn ($m) => $m[0] === 'module')), ['outlet' => 10, 'company' => 3, 'employee' => 200]);
        $this->assertNotSame('enterprise', $everything['suggestion']['bundle'] ?? null);
    }

    public function test_brand_pack_is_sold_only_with_starter(): void
    {
        $this->assertSame(390000 + 150000, $this->quote(['pos', 'brand_pack'], bundle: 'starter')['total']);
        $this->assertSame('addon_requires_bundle', $this->error(fn () => $this->quote(['brand_pack']))->reason);
        $this->assertSame('addon_not_available', $this->error(fn () => $this->quote(['brand_pack'], bundle: 'business'))->reason);
    }

    public function test_unknown_or_unsold_selections_are_refused(): void
    {
        $this->assertSame(['unknown_module', 'modules.1', 'nope'], $this->errorParts(fn () => $this->quote(['pos', 'nope'])));
        $this->assertSame(['unknown_bundle', 'bundle', 'gold'], $this->errorParts(fn () => $this->quote(['pos'], bundle: 'gold')));
        $this->assertSame(['requires_module', 'modules', 'pos_device'], $this->errorParts(fn () => $this->quote(['pos_device'], ['device' => 3])));
        $this->assertSame('unknown_cycle', $this->error(fn () => $this->quote(['pos'], cycle: 'weekly'))->reason);

        $snapshot = self::catalogue();
        $unsold = new CatalogueSnapshot('KE', 'KES', [...$snapshot->modules, 'crm' => new ModuleOffer('crm', 'module', 'company', null)], $snapshot->bundles, 10);
        $this->assertSame('not_sold_here', $this->error(fn () => $this->calculator->quote($unsold, new QuoteRequest(['crm'])))->reason);
    }

    /** Property: a suggestion is never more expensive, and no total is ever negative. */
    public function test_property_suggestions_are_cheaper_and_totals_never_negative(): void
    {
        mt_srand(20261012);
        $keys = array_keys(array_filter(self::MODULES, fn ($m) => $m[0] === 'module'));
        $checked = 0;
        $suggested = 0;

        for ($i = 0; $i < 600; $i++) {
            $modules = array_values(array_filter($keys, fn () => mt_rand(0, 2) === 0)) ?: ['pos'];
            $quantities = [
                'outlet' => mt_rand(1, 6),
                'device' => mt_rand(0, 20),
                'employee' => mt_rand(1, 300),
                'company' => mt_rand(1, 3),
                'online_store' => mt_rand(1, 2),
            ];
            $cycle = mt_rand(0, 1) === 0 ? 'monthly' : 'annual';
            $coupon = match (mt_rand(0, 3)) {
                0 => null,
                1 => new Coupon('P', Coupon::PERCENT, percent: mt_rand(1, 100)),
                2 => new Coupon('F', Coupon::FIXED, amount: mt_rand(1, 50_000_000), currency: mt_rand(0, 1) ? 'KES' : 'USD'),
                default => new Coupon('M', Coupon::PERCENT, percent: mt_rand(1, 100), modules: [$keys[mt_rand(0, count($keys) - 1)]], bundles: mt_rand(0, 1) ? ['starter'] : []),
            };
            $country = mt_rand(0, 1) ? 'KE' : 'CD';

            $quote = $this->quote($modules, $quantities, $cycle, coupon: $coupon, country: $country);

            $this->assertGreaterThanOrEqual(0, $quote['total']);
            $this->assertSame($quote['subtotal'] - $quote['annual_discount'] - $quote['coupon_discount'], $quote['total']);
            foreach ($quote['lines'] as $line) {
                $this->assertGreaterThanOrEqual(0, $line['amount']);
            }

            if ($quote['suggestion'] !== null) {
                $suggested++;
                $bundled = $this->quote($modules, $quantities, $cycle, $quote['suggestion']['bundle'], $coupon, $country);
                $this->assertLessThan($quote['total'], $bundled['total']);
                $this->assertSame($bundled['total'], $quote['suggestion']['total']);
                $this->assertSame($quote['total'] - $bundled['total'], $quote['suggestion']['saving']);
                $this->assertGreaterThan(0, $quote['suggestion']['saving']);
                // The bundle quote still charges every selected module (in the bundle or beside it).
                $charged = array_merge(...array_map(fn ($line) => $line['type'] === 'bundle' ? self::catalogue()->bundle($line['key'])->modules : [$line['key']], $bundled['lines']));
                $this->assertSame([], array_diff($modules, $charged));
            }

            $checked++;
        }

        $this->assertSame(600, $checked);
        $this->assertGreaterThan(10, $suggested, 'the property should see suggestions');
    }

    private function error(callable $call): QuoteError
    {
        try {
            $call();
        } catch (QuoteError $e) {
            return $e;
        }

        $this->fail('Expected a QuoteError');
    }

    private function errorParts(callable $call): array
    {
        $e = $this->error($call);

        return [$e->reason, $e->field, $e->key];
    }
}
