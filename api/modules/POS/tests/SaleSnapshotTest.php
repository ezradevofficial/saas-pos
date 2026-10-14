<?php

namespace Modules\POS\Tests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use Illuminate\Support\Facades\Event;
use Modules\POS\Events\SaleCompleted;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Events\SaleVoided;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\BuildsExchangeRates;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// POS-13, CUR-04, CUR-06 (stage 7B, Accounting integrations): the event
// snapshots carry what a journal needs without reading POS tables: per
// line the tax code, inclusive flag, net, tax and the discount by source;
// the rounding, the change and its share of the sale currency; the base
// currency, rate and base amounts; the customer and location; the void's
// date; and the same per-line fields on a refund.
class SaleSnapshotTest extends TestCase
{
    use BuildsExchangeRates, BuildsPos, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->ranges()->assertOk();
        $this->ranges('pos.refund')->assertOk();
        $this->shift = $this->openShift();
    }

    public function test_a_sale_snapshot_carries_lines_discount_sources_change_rounding_and_base(): void
    {
        $customer = $this->customer($this->acme->id);
        $line = $this->line(['discount_minor' => '10000', 'tax_minor' => '11389', 'total_minor' => '102500', 'adjustments' => [
            ['source' => 'loyalty.promotion', 'reference' => 'promo-1', 'amount_minor' => '6000'],
        ]]);
        $sale = $this->saleBody($this->shift, 1, ['customer_id' => $customer->id, 'lines' => [$line], 'payments' => [[
            'id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES',
            'amount_minor' => '110000', 'amount_in_sale_minor' => '110000', 'rate' => null, 'status' => 'confirmed',
        ]], 'change' => ['currency' => 'KES', 'amount_minor' => '7500', 'rate' => null]]);

        Event::fake([SaleCompleted::class]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        Event::assertDispatched(SaleCompleted::class, function (SaleCompleted $event) use ($sale, $customer) {
            $s = $event->sale;
            $l = $s['lines'][0];

            $this->assertSame([$customer->id, $this->locationA->id, 'completed', null], [$s['customer_id'], $s['location_id'], $s['status'], $s['voided_at']]);
            $this->assertSame(['102500', '110000', '0'], [$s['totals']['total_minor'], $s['totals']['paid_minor'], $s['totals']['rounding_minor']]);
            $this->assertSame(['currency' => 'KES', 'amount_minor' => '7500', 'in_sale_minor' => '7500'], $s['change']);
            $this->assertSame(['KES', '102500', '11389'], [$s['base']['currency'], $s['base']['total_minor'], $s['base']['tax_minor']]);
            $this->assertSame('1.00000000', $s['base']['fx']['rate']);
            $this->assertSame([$this->vat->id, true, '91111', '11389', '102500'], [$l['tax_code_id'], $l['tax_inclusive'], $l['net_minor'], $l['tax_minor'], $l['total_minor']]);
            // Review #4: no module vouches for `loyalty.promotion` here, so the till's claim is a manual discount.
            $this->assertSame(['total_minor' => '10000', 'sources' => [], 'manual_minor' => '10000'], $l['discount']);

            return $event->saleId === $sale['id'];
        });
    }

    public function test_change_in_another_currency_carries_its_share_of_the_sale_currency(): void
    {
        $this->congoCurrencies();
        $this->rate('USD', 'CDF', '2850', at: '-1 day');
        $cdf = $this->inTenant(fn () => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'cash', 'name' => 'Cash CDF', 'currency' => 'CDF', 'active' => true, 'position' => 4]));
        $rate = ['rate' => '2850', 'base' => 'USD', 'quote' => 'CDF', 'kind' => 'shop', 'effective_at' => now()->subDay()->toIso8601String()];
        $sale = $this->saleBody($this->shift, 1, [
            'currency' => 'USD', 'price_list_id' => null,
            'lines' => [$this->line(['qty' => '1', 'unit_price_minor' => '850', 'list_price_minor' => null, 'price_list_id' => null, 'tax_minor' => '94', 'total_minor' => '850'])],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $cdf->id, 'currency' => 'CDF', 'amount_minor' => '30000', 'amount_in_sale_minor' => '1052', 'rate' => $rate, 'status' => 'confirmed']],
            'change' => ['currency' => 'CDF', 'amount_minor' => '5750', 'rate' => $rate],
        ]);

        Event::fake([SaleCompleted::class]);
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');

        Event::assertDispatched(SaleCompleted::class, function (SaleCompleted $event) {
            $s = $event->sale;
            // Paid 1052 − change 201 = total 850 + rounding 1, exactly.
            $this->assertSame(['currency' => 'CDF', 'amount_minor' => '5750', 'in_sale_minor' => '201'], $s['change']);
            $this->assertSame('1', $s['totals']['rounding_minor']);
            $this->assertSame(['CDF', '30000', '1052'], [$s['payments'][0]['currency'], $s['payments'][0]['amount_minor'], $s['payments'][0]['amount_in_sale_minor']]);
            $this->assertSame('2850.00000000', $s['payments'][0]['fx']['rate']);

            return true;
        });
    }

    public function test_a_void_carries_its_date_and_a_refund_its_lines_shares(): void
    {
        $line = $this->line(['discount_minor' => '10000', 'tax_minor' => '11389', 'total_minor' => '102500', 'adjustments' => [
            ['source' => 'loyalty.promotion', 'reference' => 'promo-1', 'amount_minor' => '10000'],
        ]]);
        $first = $this->saleBody($this->shift, 1, ['lines' => [$line]]);
        $second = $this->saleBody($this->shift, 2);
        $this->upload([$first, $second])->assertOk();

        Event::fake([SaleVoided::class, SaleRefunded::class]);
        $voidedAt = now()->subMinute()->toIso8601String();
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $second['id'], 'voided_by_id' => $this->owner->id, 'voided_at' => $voidedAt,
            'reason' => 'Wrong item', 'override' => $this->override($this->owner->id),
        ]]], $this->tillHeaders())->assertOk();

        Event::assertDispatched(SaleVoided::class, fn (SaleVoided $e) => $e->saleId === $second['id']
            && substr((string) $e->voidedAt, 0, 19) === \Carbon\CarbonImmutable::parse($voidedAt)->utc()->format('Y-m-d\TH:i:s')
            && $e->sale['status'] === 'voided');

        // Half the line back: KES 512.50 of KES 1,025.00, its share of tax and discount.
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $first['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
            'receipt_seq' => 1, 'receipt_number' => 'RF-L01-000001', 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged', 'total_minor' => '51250',
            'lines' => [['id' => $this->id(), 'sale_line_id' => $first['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => '51250', 'amount_in_sale_minor' => '51250', 'status' => 'confirmed']],
            'override' => $this->override($this->owner->id),
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');

        Event::assertDispatched(SaleRefunded::class, function (SaleRefunded $e) {
            $line = $e->refund['lines'][0];
            $this->assertSame([$this->soap->id, $this->vat->id, true], [$line['item_id'], $line['tax_code_id'], $line['tax_inclusive']]);
            $this->assertSame(['51250', '5695', '45555'], [$line['total_minor'], $line['tax_minor'], $line['net_minor']]);
            $this->assertSame(['total_minor' => '5000', 'sources' => [], 'manual_minor' => '5000'], $line['discount']);
            $this->assertSame(['0', $this->locationA->id, 'KES'], [$e->refund['rounding_minor'], $e->refund['location_id'], $e->refund['base']['currency']]);

            return true;
        });
    }

    /** Review #8: refunds of one line in parts take cumulative shares of its discount, which add up to it exactly. */
    public function test_partial_refunds_take_cumulative_shares_of_the_discount(): void
    {
        // Three soaps, KES 100.00 off: KES 1,587.50.
        $line = $this->line(['qty' => '3', 'discount_minor' => '10000', 'tax_minor' => '17639', 'total_minor' => '158750']);
        $sale = $this->saleBody($this->shift, 1, ['lines' => [$line]]);
        $this->upload([$sale])->assertOk();
        $shares = [];
        Event::listen(SaleRefunded::class, function (SaleRefunded $e) use (&$shares) {
            $shares[] = $e->refund['lines'][0]['discount']['total_minor'];
        });

        foreach ([['52917', 1], ['52916', 2], ['52917', 3]] as [$total, $seq]) {
            $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
                'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->shift, 'cashier_id' => $this->owner->id,
                'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(), 'reason' => 'Damaged', 'total_minor' => $total,
                'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
                'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total, 'status' => 'confirmed']],
                'override' => $this->override($this->owner->id),
            ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');
        }

        $this->assertSame(['3333', '3334', '3333'], $shares);
    }
}
