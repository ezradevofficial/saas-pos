<?php

namespace Modules\Procurement\Tests;

use Illuminate\Support\Facades\Event;
use Modules\Inventory\Contracts\GoodsReceived;
use Modules\Procurement\Documents\LandedCostAllocator;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Events\LandedCostAllocated;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PRC-17: landed costs split over GRN lines by value, quantity or weight in
// integer minor units (largest remainder, nothing lost), into the cost a
// draft GRN posts with, or raised as LandedCostAllocated after receipt.
class LandedCostsTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    public function test_the_allocator_never_loses_or_invents_a_minor_unit(): void
    {
        // 100 over 1:1:1 → 34, 33, 33 (ties to the earlier part).
        $this->assertSame(['a' => 34, 'b' => 33, 'c' => 33], LandedCostAllocator::allocate(100, ['a' => '1', 'b' => '1', 'c' => '1']));
        // Decimal bases (weights): 0.5 kg and 1.25 kg share 1,001 → 286 and 715.
        $this->assertSame(['x' => 286, 'y' => 715], LandedCostAllocator::allocate(1001, ['x' => '0.5', 'y' => '1.25']));
        // A zero basis gets nothing.
        $this->assertSame(['x' => 0, 'y' => 7], LandedCostAllocator::allocate(7, ['x' => '0', 'y' => '3']));
        // CDF has no decimals; big totals stay exact.
        $parts = LandedCostAllocator::allocate(987654321, ['a' => '3', 'b' => '7', 'c' => '11', 'd' => '13']);
        $this->assertSame(987654321, array_sum($parts));

        // Property: any split sums exactly and each part is within one unit of its exact share.
        mt_srand(17);
        for ($run = 0; $run < 200; $run++) {
            $total = mt_rand(0, 5000000);
            $bases = [];
            for ($i = 0; $i < mt_rand(1, 8); $i++) {
                $bases["l{$i}"] = (string) (mt_rand(0, 100000) / 1000);
            }
            if (array_sum(array_map('floatval', $bases)) == 0) {
                continue;
            }
            $parts = LandedCostAllocator::allocate($total, $bases);
            $this->assertSame($total, array_sum($parts));
            $sum = array_sum(array_map('floatval', $bases));
            foreach ($parts as $key => $part) {
                $this->assertLessThanOrEqual(1.0001, abs($part - $total * floatval($bases[$key]) / $sum));
            }
        }
    }

    public function test_a_landed_cost_over_posted_and_draft_grns_by_value_quantity_and_weight(): void
    {
        Event::fake([LandedCostAllocated::class, GoodsReceiptPosted::class, GoodsReceived::class]);
        $this->setUpProcurement();
        $order = $this->approvedOrder([], [
            ['item_id' => $this->soap->id, 'qty' => '10', 'unit_price_minor' => '10000'],
            ['item_id' => $this->delivery->id, 'qty' => '1', 'unit_price_minor' => '30000'],
        ]);
        [$soap, $delivery] = $order['lines'];
        // A posted GRN (soap 6, delivery 1) and a draft one (soap 4, weights typed).
        $posted = $this->postedReceipt($order, [['order_line_id' => $soap['id'], 'received_qty' => '6', 'weight' => '3'], ['order_line_id' => $delivery['id'], 'received_qty' => '1', 'weight' => '1']]);
        $draft = $this->api('POST', 'procurement/receipts', ['order_id' => $order['id'], 'lines' => [['order_line_id' => $soap['id'], 'received_qty' => '4', 'weight' => '2']]])->assertCreated()->json('data');

        // Freight USD 10.00 (KES 1,300.00) and duty KES 700.00: KES 2,000.00 in all.
        $body = ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'value', 'description' => 'Container 7',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'currency' => 'USD', 'amount_minor' => '1000'],
                ['description' => 'Duty', 'currency' => 'KES', 'amount_minor' => '70000']],
            'receipt_ids' => [$posted['id'], $draft['id']]];
        $landed = $this->api('POST', 'procurement/landed-costs', $body)->assertCreated()->json('data');
        // By value: 600.00, 300.00 and 400.00 (the draft at the PO price) share 2,000.00.
        $this->assertSame(['92308', '46154', '61538'], array_map(fn ($l) => $l['amount']['amount_minor'], $landed['allocation']['lines']));
        $this->assertSame('200000', $landed['allocation']['total']['amount_minor']);

        $byQty = $this->api('PATCH', "procurement/landed-costs/{$landed['id']}", ['method' => 'quantity'])->assertOk()->json('data');
        // 6, 1 and 4 units share 2,000.00: 1,090.91, 181.82, 727.27.
        $this->assertSame(['109091', '18182', '72727'], array_map(fn ($l) => $l['amount']['amount_minor'], $byQty['allocation']['lines']));
        $byWeight = $this->api('PATCH', "procurement/landed-costs/{$landed['id']}", ['method' => 'weight'])->assertOk()->json('data');
        // 3, 1 and 2 kg: 1,000.00, 333.33, 666.67.
        $this->assertSame(['100000', '33333', '66667'], array_map(fn ($l) => $l['amount']['amount_minor'], $byWeight['allocation']['lines']));

        $done = $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertOk()->json('data');
        $this->assertSame('posted', $done['status']);
        $this->assertSame('200000', $done['base_total']['amount_minor']);
        // The posted GRN's lines carry their share at once; the draft takes its own when posted.
        $this->assertSame(['100000', '33333'], array_map(fn ($l) => $l['landed']['amount_minor'], $this->api('GET', "procurement/receipts/{$posted['id']}")->json('data.lines')));
        Event::assertDispatched(LandedCostAllocated::class, fn (LandedCostAllocated $e) => $e->amounts === ['stock_value' => '100000', 'expense' => '33333', 'landed_cost' => '133333']
            && count($e->charges) === 2 && $e->charges[0]['base_amount_minor'] === '130000' && count($e->allocations) === 3);

        $this->api('POST', "procurement/receipts/{$draft['id']}/post")->assertOk();
        $this->assertSame('66667', $this->api('GET', "procurement/receipts/{$draft['id']}")->json('data.lines.0.landed.amount_minor'));
        Event::assertDispatched(GoodsReceiptPosted::class, fn (GoodsReceiptPosted $e) => $e->receiptId === $draft['id']
            && $e->amounts === ['stock_value' => '106667', 'expense' => '0', 'grni' => '40000', 'landed_cost' => '66667']);
        // A posted landed cost is final.
        $this->api('PATCH', "procurement/landed-costs/{$landed['id']}", ['method' => 'value'])->assertStatus(409);
    }

    public function test_weight_must_be_typed_to_allocate_by_weight(): void
    {
        $this->setUpProcurement();
        $order = $this->approvedOrder();
        $receipt = $this->postedReceipt($order);
        $landed = $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'weight',
            'charges' => [['description' => 'Freight', 'currency' => 'KES', 'amount_minor' => '1000']], 'receipt_ids' => [$receipt['id']]])->assertCreated()->json('data');

        $this->assertSame('weight_missing', $landed['allocation']['error']['code']);
        $this->api('POST', "procurement/landed-costs/{$landed['id']}/post")->assertUnprocessable()->assertJsonPath('code', 'weight_missing');
    }
}
