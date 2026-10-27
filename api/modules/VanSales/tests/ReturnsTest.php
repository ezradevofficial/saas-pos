<?php

namespace Modules\VanSales\Tests;

use Modules\VanSales\Credit\Ledger;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\StockLine;
use Modules\VanSales\Models\Tender;
use Modules\VanSales\Models\VanSale;
use Modules\VanSales\Tests\Concerns\BuildsVanSales;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// VAN-16, VAN-19, VAN-20: returns and exchanges from customers on the
// route: a POS refund at the van brings the goods back into the run's load
// and takes the money (or credit) out of what is expected.
class ReturnsTest extends TestCase
{
    use BuildsVanSales, RefreshTenantDatabase;

    private string $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpVanSales();
        $this->run = $this->startRun();
        $this->loadVan('10', $this->run);
        $this->ranges('pos.refund', null, $this->vanToken)->assertOk();
    }

    private function refund(array $sale, string $method, string $total = '56250', int $seq = 1): void
    {
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->vanShift, 'cashier_id' => $this->rep->id, 'actor_proof' => $this->actorProof($this->rep->id, $this->vanDevice),
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-V01-%06d', $seq), 'refunded_at' => now()->toIso8601String(),
            'reason' => 'Damaged', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][0]['id'], 'qty' => '1']],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods[$method]->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
            'override' => $this->override($this->manager->id),
        ]]], $this->tillHeaders($this->vanToken))->assertOk()->assertJsonPath('results.0.status', 'stored');
    }

    public function test_a_cash_return_brings_the_goods_back_and_takes_the_cash_out(): void
    {
        $sale = $this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4');
        $this->sellOnVan($sale)->assertOk();

        $this->refund($sale, 'cash_kes');

        $this->inTenant(function () {
            $this->assertSame('1.000000', (string) StockLine::query()->where('run_id', $this->run)->where('kind', 'customer_return')->sole()->qty);
            $this->assertSame(168750, (int) Tender::query()->where('run_id', $this->run)->sum('amount_minor'));
        });

        // load 10 + 1 back = 11; sold 4; 7 counted back; cash 168,750.
        $count = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '7', 'damaged_qty' => '0']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '168750']]);
        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'balanced');
        $this->inTenant(fn () => $this->assertSame(0, Count::findOrFail($count['id'])->stock_variance_lines));
    }

    public function test_a_return_of_goods_found_damaged_waits_for_a_decision(): void
    {
        $sale = $this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '225000')], '4');
        $this->sellOnVan($sale)->assertOk();
        $this->refund($sale, 'cash_kes');

        $count = $this->countRecord($this->run, [['item_id' => $this->soap->id, 'returned_qty' => '6', 'damaged_qty' => '1']], [['kind' => 'cash', 'currency' => 'KES', 'counted_minor' => '168750']]);

        $this->vsSync(['counts' => [$count]])->assertOk()->assertJsonPath('results.counts.0.reconciliation.status', 'pending')->assertJsonPath('results.counts.0.reconciliation.stock_variance_lines', 0);
    }

    public function test_a_return_paid_back_as_credit_lowers_the_balance(): void
    {
        $sale = $this->vanSale($this->run, 1, $this->shop, [$this->pay('credit', '225000')], '4');
        $this->sellOnVan($sale)->assertOk();

        $this->refund($sale, 'credit', '56250');

        $this->inTenant(fn () => $this->assertSame(['KES' => 168750], app(Ledger::class)->balances($this->shop->id)));
    }

    public function test_an_exchange_is_a_return_and_a_sale_that_names_it(): void
    {
        $sale = $this->vanSale($this->run, 1, null, [$this->pay('cash_kes', '112500')], '2');
        $this->sellOnVan($sale)->assertOk();
        $this->refund($sale, 'cash_kes');
        $exchange = $this->vanSale($this->run, 2, null, [$this->pay('cash_kes', '56250')], '1', ['exchange_for_sale_id' => $sale['id']]);

        $this->sellOnVan($exchange)->assertOk();

        $this->inTenant(function () use ($exchange, $sale) {
            $this->assertSame($sale['id'], VanSale::query()->where('sale_id', $exchange['id'])->sole()->exchange_for_sale_id);
            $this->assertSame(112500, (int) Tender::query()->where('run_id', $this->run)->sum('amount_minor'));
        });
    }
}
