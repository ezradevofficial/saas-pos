<?php

namespace Modules\POS\Tests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SalePayment;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-03, CUR-04, CUR-06, CUR-09: a sale paid in several currencies keeps
// each tender's rate and amount in the sale currency, the change in the
// chosen currency (never above the overpayment), the rounding the shop
// keeps, and base-currency amounts with the rate used.
class MultiCurrencySaleTest extends TestCase
{
    use BuildsExchangeRates, BuildsPos, RefreshTenantDatabase;

    private string $shift;

    private PaymentMethod $cashCdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPos();
        // DR Congo: base USD, CDF with cash rounding 50; 1 USD = 2850 CDF (shop rate).
        $this->congoCurrencies();
        $this->rate('USD', 'CDF', '2850', at: '-1 day');
        $this->cashCdf = $this->inTenant(fn () => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash CDF', 'currency' => 'CDF', 'active' => true, 'position' => 4]));
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    private function usdRate(string $rate = '2850'): array
    {
        return ['rate' => $rate, 'base' => 'USD', 'quote' => 'CDF', 'kind' => 'shop', 'effective_at' => now()->subDay()->toIso8601String()];
    }

    /** A USD 8.50 sale (tax included at the test rate: USD 0.94). */
    private function usdSale(int $seq, array $payments, array $change): array
    {
        return $this->saleBody($this->shift, $seq, [
            'currency' => 'USD',
            'price_list_id' => null,
            'lines' => [$this->line(['qty' => '1', 'unit_price_minor' => '850', 'list_price_minor' => null, 'price_list_id' => null, 'tax_minor' => '94', 'total_minor' => '850'])],
            'payments' => $payments,
            'change' => $change,
        ]);
    }

    public function test_cdf_tendered_for_a_usd_sale_stores_the_rate_the_change_in_cdf_and_the_rounding(): void
    {
        // CDF 30,000 at 2850 = USD 10.5263...: credited USD 10.52, overpaid by USD 2.0263... exactly.
        // Change in CDF: 2.0263 × 2850 = 5,775 rounded down to the 50 step = CDF 5,750 (USD 2.0175...).
        $body = $this->usdSale(1, [[
            'id' => $this->id(), 'payment_method_id' => $this->cashCdf->id, 'currency' => 'CDF',
            'amount_minor' => '30000', 'amount_in_sale_minor' => '1052', 'rate' => $this->usdRate(), 'status' => 'confirmed',
        ]], ['currency' => 'CDF', 'amount_minor' => '5750', 'rate' => $this->usdRate()]);

        $this->upload([$body])->assertOk()->assertJsonPath('results.0.flags', []);

        $this->inTenant(function () use ($body) {
            $sale = Sale::findOrFail($body['id']);
            $this->assertSame(['850', '1052', '5750', 'CDF'], [(string) $sale->total_minor, (string) $sale->paid_minor, (string) $sale->change_minor, $sale->change_currency]);
            // The shop keeps USD 0.0088 (202.63 - 201.75 cents), stored half up as 1 cent.
            $this->assertSame('1', (string) $sale->rounding_minor);
            $this->assertSame(['USD', '850', '94'], [$sale->base_currency, (string) $sale->base_total_minor, (string) $sale->base_tax_minor]);
            $this->assertTrue($sale->fx->isIdentity());

            $payment = SalePayment::where('sale_id', $sale->id)->sole();
            $this->assertSame(['CDF', '30000', '1052'], [$payment->currency, (string) $payment->amount_minor, (string) $payment->amount_in_sale_minor]);
            $this->assertSame(['2850.00000000', 'USD', 'CDF', 'shop'], [$payment->fx->rate, $payment->fx->base, $payment->fx->quote, $payment->fx->kind]);
        });
    }

    public function test_split_usd_and_cdf_with_change_in_usd(): void
    {
        // USD 5.00 + CDF 10,000 (USD 3.5087...) = USD 8.50 credited (8.5087 exactly); change USD 0.00.
        $body = $this->usdSale(1, [
            ['id' => $this->id(), 'payment_method_id' => $this->methods['cash_usd']->id, 'currency' => 'USD', 'amount_minor' => '500', 'amount_in_sale_minor' => '500', 'rate' => null, 'status' => 'confirmed'],
            ['id' => $this->id(), 'payment_method_id' => $this->cashCdf->id, 'currency' => 'CDF', 'amount_minor' => '10000', 'amount_in_sale_minor' => '350', 'rate' => $this->usdRate(), 'status' => 'confirmed'],
        ], ['currency' => 'USD', 'amount_minor' => '0', 'rate' => null]);

        $this->upload([$body])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->inTenant(fn () => $this->assertSame(2, SalePayment::where('sale_id', $body['id'])->count()));
    }

    public function test_conversions_and_change_must_follow_the_stated_rate(): void
    {
        $cdf = fn (string $inSale, string $rate = '2850') => [[
            'id' => $this->id(), 'payment_method_id' => $this->cashCdf->id, 'currency' => 'CDF',
            'amount_minor' => '30000', 'amount_in_sale_minor' => $inSale, 'rate' => $this->usdRate($rate), 'status' => 'confirmed',
        ]];

        // Credited more than the rate gives.
        $this->upload([$this->usdSale(1, $cdf('1060'), ['currency' => 'USD', 'amount_minor' => '0'])])
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'payment_conversion_mismatch');
        // More change than was overpaid (USD 2.02).
        $this->upload([$this->usdSale(1, $cdf('1052'), ['currency' => 'USD', 'amount_minor' => '210'])])
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'change_too_large');
        // A tender in another currency without the rate the till used.
        $noRate = $cdf('1052');
        $noRate[0]['rate'] = null;
        $this->upload([$this->usdSale(1, $noRate, ['currency' => 'USD', 'amount_minor' => '0'])])
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'rate_missing');
        // A rate for another pair.
        $wrongPair = $cdf('1052');
        $wrongPair[0]['rate']['quote'] = 'KES';
        $this->upload([$this->usdSale(1, $wrongPair, ['currency' => 'USD', 'amount_minor' => '0'])])
            ->assertUnprocessable()->assertJsonPath('results.0.error.code', 'rate_pair_mismatch');
    }

    public function test_a_till_rate_other_than_the_servers_is_kept_and_flagged(): void
    {
        // CUR-09: offline, the till used yesterday's cached 2900 (the server's rate in force is 2850).
        $body = $this->usdSale(1, [[
            'id' => $this->id(), 'payment_method_id' => $this->cashCdf->id, 'currency' => 'CDF',
            'amount_minor' => '29000', 'amount_in_sale_minor' => '1000', 'rate' => $this->usdRate('2900'), 'status' => 'confirmed',
        ]], ['currency' => 'USD', 'amount_minor' => '150']);

        $this->upload([$body])->assertOk()
            ->assertJsonPath('results.0.flags.0.code', 'rate_differs')
            ->assertJsonPath('results.0.flags.0.detail.pair', 'USD/CDF');
        $this->inTenant(fn () => $this->assertSame('2900.00000000', SalePayment::where('sale_id', $body['id'])->sole()->fx->rate));
    }

    public function test_a_sale_in_another_currency_than_the_base_stores_base_amounts_at_the_servers_rate(): void
    {
        // A CDF 28,500 sale (tax included: CDF 3,167) in a USD-based company: USD 10.00 and USD 1.11.
        $body = $this->saleBody($this->shift, 1, [
            'currency' => 'CDF',
            'price_list_id' => null,
            'lines' => [$this->line(['qty' => '1', 'unit_price_minor' => '28500', 'list_price_minor' => null, 'price_list_id' => null, 'tax_minor' => '3167', 'total_minor' => '28500'])],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->cashCdf->id, 'currency' => 'CDF', 'amount_minor' => '28500', 'amount_in_sale_minor' => '28500', 'rate' => null]],
            'change' => ['currency' => 'CDF', 'amount_minor' => '0'],
        ]);

        $this->upload([$body])->assertOk()->assertJsonPath('results.0.flags', []);
        $this->inTenant(function () use ($body) {
            $sale = Sale::findOrFail($body['id']);
            $this->assertSame(['USD', '1000', '111'], [$sale->base_currency, (string) $sale->base_total_minor, (string) $sale->base_tax_minor]);
            $this->assertSame(['2850.00000000', 'USD', 'CDF', 'shop'], [$sale->fx->rate, $sale->fx->base, $sale->fx->quote, $sale->fx->kind]);
        });
    }

    public function test_without_any_rate_to_the_base_the_sale_waits(): void
    {
        $body = $this->saleBody($this->shift, 1, ['currency' => 'KES', 'price_list_id' => null, 'lines' => [$this->line(['price_list_id' => null])]]);

        $this->upload([$body])->assertUnprocessable()
            ->assertJsonPath('results.0.error.code', 'rate_unavailable')
            ->assertJsonPath('results.0.error.retryable', true);
    }
}
