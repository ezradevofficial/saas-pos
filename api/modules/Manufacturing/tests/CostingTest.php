<?php

namespace Modules\Manufacturing\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Listeners\MoveStockForManufacturing;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Qty;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Events\ProductionValued;
use Modules\Manufacturing\Models\Setting;
use Modules\Manufacturing\Tests\Concerns\BuildsManufacturing;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-12, MFG-13, MFG-15, MFG-16, MFG-20, MFG-21: with Inventory on,
// materials leave stock at Inventory's value, reports relieve work in
// progress in integer minor units (shares add up exactly), finished goods
// and by-products enter stock at their cost, returns come back at the
// cost they left at, and what is left at close is the variance.
class CostingTest extends TestCase
{
    use BuildsManufacturing, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpManufacturing(inventory: true);
        $this->breadBom();
        // KES 50.00 a kg of flour, 30.00 a kg of yeast, 1.00 a litre of water.
        $this->receive($this->flour, '200', 1000000);
        $this->receive($this->yeast, '10', 30000);
        $this->receive($this->water, '500', 50000);
        // MFG-29: KES 1,200.00 an hour of labour and 600.00 of overhead.
        $this->inTenant(fn () => Setting::create(['company_id' => $this->acme->id, 'labour_rate_minor' => 120000, 'overhead_rate_minor' => 60000, 'currency' => 'KES']));
    }

    private function money(?array $money): ?string
    {
        return $money['amount_minor'] ?? null;
    }

    public function test_actual_cost_flows_from_issue_to_receipt_and_close_in_exact_minor_units(): void
    {
        Event::fake([ProductionValued::class]);
        $order = $this->released($this->bread, '200');
        $this->assertSame('inventory', $order['ledger']);

        $order = $this->issueAll($order, [$this->flour->id => '102', $this->yeast->id => '1', $this->water->id => '60'])->assertCreated()->json('data');

        // Inventory moved and valued them (weighted average): 510,000 + 3,000 + 6,000.
        $this->assertSame('98', $this->onHand($this->flour));
        $this->assertSame(['production_issue'], array_values(array_unique(array_map(fn (Movement $m) => $m->kind, $this->movements('manufacturing.issue')))));
        $this->assertSame('519000', $this->money($order['material_cost']));
        $this->assertSame('510000', $this->money(collect($order['materials'])->firstWhere('item.code', 'FLOUR')['value']));

        // Report 1: 120 good of 200 → 120/200 of the materials (311,400), 144 minutes of labour
        // (288,000) and overhead (144,000); bran takes 10% rounded down (74,340), bread the rest.
        $bran = collect($order['outputs'])->firstWhere('kind', 'by_product')['id'];
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/reports", [
            'qty_good' => '120', 'qty_scrap' => '4', 'scrap_reason' => 'quality', 'by_products' => [['output_id' => $bran, 'qty' => '6']],
        ])->assertCreated()->json('data');
        $report = $order['reports'][0];

        $this->assertSame('received', $report['status']);
        $this->assertSame(['311400', '288000', '144000', '743400'], [$this->money($report['material_cost']), $this->money($report['labour_cost']), $this->money($report['overhead_cost']), $this->money($report['cost'])]);
        $this->assertSame(['669060', '74340'], array_map(fn ($line) => $this->money($line['cost']), array_values(array_filter($report['lines'], fn ($l) => $l['kind'] !== 'scrap'))));
        $this->assertSame('120', $this->onHand($this->bread));
        $this->assertSame('6', $this->onHand($this->bran));

        // Report 2: 70 good of the 80 still planned → 70/80 of the 207,600 left (181,650), its own 80 minutes.
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '70', 'labour_minutes' => 80])->assertCreated()->json('data');
        $this->assertSame(['181650', '160000', '80000', '421650'], [$this->money($order['reports'][1]['material_cost']), $this->money($order['reports'][1]['labour_cost']), $this->money($order['reports'][1]['overhead_cost']), $this->money($order['reports'][1]['cost'])]);

        // 2 kg of flour back at the cost the issue went out at (10,000).
        $issue = $order['issues'][0];
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/returns", ['issue_id' => $issue['id'], 'reason' => 'unused', 'lines' => [
            ['issue_line_id' => collect($issue['lines'])->firstWhere('item.code', 'FLOUR')['id'], 'qty' => '2'],
        ]])->assertCreated()->json('data');
        $this->assertSame('10000', $this->money($order['returns'][0]['lines'][0]['value']));
        $this->assertSame('509000', $this->money($order['material_cost']));
        $this->assertSame('100', $this->onHand($this->flour));

        // Close: materials 509,000 + labour 448,000 + overhead 224,000 − received 1,165,050 = 15,950 left: the variance.
        $closed = $this->api('POST', "manufacturing/orders/{$order['id']}/close")->assertOk()->json('data');
        $this->assertSame(['509000', '448000', '224000', '1165050', '15950'], [
            $this->money($closed['material_cost']), $this->money($closed['labour_cost']), $this->money($closed['overhead_cost']), $this->money($closed['relieved']), $this->money($closed['variance']),
        ]);

        // Inventory holds the bread at what it cost: 669,060 + 421,650.
        $state = $this->inTenant(fn () => CostState::query()->where('item_id', $this->bread->id)->first());
        $this->assertSame(['190', '1090710'], [Qty::show($state->qty), (string) $state->value_minor]);

        // MFG-20: one valued event per document: the issue, the return, two reports and the close.
        Event::assertDispatchedTimes(ProductionValued::class, 5);
        Event::assertDispatched(ProductionValued::class, fn (ProductionValued $e) => $e->kind === ProductionValued::VARIANCE && $e->varianceMinor === '15950');
        Event::assertDispatched(ProductionValued::class, fn (ProductionValued $e) => $e->kind === ProductionValued::PRODUCTION && $e->labourMinor === '288000'
            && array_column($e->lines, 'value_minor') === ['669060', '74340']);
        Event::assertDispatched(ProductionValued::class, fn (ProductionValued $e) => $e->kind === ProductionValued::MATERIALS && array_sum(array_map('intval', array_column($e->lines, 'value_minor'))) === -519000);
    }

    public function test_a_report_waits_for_its_materials_to_be_valued(): void
    {
        $order = $this->released($this->bread, '100');
        Event::fake([MaterialsMoved::class]);

        $order = $this->issueAll($order, [$this->flour->id => '51'])->assertCreated()->json('data');
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '100'])->assertCreated()->json('data');

        // Inventory has not valued the issue yet: the report waits, and so does closing.
        $this->assertSame('pending', $order['reports'][0]['status']);
        $this->assertNull($order['reports'][0]['cost']);
        $this->api('POST', "manufacturing/orders/{$order['id']}/close")->assertConflict()->assertJsonPath('code', 'valuation_pending');

        // Inventory's job runs (late, and twice): the issue is valued once, then the report costed
        // (51 kg at 5,000 and 120 minutes at 1,200 + 600 an hour); its receipt is the next event.
        $issued = Event::dispatched(MaterialsMoved::class)->map(fn ($call) => $call[0])->first();
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($issued));
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($issued));

        $order = $this->api('GET', "manufacturing/orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame('255000', $this->money($order['material_cost']));
        $this->assertSame(['costed', '615000'], [$order['reports'][0]['status'], $this->money($order['reports'][0]['cost'])]);
        $this->assertCount(1, $this->movements('manufacturing.issue'));
        Event::assertDispatched(MaterialsMoved::class, fn (MaterialsMoved $e) => $e->kind === MaterialsMoved::RECEIPT && $e->lines[0]['cost_minor'] === '615000');
    }

    public function test_costs_are_hidden_without_the_permission(): void
    {
        $order = $this->released($this->bread, '100');
        $this->issueAll($order, [$this->flour->id => '51'])->assertCreated();
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));

        $seen = $this->api('GET', "manufacturing/orders/{$order['id']}", [], $storekeeper)->assertOk()->json('data');
        $this->assertNull($seen['material_cost']);
        $this->assertNull($seen['materials'][0]['value']);
        $this->assertNotNull($this->api('GET', "manufacturing/orders/{$order['id']}")->json('data.material_cost'));
    }

    /** @return list<MaterialsMoved> */
    private function moved(string $kind): array
    {
        return Event::dispatched(MaterialsMoved::class)->map(fn ($call) => $call[0])->filter(fn (MaterialsMoved $e) => $e->kind === $kind)->values()->all();
    }

    public function test_a_return_whose_issue_moved_nothing_comes_back_at_nothing_flagged(): void
    {
        // Review fix 5: the issue posted while flour was not a stock item (nothing left stock);
        // flour is a stock item again when the return comes: it is valued at nothing and
        // flagged, never stuck retrying.
        $order = $this->released($this->bread, '100');
        Event::fake([MaterialsMoved::class]);
        $order = $this->issueAll($order, [$this->flour->id => '10'])->assertCreated()->json('data');
        $this->inTenant(fn () => $this->flour->update(['type' => 'non_stock']));
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($this->moved(MaterialsMoved::ISSUE)[0]));
        $this->inTenant(fn () => $this->flour->update(['type' => 'stock']));

        $issue = $this->api('GET', "manufacturing/orders/{$order['id']}")->assertOk()->json('data.issues.0');
        $this->assertTrue($issue['valued']);
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/returns", ['issue_id' => $issue['id'], 'reason' => 'unused', 'lines' => [
            ['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '2'],
        ]])->assertCreated()->json('data');
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($this->moved(MaterialsMoved::RETURN)[0]));

        $return = $this->api('GET', "manufacturing/orders/{$order['id']}")->assertOk()->json('data.returns.0');
        $this->assertTrue($return['valued']);
        $this->assertSame('0', $this->money($return['lines'][0]['value']));
        $this->assertSame([['code' => 'nothing_to_return', 'line_id' => $return['lines'][0]['id']]], $return['flags']);
        $this->assertSame('200', $this->onHand($this->flour));
    }

    public function test_a_stuck_document_is_moved_and_valued_again_by_an_administrator(): void
    {
        $order = $this->released($this->bread, '100');
        Event::fake([MaterialsMoved::class]);
        // Inventory's job for the issue gave up: the report waits for it.
        $order = $this->issueAll($order, [$this->flour->id => '51'])->assertCreated()->json('data');
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '100'])->assertCreated()->json('data');
        $issue = $order['issues'][0];
        $report = $order['reports'][0];
        $this->assertSame('pending', $report['status']);

        // manufacturing.settings.edit at the company: a branch manager is refused; unknown documents are not found.
        $this->api('POST', 'manufacturing/revalue', ['kind' => 'issue', 'document_id' => $issue['id']], $this->planner)->assertForbidden();
        $this->api('POST', 'manufacturing/revalue', ['kind' => 'return', 'document_id' => $issue['id']])->assertUnprocessable()->assertJsonValidationErrors('document_id');
        $this->api('POST', 'manufacturing/revalue', ['kind' => 'issue', 'document_id' => $issue['id']])->assertAccepted();

        $again = $this->moved(MaterialsMoved::ISSUE);
        $this->assertCount(2, $again);
        $this->assertSame($again[0]->lines, $again[1]->lines);
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($again[1]));

        $order = $this->api('GET', "manufacturing/orders/{$order['id']}")->assertOk()->json('data');
        $this->assertSame('255000', $this->money($order['material_cost']));
        $this->assertSame('costed', $order['reports'][0]['status']);
        $this->api('POST', 'manufacturing/revalue', ['kind' => 'issue', 'document_id' => $issue['id']])->assertConflict()->assertJsonPath('code', 'nothing_to_revalue');

        // The report's receipt never came back either: raised again, then received once.
        $this->api('POST', 'manufacturing/revalue', ['kind' => 'report', 'document_id' => $report['id']])->assertAccepted();
        $receipts = $this->moved(MaterialsMoved::RECEIPT);
        $this->assertCount(2, $receipts);
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($receipts[1]));
        $this->inTenant(fn () => app(MoveStockForManufacturing::class)->handle($receipts[0]));
        $this->assertSame('received', $this->api('GET', "manufacturing/orders/{$order['id']}")->json('data.reports.0.status'));
        $this->assertSame('100', $this->onHand($this->bread));
        $this->assertCount(1, $this->movements('manufacturing.receipt'));
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.document.revalue')->count()));
    }
}
