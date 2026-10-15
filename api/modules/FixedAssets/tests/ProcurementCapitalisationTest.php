<?php

namespace Modules\FixedAssets\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FA-21: goods received, or an invoice approved, for items flagged as
// assets become draft assets (one per unit, the value split exactly, the
// serials one each), once per line; with Fixed assets off nothing
// happens; stock items cannot be flagged.
class ProcurementCapitalisationTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    private Item $laptop;

    private Category $computers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('fixed_assets');
            $this->laptop = Item::create(['code' => 'LAPTOP', 'name' => 'Laptop', 'type' => 'non_stock', 'base_uom_id' => $this->each->id]);
            $this->computers = Category::query()->create(['company_id' => $this->acme->id, 'code' => 'IT', 'name' => 'Computers', 'method' => 'straight_line', 'useful_life_months' => 36]);
        });
    }

    private function flag(Item $item): \Illuminate\Testing\TestResponse
    {
        return $this->api('POST', 'fixed-assets/item-categories', ['company_id' => $this->acme->id, 'item_id' => $item->id, 'category_id' => $this->computers->id]);
    }

    /** @return list<Asset> */
    private function drafts(): array
    {
        return $this->inTenant(fn () => Asset::query()->orderBy('source_unit')->get()->all());
    }

    public function test_a_posted_grn_of_a_flagged_item_makes_one_draft_per_unit(): void
    {
        $this->flag($this->laptop)->assertCreated();
        $this->flag($this->soap)->assertStatus(422)->assertJsonPath('code', 'item_is_stock');
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '3', 'unit_price_minor' => '100001'], ['item_id' => $this->soap->id, 'qty' => '2', 'unit_price_minor' => '500']]);
        $this->postedReceipt($order, [
            ['order_line_id' => $order['lines'][0]['id'], 'received_qty' => '3', 'serials' => ['SN-1', 'SN-2', 'SN-3']],
            ['order_line_id' => $order['lines'][1]['id'], 'received_qty' => '2'],
        ]);

        $drafts = $this->drafts();
        $this->assertCount(3, $drafts);
        // KES 3 000.03 split exactly: 1 000.01 each.
        $this->assertSame([100001, 100001, 100001], array_map(fn (Asset $a) => (int) $a->cost_minor, $drafts));
        $this->assertSame(['SN-1', 'SN-2', 'SN-3'], array_map(fn (Asset $a) => $a->serial_number, $drafts));
        $this->assertSame([Asset::DRAFT, 'procurement_receipt', $this->computers->id, $this->supplier->id, 'Laptop'],
            [$drafts[0]->status, $drafts[0]->source, $drafts[0]->category_id, $drafts[0]->supplier_id, $drafts[0]->name]);

        // Activated, it is capitalised against purchases (Procurement's invoice expenses the line).
        $this->api('POST', "fixed-assets/assets/{$drafts[0]->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_an_invoice_makes_drafts_only_for_what_no_grn_did(): void
    {
        $this->flag($this->laptop)->assertCreated();
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '2', 'unit_price_minor' => '150000']]);
        $this->postedReceipt($order);
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'INV-77', 'invoice_date' => '2026-10-15',
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'qty' => '2', 'unit_price_minor' => '150000']],
        ])->assertCreated()->json('data');
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $this->userWith('accountant', \App\Core\Rbac\Scope::company($this->acme->id)))->assertOk();

        // The GRN made both drafts; the invoice adds none.
        $this->assertCount(2, $this->drafts());
    }

    public function test_nothing_happens_while_fixed_assets_is_off(): void
    {
        $this->flag($this->laptop)->assertCreated();
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('fixed_assets'));
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '1', 'unit_price_minor' => '150000']]);
        $this->postedReceipt($order);

        $this->assertCount(0, $this->drafts());
    }
}
