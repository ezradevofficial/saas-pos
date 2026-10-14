<?php

namespace Modules\Inventory\Tests;

use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\CostLayer;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Modules\Procurement\Events\LandedCostAllocated;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-25 (owner decision 2026-10-14): landed costs allocated after a
// receipt adjust its cost by value only: the units still on hand take
// their share, the units already sold give a cost variance; once per
// allocation; zero quantities only for this kind.
class LandedCostAdjustmentTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    private string $receipt;

    private string $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventory();
        $this->receipt = (string) Str::uuid7();
        $this->line = (string) Str::uuid7();
    }

    /** 10 soaps received at KES 1,000.00 by a GRN, then 4 sold. */
    private function receiveAndSell(string $method): void
    {
        $this->costing($method);
        $this->inTenant(fn () => app(StockLedger::class)->post(new Posting($this->acme->id, 'procurement.goods_receipt', $this->receipt, CarbonImmutable::now(), null, [
            new PostingLine($this->soap->id, $this->locationA->id, Movement::RECEIPT, Qty::of('10'), sourceLineId: $this->line, cost: BigInteger::of(100000), setsLastCost: true),
        ], manual: false)));
        $this->move($this->soap, Movement::SALE, '-4', extra: ['source_type' => 'pos.sale']);
    }

    private function allocate(int $amount, string $landedCost): void
    {
        $event = new LandedCostAllocated((string) $this->owner->tenant_id, $landedCost, $this->acme->id, $this->branchA->id, CarbonImmutable::now()->toDateString(), 'KES',
            ['stock_value' => (string) $amount, 'expense' => '0', 'landed_cost' => (string) $amount], [],
            [['receipt_id' => $this->receipt, 'receipt_line_id' => $this->line, 'item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'stock' => true, 'after_receipt' => true, 'base_amount_minor' => (string) $amount]],
            'LC-1');
        event($event);
    }

    public function test_fifo_adds_the_on_hand_share_to_the_receipts_layer_and_the_rest_is_a_variance(): void
    {
        $this->receiveAndSell('fifo');
        $landedCost = (string) Str::uuid7();
        $this->allocate(50000, $landedCost);
        // Delivered twice, applied once.
        $this->allocate(50000, $landedCost);

        $adjustment = $this->inTenant(fn () => Movement::query()->where('kind', Movement::COST_ADJUSTMENT)->sole());
        // 6 of 10 still on hand: 300.00 to stock, 200.00 to the variance (expense).
        $this->assertSame('0', Qty::show((string) $adjustment->qty));
        $this->assertSame(30000, (int) $adjustment->value_minor);
        $this->assertSame(20000, (int) $adjustment->cost_variance_minor);
        $this->assertSame(['qty' => '6', 'value' => '90000'], $this->costState($this->soap));
        $this->assertSame(90000, (int) $this->inTenant(fn () => CostLayer::query()->sole()->remaining_cost_minor));
        // Stock on hand did not move.
        $this->assertSame('6', $this->onHand($this->soap));
    }

    public function test_weighted_average_raises_the_average_for_what_is_held(): void
    {
        $this->receiveAndSell('weighted_average');
        $this->allocate(50000, (string) Str::uuid7());

        $this->assertSame(['qty' => '6', 'value' => '90000'], $this->costState($this->soap));
        $this->assertSame(20000, (int) $this->inTenant(fn () => Movement::query()->where('kind', Movement::COST_ADJUSTMENT)->value('cost_variance_minor')));
    }

    public function test_only_cost_adjustments_may_carry_no_quantity(): void
    {
        $this->expectException(QueryException::class);

        $this->inTenant(fn () => DB::table('inventory_movements')->insert([
            'id' => (string) Str::uuid7(), 'company_id' => $this->acme->id, 'location_id' => $this->locationA->id, 'item_id' => $this->soap->id,
            'kind' => 'receipt', 'qty' => '0', 'value_minor' => 0, 'currency' => 'KES', 'source_type' => 'test', 'source_id' => (string) Str::uuid7(),
            'occurred_at' => now(), 'flags' => '[]',
        ]));
    }
}
