<?php

namespace Modules\Inventory\Tests;

use App\Core\Http\ApiException;
use Illuminate\Support\Str;
use Modules\Inventory\Models\CostLayer;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-12, INV-13: costing per company, FIFO layers and weighted average,
// returns at their original cost, negative stock costed at the last
// receipt's cost with the variance kept, all in whole minor units.
class CostingTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInventory();
    }

    public function test_weighted_average_takes_its_share_of_the_value_and_all_of_it_at_the_end(): void
    {
        $this->costing(InventorySetting::WEIGHTED_AVERAGE);

        $this->move($this->soap, Movement::RECEIPT, '10', 1000);
        $this->move($this->soap, Movement::RECEIPT, '10', 3000);
        $this->assertSame(['qty' => '20', 'value' => '4000'], $this->costState($this->soap));

        [$out] = $this->move($this->soap, Movement::SALE, '-5', manual: false);
        $this->assertSame('-1000', (string) $out->value_minor);

        // Three units of a 3000/15 average: 600; the last 12 take what is left exactly.
        $this->assertSame('-600', (string) $this->move($this->soap, Movement::SALE, '-3', manual: false)[0]->value_minor);
        $this->assertSame('-2400', (string) $this->move($this->soap, Movement::SALE, '-12', manual: false)[0]->value_minor);
        $this->assertSame(['qty' => '0', 'value' => '0'], $this->costState($this->soap));
    }

    public function test_weighted_average_returns_come_back_at_the_cost_they_went_out_at(): void
    {
        $this->costing(InventorySetting::WEIGHTED_AVERAGE);

        $this->move($this->soap, Movement::RECEIPT, '20', 4000);
        [$sale] = $this->move($this->soap, Movement::SALE, '-5', manual: false);
        $this->assertSame('-1000', (string) $sale->value_minor);

        // The average rises to 8000/25 = 320 a unit ...
        $this->move($this->soap, Movement::RECEIPT, '10', 5000);
        $this->assertSame(['qty' => '25', 'value' => '8000'], $this->costState($this->soap));

        // ... but two units returned from the sale come back at 200 each, not 320.
        [$return] = $this->move($this->soap, Movement::SALE_RETURN, '2', 400, extra: ['reverses' => $sale->id], manual: false);
        $this->assertSame('400', (string) $return->value_minor);
        $this->assertSame(['qty' => '27', 'value' => '8400'], $this->costState($this->soap));
        $this->assertSame('27', $this->onHand($this->soap));
    }

    public function test_fifo_consumes_the_oldest_layers_first(): void
    {
        $this->costing(InventorySetting::FIFO);

        $this->move($this->soap, Movement::RECEIPT, '10', 1000);
        $this->move($this->soap, Movement::RECEIPT, '10', 2000);

        // 10 at 100 and 5 at 200.
        $this->assertSame('-2000', (string) $this->move($this->soap, Movement::SALE, '-15', manual: false)[0]->value_minor);
        $this->assertSame(['qty' => '5', 'value' => '1000'], $this->costState($this->soap));

        $layers = $this->inTenant(fn () => CostLayer::query()->orderBy('received_at')->get(['remaining_qty', 'remaining_cost_minor'])
            ->map(fn ($l) => [(string) $l->remaining_qty, (string) $l->remaining_cost_minor])->all());
        $this->assertSame([['0.000000', '0'], ['5.000000', '1000']], $layers);

        $this->assertSame('-1000', (string) $this->move($this->soap, Movement::SALE, '-5', manual: false)[0]->value_minor);
    }

    public function test_fifo_never_drifts_on_a_layer_that_does_not_divide_evenly(): void
    {
        $this->costing(InventorySetting::FIFO);

        $this->move($this->soap, Movement::RECEIPT, '3', 1000);
        $values = [];

        foreach ([1, 1, 1] as $qty) {
            $values[] = (int) $this->move($this->soap, Movement::SALE, (string) -$qty, manual: false)[0]->value_minor;
        }

        // 333, then half of 667 rounded up, then exactly what is left.
        $this->assertSame([-333, -334, -333], $values);
        $this->assertSame(-1000, array_sum($values));
        $this->assertSame(['qty' => '0', 'value' => '0'], $this->costState($this->soap));
    }

    public function test_fifo_returns_form_a_layer_at_their_original_cost(): void
    {
        $this->costing(InventorySetting::FIFO);

        $this->move($this->soap, Movement::RECEIPT, '10', 1000);
        [$sale] = $this->move($this->soap, Movement::SALE, '-4', manual: false);
        $this->move($this->soap, Movement::RECEIPT, '10', 3000);
        $this->move($this->soap, Movement::SALE_RETURN, '4', 400, extra: ['reverses' => $sale->id], manual: false);

        // Layers: 6 at 100, 10 at 300, then the 4 returned at 100 (the newest).
        $this->assertSame(['qty' => '20', 'value' => '4000'], $this->costState($this->soap));
        $this->assertSame('-1800', (string) $this->move($this->soap, Movement::SALE, '-10', manual: false)[0]->value_minor);
        // The 6 left at 300, then the returned 4 at 100.
        $this->assertSame('-2200', (string) $this->move($this->soap, Movement::SALE, '-10', manual: false)[0]->value_minor);
    }

    public function test_a_shortfall_is_costed_at_the_last_receipt_and_the_receipt_that_covers_it_keeps_the_difference(): void
    {
        foreach ([InventorySetting::FIFO, InventorySetting::WEIGHTED_AVERAGE] as $method) {
            $item = $method === InventorySetting::FIFO ? $this->soap : $this->sugar;
            $this->costing($method);

            $this->move($item, Movement::RECEIPT, '10', 1000);
            [$sale] = $this->move($item, Movement::SALE, '-12', manual: false);
            // 10 held at 100, 2 short at the last receipt's 100.
            $this->assertSame('-1200', (string) $sale->value_minor, $method);
            $this->assertContains('costed_at_last_cost', $sale->flagCodes(), $method);
            $this->assertContains('negative_stock', $sale->flagCodes(), $method);
            $this->assertSame(['qty' => '-2', 'value' => '-200'], $this->costState($item), $method);

            // The 2 short really cost 150 each: the receipt keeps 2 × 50 as its variance.
            [$receipt] = $this->move($item, Movement::RECEIPT, '4', 600);
            $this->assertSame('600', (string) $receipt->value_minor, $method);
            $this->assertSame('100', (string) $receipt->cost_variance_minor, $method);
            $this->assertSame(['qty' => '2', 'value' => '300'], $this->costState($item), $method);

            $this->inTenant(fn () => InventorySetting::query()->delete());
        }
    }

    public function test_an_issue_with_no_cost_known_is_costed_at_zero_and_flagged(): void
    {
        [$sale] = $this->move($this->soap, Movement::SALE, '-3', manual: false);

        $this->assertSame('0', (string) $sale->value_minor);
        $this->assertSame(['cost_unknown', 'negative_stock'], $sale->flagCodes());
        $this->assertSame('-3', $this->onHand($this->soap));
    }

    public function test_an_increase_without_a_cost_takes_the_current_average(): void
    {
        $this->move($this->soap, Movement::RECEIPT, '10', 1500);
        [$found] = $this->move($this->soap, Movement::ADJUSTMENT_IN, '2');

        $this->assertSame('300', (string) $found->value_minor);
        $this->assertSame(['cost_estimated'], $found->flagCodes());
    }

    public function test_moves_between_locations_do_not_change_the_company_cost(): void
    {
        $this->move($this->soap, Movement::RECEIPT, '10', 1000);
        $transfer = (string) Str::uuid7();
        [$out] = $this->move($this->soap, Movement::TRANSFER_OUT, '-4', source: $transfer);
        $this->move($this->soap, Movement::TRANSFER_IN, '4', location: $this->locationB->id, source: $transfer);

        $this->assertSame('-400', (string) $out->value_minor);
        $this->assertSame(['qty' => '10', 'value' => '1000'], $this->costState($this->soap));
        $this->assertSame(['6', '4'], [$this->onHand($this->soap), $this->onHand($this->soap, $this->locationB->id)]);
    }

    public function test_manual_issues_below_zero_are_refused_when_the_company_does_not_allow_it_and_sales_are_not(): void
    {
        $this->costing(InventorySetting::WEIGHTED_AVERAGE, allowNegative: false);
        $this->move($this->soap, Movement::RECEIPT, '2', 200);

        try {
            $this->move($this->soap, Movement::ADJUSTMENT_OUT, '-3');
            $this->fail('A manual issue below zero was posted.');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }

        $this->assertSame('2', $this->onHand($this->soap));

        // INV-13: a sale is never refused for lack of stock.
        [$sale] = $this->move($this->soap, Movement::SALE, '-3', manual: false);
        $this->assertContains('negative_stock', $sale->flagCodes());
        $this->assertSame('-1', $this->onHand($this->soap));
    }

    public function test_the_costing_method_is_fixed_by_the_first_movement(): void
    {
        $this->move($this->soap, Movement::RECEIPT, '1', 100);

        $settings = $this->inTenant(fn () => InventorySetting::query()->where('company_id', $this->acme->id)->sole());
        $this->assertSame(InventorySetting::WEIGHTED_AVERAGE, $settings->costing_method);
        $this->assertNotNull($settings->costing_locked_at);
    }
}
