<?php

namespace Modules\Manufacturing\Tests;

use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\MasterData\Taxes\TaxCategoryCode;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
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

    public function test_recipe_costing_uses_current_inventory_costs_and_the_price_before_tax(): void
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

        // Owner decision B (2026-10-16): no tax category, so the tax in KES 650.00 is unknown: no net price, no share.
        $burger = collect($this->api('GET', 'manufacturing/recipe-costing?kind=recipe')->assertOk()->json('data'))->keyBy('item.code')['BURGER'];
        $this->assertSame([null, 'tax_category_missing', null, null], [$burger['price']['net_minor'], $burger['price']['net_price_reason'], $burger['net_price'], $burger['cost_share_percent']]);

        $this->inTenant(function () {
            $vat = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_M', 'name' => 'VAT menu', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $vat->id, 'rate' => '16', 'effective_from' => '2026-01-01']);
            $food = TaxCategory::create(['name' => 'Food']);
            TaxCategoryCode::create(['tax_category_id' => $food->id, 'company_id' => $this->acme->id, 'tax_code_id' => $vat->id]);
            $this->burger->update(['tax_category_id' => $food->id]);
        });

        $rows = collect($this->api('GET', 'manufacturing/recipe-costing?kind=recipe')->assertOk()->json('data'))->keyBy('item.code');

        // 15,000 + 2,000 + 0.024 L × 40,000 + 0.0015 kg × 90,000 = 17,000 + 960 + 135 = 18,095 a burger. KES 650.00 includes
        // 16 % VAT: 65,000 × 16 / 116 = 8,966 tax, so 56,034 before tax, of which the cost is 32.3 %.
        $this->assertSame(['18095', '18095', '18095', '0', '0', '0', '65000', true, '56034', '56034', '32.3'], [
            $rows['BURGER']['unit_cost']['amount_minor'], $rows['BURGER']['batch_cost']['amount_minor'], $rows['BURGER']['materials_cost']['amount_minor'],
            $rows['BURGER']['labour_cost']['amount_minor'], $rows['BURGER']['overhead_cost']['amount_minor'], $rows['BURGER']['by_product_credit']['amount_minor'],
            $rows['BURGER']['price']['amount_minor'], $rows['BURGER']['price']['tax_inclusive'], $rows['BURGER']['price']['net_minor'],
            $rows['BURGER']['net_price']['amount_minor'], $rows['BURGER']['cost_share_percent'],
        ]);
        $this->assertSame([], $rows['BURGER']['uncosted']);
        // The bread's flour, yeast and water have costs; the sauce is a recipe too.
        $this->assertSame('SAUCE', $rows['SAUCE']['item']['code']);

        $explode = $this->api('GET', "manufacturing/bom-versions/{$rows['BURGER']['version']['id']}/explode")->assertOk()->json('data.cost');
        $this->assertSame('18095', $explode['total']['amount_minor']);
    }

    public function test_recipe_costing_adds_labour_and_overhead_and_takes_off_the_by_products(): void
    {
        // Owner decision B (2026-10-16): the full cost against the price before tax.
        $this->api('PUT', "manufacturing/companies/{$this->acme->id}/settings", ['labour_rate_minor' => '120000', 'overhead_rate_minor' => '60000', 'release_approval' => false])->assertOk();
        $this->inTenant(function () {
            $list = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Wholesale', 'currency' => 'KES', 'tax_inclusive' => false, 'is_default' => true]);
            ItemPrice::create(['price_list_id' => $list->id, 'item_id' => $this->bread->id, 'uom_id' => $this->each->id, 'amount_minor' => '12000', 'currency' => 'KES', 'effective_from' => '2026-01-01']);
        });

        $bread = collect($this->api('GET', 'manufacturing/recipe-costing?kind=production')->assertOk()->json('data'))->keyBy('item.code')['BREAD'];

        // Ingredients 51 kg × 5,000 + 0.5 kg × 3,000 + 30 L × 100 = 259,500; labour 120 min at 120,000 an hour = 240,000;
        // overhead 120,000; the bran takes 10 % of 619,500 = 61,950; 557,550 for 100 loaves = 5,576 a loaf, 46.5 % of KES 120.00.
        $money = fn (string $key) => $bread[$key]['amount_minor'];
        $this->assertSame(['259500', '240000', '120000', '61950', '557550', '5576', '12000', '46.5'], [
            $money('materials_cost'), $money('labour_cost'), $money('overhead_cost'), $money('by_product_credit'), $money('batch_cost'), $money('unit_cost'),
            $money('net_price'), $bread['cost_share_percent'],
        ]);

        // The explosion of half a batch costs half the labour and overhead.
        $explode = $this->api('GET', "manufacturing/bom-versions/{$bread['version']['id']}/explode?qty=50")->assertOk()->json('data.cost');
        $this->assertSame(['129750', '120000', '60000', '30975', '278775'], [$explode['materials']['amount_minor'], $explode['labour']['amount_minor'],
            $explode['overhead']['amount_minor'], $explode['by_product_credit']['amount_minor'], $explode['total']['amount_minor']]);
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
