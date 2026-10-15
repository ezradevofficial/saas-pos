<?php

namespace Modules\Manufacturing\Tests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Rbac\Scope;
use Modules\Manufacturing\Tests\Concerns\BuildsManufacturing;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-06, MFG-18, MFG-19, MFG-21, MFG-22, MFG-27, MFG-29: consumption
// against standard, yield and variances, recipe costing from Inventory's
// current costs, company settings, custom fields, and costs only for
// holders of manufacturing.cost.view.
class ReportsTest extends TestCase
{
    use BuildsManufacturing, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpManufacturing(inventory: true);
        $this->breadBom();
        $this->receive($this->flour, '200', 1000000);
        $this->receive($this->yeast, '10', 30000);
        $this->receive($this->water, '500', 50000);
    }

    /** An order of 200 that used 105 kg flour (102 standard for 200; 76.5 for the 150 made), 1 kg yeast, 60 L water. */
    private function ranOrder(): array
    {
        $order = $this->released($this->bread, '200');
        $this->issueAll($order, [$this->flour->id => '105', $this->yeast->id => '1', $this->water->id => '60'])->assertCreated();

        return $this->api('POST', "manufacturing/orders/{$order['id']}/reports", ['qty_good' => '150', 'qty_scrap' => '10', 'scrap_reason' => 'process'])->assertCreated()->json('data');
    }

    public function test_consumption_compares_actual_with_the_standard_for_the_output(): void
    {
        $order = $this->ranOrder();
        $rows = collect($this->api('GET', 'manufacturing/consumption')->assertOk()->json('data'))->keyBy('item.code');

        // Flour: 102 required for 200, so 102 × 150/200 = 76.5 standard for the 150 made (1.5 of it expected wastage); 105 used.
        $this->assertSame(['102', '76.5', '1.5', '105', '28.5', '37.3'], [$rows['FLOUR']['required'], $rows['FLOUR']['standard'], $rows['FLOUR']['expected_loss'],
            $rows['FLOUR']['actual'], $rows['FLOUR']['variance_qty'], $rows['FLOUR']['variance_percent']]);
        // At the actual unit cost (5,000 a kg): 525,000 used, 382,500 standard, 142,500 more.
        $this->assertSame(['525000', '382500', '142500'], [$rows['FLOUR']['actual_value']['amount_minor'], $rows['FLOUR']['standard_value']['amount_minor'], $rows['FLOUR']['value_variance']['amount_minor']]);
        $this->assertSame($order['number'], $rows['FLOUR']['order']['number']);

        $yield = $this->api('GET', 'manufacturing/yield')->assertOk()->json('data.0');
        $this->assertSame(['200', '150', '10', '75', '6.3'], [$yield['planned'], $yield['good'], $yield['scrap'], $yield['yield_percent'], $yield['scrap_percent']]);
        $this->assertNotNull($yield['material_cost']);

        // RBAC-05: the storekeeper reads quantities, never values; reports need their own permission.
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $this->api('GET', 'manufacturing/consumption', [], $storekeeper)->assertForbidden();
        $accountant = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->assertNotNull($this->api('GET', 'manufacturing/consumption', [], $accountant)->assertOk()->json('data.0.actual_value'));
        $planner = $this->userWith('branch_manager', Scope::branch($this->branchB->id));
        $this->api('GET', 'manufacturing/consumption', [], $planner)->assertOk()->assertJsonCount(0, 'data');

        $csv = $this->get('/api/v1/manufacturing/consumption?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('Wheat flour', $csv);
    }

    public function test_recipe_costing_uses_current_inventory_costs_and_the_price(): void
    {
        $this->burgerRecipe();
        $this->receive($this->patty, '10', 150000);
        $this->receive($this->bun, '10', 20000);
        $this->receive($this->mayo, '4', 160000);
        $this->receive($this->chilli, '1', 90000);
        $this->inTenant(function () {
            $list = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Menu', 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true]);
            ItemPrice::create(['price_list_id' => $list->id, 'item_id' => $this->burger->id, 'uom_id' => $this->each->id, 'amount_minor' => '65000', 'currency' => 'KES', 'effective_from' => '2026-01-01']);
        });

        $rows = collect($this->api('GET', 'manufacturing/recipe-costing?kind=recipe')->assertOk()->json('data'))->keyBy('item.code');

        // 15,000 + 2,000 + 0.024 L × 40,000 + 0.0015 kg × 90,000 = 17,000 + 960 + 135 = 18,095 a burger, 27.8% of KES 650.00.
        $this->assertSame(['18095', '18095', '65000', '27.8'], [$rows['BURGER']['unit_cost']['amount_minor'], $rows['BURGER']['batch_cost']['amount_minor'],
            $rows['BURGER']['price']['amount_minor'], $rows['BURGER']['cost_share_percent']]);
        $this->assertSame([], $rows['BURGER']['uncosted']);
        // The bread's flour, yeast and water have costs; the sauce is a recipe too.
        $this->assertSame('SAUCE', $rows['SAUCE']['item']['code']);

        $explode = $this->api('GET', "manufacturing/bom-versions/{$rows['BURGER']['version']['id']}/explode")->assertOk()->json('data.cost');
        $this->assertSame('18095', $explode['total']['amount_minor']);
    }

    public function test_settings_are_set_at_the_company_and_audited(): void
    {
        $this->api('GET', "manufacturing/companies/{$this->acme->id}/settings")->assertOk()->assertJsonPath('data.labour_rate_minor', '0')->assertJsonPath('data.release_approval', false);
        $this->api('PUT', "manufacturing/companies/{$this->acme->id}/settings", ['labour_rate_minor' => '120000', 'overhead_rate_minor' => '60000', 'release_approval' => true])
            ->assertOk()->assertJsonPath('data.labour_rate_minor', '120000')->assertJsonPath('data.currency', 'KES');
        $this->api('PUT', "manufacturing/companies/{$this->acme->id}/settings", ['labour_rate_minor' => '1', 'overhead_rate_minor' => '1', 'release_approval' => false], $this->planner)->assertForbidden();
        // Review fix 4: the rates are costs: hidden from a reader without cost.view or settings.edit.
        $storekeeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $this->api('GET', "manufacturing/companies/{$this->acme->id}/settings", [], $storekeeper)->assertOk()
            ->assertJsonPath('data.labour_rate_minor', null)->assertJsonPath('data.overhead_rate_minor', null)->assertJsonPath('data.release_approval', true);
        $this->api('GET', "manufacturing/companies/{$this->acme->id}/settings", [], $this->planner)->assertOk()->assertJsonPath('data.labour_rate_minor', '120000');
        $this->api('GET', 'manufacturing/status')->assertOk()->assertJsonPath('data.inventory_active', true)->assertJsonPath('data.backflush_active', false);
    }

    public function test_custom_fields_on_orders_and_boms(): void
    {
        $this->inTenant(function () {
            CustomFieldDefinition::create(['entity' => 'manufacturing_order', 'key' => 'shift', 'label' => 'Shift', 'type' => 'text', 'position' => 1]);
            CustomFieldDefinition::create(['entity' => 'manufacturing_bom', 'key' => 'line', 'label' => 'Line', 'type' => 'text', 'position' => 1]);
        });

        $order = $this->plan($this->bread, '10', ['custom' => ['shift' => 'Night']]);
        $this->assertSame('Night', $order['custom']['shift']);
        $bom = $this->api('GET', 'manufacturing/boms')->assertOk()->json('data.0');
        $this->api('PUT', "manufacturing/boms/{$bom['id']}", ['custom' => ['line' => 'Oven 2']])->assertOk()->assertJsonPath('data.custom.line', 'Oven 2');
        $this->get("/api/v1/manufacturing/orders/{$order['id']}/print", $this->headersFor())->assertOk()->assertSee('Bread 800 g');
    }
}
