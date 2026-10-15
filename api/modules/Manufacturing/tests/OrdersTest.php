<?php

namespace Modules\Manufacturing\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Inventory\Models\Movement;
use Modules\Manufacturing\Events\MaterialsMoved;
use Modules\Manufacturing\Events\ProductionOrderClosed;
use Modules\Manufacturing\Events\ProductionOrderReleased;
use Modules\Manufacturing\Events\ProductionReported;
use Modules\Manufacturing\Models\Setting;
use Modules\Manufacturing\Tests\Concerns\BuildsManufacturing;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-10 to MFG-14, MFG-17, MFG-21, MFG-25, MFG-28: production orders
// planned from their BOM version, released (with approval where the
// company asks), materials issued and returned, production reported in
// parts, closed or cancelled; quantity-only without Inventory.
class OrdersTest extends TestCase
{
    use BuildsManufacturing, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpManufacturing();
        $this->breadBom();
    }

    /** @return array<string, string> item code => base quantity */
    private function materials(array $order, string $field = 'qty_required'): array
    {
        return collect($order['materials'])->mapWithKeys(fn ($m) => [$m['item']['code'] => $m[$field]])->all();
    }

    public function test_an_order_explodes_its_version_into_materials_and_outputs(): void
    {
        $order = $this->plan($this->bread, '250', ['planned_start' => now()->toDateString(), 'note' => 'Morning batch']);

        $this->assertSame('planned', $order['status']);
        $this->assertMatchesRegularExpression('/^MO-.+-\d{4}-00001$/', $order['number']);
        $this->assertSame(['FLOUR' => '127.5', 'YEAST' => '1.25', 'WATER' => '75'], $this->materials($order));
        $this->assertSame(['FLOUR' => '2.5', 'YEAST' => '0', 'WATER' => '0'], $this->materials($order, 'expected_loss'));
        $this->assertSame([['product', 'BREAD', '250'], ['by_product', 'BRAN', '12.5']], array_map(fn ($o) => [$o['kind'], $o['item']['code'], $o['qty_planned']], $order['outputs']));

        // While planned, the quantity changes and the materials follow.
        $updated = $this->api('PUT', "manufacturing/orders/{$order['id']}", ['qty' => '100'])->assertOk()->json('data');
        $this->assertSame(['FLOUR' => '51', 'YEAST' => '0.5', 'WATER' => '30'], $this->materials($updated));
        $this->assertSame(1, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.order.update')->count()));
    }

    public function test_an_order_runs_from_release_to_close_in_parts_without_inventory(): void
    {
        Event::fake([MaterialsMoved::class, ProductionOrderReleased::class, ProductionReported::class, ProductionOrderClosed::class]);
        $order = $this->released($this->bread, '200');

        // MFG-17: released while Inventory is off: quantities only.
        $this->assertSame('none', $order['ledger']);
        Event::assertDispatched(ProductionOrderReleased::class);

        $this->issueAll($order, [$this->flour->id => '60', $this->yeast->id => '1'])->assertCreated()->assertJsonPath('data.status', 'in_progress');
        $order = $this->issueAll($order, [$this->flour->id => '45', $this->water->id => '60'])->assertCreated()->json('data');
        $this->assertSame(['FLOUR' => '105', 'YEAST' => '1', 'WATER' => '60'], $this->materials($order, 'qty_issued'));

        // Partial completion: two reports, scrap with its reason, a by-product.
        $bran = collect($order['outputs'])->firstWhere('kind', 'by_product')['id'];
        $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '120', 'qty_scrap' => '4'])
            ->assertUnprocessable()->assertJsonPath('code', 'scrap_reason_required');
        $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '120', 'qty_scrap' => '4', 'scrap_reason' => 'quality', 'by_products' => [['output_id' => $bran, 'qty' => '6']]])
            ->assertCreated()->assertJsonPath('data.qty_good', '120')->assertJsonPath('data.reports.0.status', 'recorded')
            ->assertJsonPath('data.reports.0.labour_minutes', 144);
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '70', 'labour_minutes' => 80])->assertCreated()->json('data');

        $this->assertSame('in_progress', $order['status']);
        $this->assertSame(['190', '4'], [$order['qty_good'], $order['qty_scrap']]);
        $this->assertSame('6', collect($order['outputs'])->firstWhere('kind', 'by_product')['qty_reported']);
        $this->assertNull($order['material_cost']);

        // Unused flour back, never more than issued less returned.
        $issue = $order['issues'][0];
        $flourLine = collect($issue['lines'])->firstWhere('item.code', 'FLOUR');
        $this->api('POST', "manufacturing/orders/{$order['id']}/returns", ['issue_id' => $issue['id'], 'reason' => 'unused', 'lines' => [['issue_line_id' => $flourLine['id'], 'qty' => '61']]])
            ->assertUnprocessable()->assertJsonPath('code', 'over_return');
        $order = $this->api('POST', "manufacturing/orders/{$order['id']}/returns", ['issue_id' => $issue['id'], 'reason' => 'unused', 'lines' => [['issue_line_id' => $flourLine['id'], 'qty' => '3']]])
            ->assertCreated()->json('data');
        $this->assertSame('102', collect($order['materials'])->firstWhere('item.code', 'FLOUR')['qty_consumed']);

        $closed = $this->api('POST', "manufacturing/orders/{$order['id']}/close", ['note' => 'Short by ten'])->assertOk()->json('data');
        $this->assertSame('closed', $closed['status']);
        $this->assertNull($closed['variance']);
        $this->api('POST', "manufacturing/orders/{$order['id']}/issues", ['lines' => [['material_id' => $order['materials'][0]['id'], 'qty' => '1']]])
            ->assertConflict()->assertJsonPath('code', 'document_not_open');

        // Nothing went to Inventory.
        Event::assertNotDispatched(MaterialsMoved::class);
        Event::assertDispatchedTimes(ProductionReported::class, 2);
        Event::assertDispatched(ProductionOrderClosed::class);
        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->count()));
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.issue.create')->count()));
        $this->assertSame(2, $this->inTenant(fn () => AuditEntry::query()->where('action', 'manufacturing.report.create')->count()));
    }

    public function test_release_needs_approval_where_the_company_asks_and_the_planner_never_approves(): void
    {
        $this->inTenant(fn () => Setting::create(['company_id' => $this->acme->id, 'labour_rate_minor' => 0, 'overhead_rate_minor' => 0, 'currency' => 'KES', 'release_approval' => true]));
        $approver = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $order = $this->plan($this->bread, '100', [], $this->planner);

        $this->api('POST', "manufacturing/orders/{$order['id']}/release", [], $this->planner)->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $order['id'])->sole());

        // APR-07: the planner who asked does not approve.
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->planner))->assertForbidden();
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($approver))->assertOk();

        $this->api('GET', "manufacturing/orders/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.ledger', 'none');
    }

    public function test_cancel_only_before_anything_moved(): void
    {
        $planned = $this->plan($this->bread, '10');
        $this->api('POST', "manufacturing/orders/{$planned['id']}/cancel", ['reason' => 'Not needed'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->api('POST', "manufacturing/orders/{$planned['id']}/release")->assertConflict();

        $running = $this->released($this->bread, '10');
        $this->issueAll($running, [$this->yeast->id => '0.05'])->assertCreated();
        $this->api('POST', "manufacturing/orders/{$running['id']}/cancel", ['reason' => 'Too late'])->assertConflict()->assertJsonPath('code', 'document_not_open');
    }

    public function test_permissions_follow_the_location(): void
    {
        $order = $this->released($this->bread, '10');
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $managerB = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));

        // The storekeeper issues but does not release, report, close or see costs.
        $this->api('POST', "manufacturing/orders/{$order['id']}/issues", ['lines' => [['material_id' => $order['materials'][0]['id'], 'qty' => '1']]], $storekeeper)->assertCreated()
            ->assertJsonPath('data.can.costs', false)->assertJsonPath('data.can.close', false);
        $this->api('POST', "manufacturing/orders/{$order['id']}/close", [], $storekeeper)->assertForbidden();
        $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '1'], $storekeeper)->assertForbidden();
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '1'], $storekeeper)->assertForbidden();

        // RBAC-04: another branch's manager and a cashier are not told the order exists.
        $this->api('GET', "manufacturing/orders/{$order['id']}", [], $managerB)->assertNotFound();
        $this->api('GET', 'manufacturing/orders', [], $managerB)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', "manufacturing/orders/{$order['id']}", [], $cashier)->assertNotFound();
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '1'], $managerB)->assertForbidden();
    }

    public function test_an_order_needs_a_production_bom_in_force_and_a_known_unit(): void
    {
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->flour->id, 'qty' => '1'])
            ->assertUnprocessable()->assertJsonPath('code', 'no_bom');
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '1', 'uom_id' => $this->kg->id])
            ->assertUnprocessable()->assertJsonPath('code', 'uom_not_on_item');
        $this->api('POST', 'manufacturing/orders', ['location_id' => $this->locationA->id, 'item_id' => $this->bread->id, 'qty' => '1.5'])
            ->assertUnprocessable()->assertJsonPath('code', 'qty_precision');
    }

    public function test_orders_list_and_print(): void
    {
        $order = $this->released($this->bread, '50');

        $this->api('GET', 'manufacturing/orders?status=released')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $order['number']);
        $this->api('GET', 'manufacturing/orders?status=closed')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/v1/manufacturing/orders/{$order['id']}/print?document=pick_list", $this->headersFor())->assertOk()
            ->assertSee('Wheat flour')->assertSee('25.5')->assertSee($order['number']);
        $this->get("/api/v1/manufacturing/orders/{$order['id']}/print", $this->headersFor())->assertOk()->assertSee('Bread 800 g');
        $csv = $this->get('/api/v1/manufacturing/orders?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString($order['number'], $csv);
    }
}
