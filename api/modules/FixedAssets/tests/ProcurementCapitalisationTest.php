<?php

namespace Modules\FixedAssets\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\FixedAssets\Listeners\CancelDraftsOfReceipt;
use Modules\FixedAssets\Listeners\CapitaliseFromProcurement;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\Category;
use Modules\Procurement\Events\GoodsReceiptCancelled;
use Modules\Procurement\Events\GoodsReceiptPosted;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use PDO;
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

    private function flag(Item $item): TestResponse
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

    public function test_an_asset_item_cannot_become_stock_and_one_that_did_is_never_capitalised(): void
    {
        // Review item 2 (stage 7D closing): stock stays in the stock ledger.
        $flag = $this->flag($this->laptop)->assertCreated()->json('data.id');
        $this->api('PATCH', "items/{$this->laptop->id}", ['type' => 'stock'])->assertUnprocessable()
            ->assertJsonPath('code', 'item_is_asset')->assertJsonValidationErrors(['type']);
        $this->assertSame('non_stock', $this->inTenant(fn () => $this->laptop->fresh()->type));
        $this->api('PATCH', "items/{$this->laptop->id}", ['name' => 'Laptop 14"'])->assertOk();

        // Made stock while the module was off (no guard then): its GRN lines give no drafts.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('fixed_assets'));
        $this->api('PATCH', "items/{$this->laptop->id}", ['type' => 'stock'])->assertOk();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('fixed_assets'));
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '2', 'unit_price_minor' => '100000']]);
        $this->postedReceipt($order, [['order_line_id' => $order['lines'][0]['id'], 'received_qty' => '2']]);
        $this->assertSame([], $this->drafts());

        // Unflagged, an item may become stock.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('fixed_assets'));
        $this->api('PATCH', "items/{$this->laptop->id}", ['type' => 'non_stock'])->assertOk();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('fixed_assets'));
        $this->api('POST', "fixed-assets/item-categories/{$flag}/archive")->assertOk();
        $this->api('PATCH', "items/{$this->laptop->id}", ['type' => 'stock'])->assertOk();
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
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", [], $this->userWith('accountant', Scope::company($this->acme->id)))->assertOk();

        // The GRN made both drafts; the invoice adds none.
        $this->assertCount(2, $this->drafts());
    }

    public function test_a_grn_after_an_invoice_adds_no_drafts_for_the_same_order_line(): void
    {
        // Review #2: invoiced (and approved over the match exception) before the goods came in.
        $this->flag($this->laptop)->assertCreated();
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '2', 'unit_price_minor' => '150000']]);
        $invoice = $this->api('POST', 'procurement/invoices', [
            'order_id' => $order['id'], 'supplier_reference' => 'INV-78', 'invoice_date' => '2026-10-15',
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'qty' => '2', 'unit_price_minor' => '150000']],
        ], $this->buyer)->assertCreated()->json('data');
        $this->api('POST', "procurement/invoices/{$invoice['id']}/approve", ['override_reason' => 'Billed before delivery'])->assertOk();
        $this->assertSame(['procurement_invoice', 'procurement_invoice'], array_map(fn (Asset $a) => $a->source, $this->drafts()));

        $this->postedReceipt($order);
        $this->assertCount(2, $this->drafts());
    }

    public function test_capitalising_a_line_waits_for_its_order_line_lock(): void
    {
        // Review #2: a GRN and an invoice of one order line never capitalise it side by side.
        $this->flag($this->laptop)->assertCreated();
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '1', 'unit_price_minor' => '150000']]);
        $lineId = $order['lines'][0]['id'];
        $event = new GoodsReceiptPosted($this->owner->tenant_id, (string) Str::uuid(), $this->acme->id, '2026-10-15', 'KES', [], [], ['party_id' => null], 'GRN-X', $order['id'],
            [['line_id' => (string) Str::uuid(), 'order_line_id' => $lineId, 'item_id' => $this->laptop->id, 'description' => 'Laptop', 'qty' => '1',
                'base_value_minor' => '150000', 'landed_minor' => '0', 'serials' => [], 'location_id' => $this->locationA->id]]);

        $config = config('database.connections.pgsql');
        $other = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
        $other->prepare('select pg_advisory_lock(hashtextextended(?, 0))')->execute(['fa_order_line:'.$lineId]);
        DB::statement("set lock_timeout = '300ms'");

        try {
            $this->expectException(QueryException::class);
            app(CapitaliseFromProcurement::class)->handle($event);
        } finally {
            $other->prepare('select pg_advisory_unlock(hashtextextended(?, 0))')->execute(['fa_order_line:'.$lineId]);
        }
    }

    public function test_a_cancelled_grn_cancels_its_draft_assets(): void
    {
        // Review #14: Procurement says so (GoodsReceiptCancelled); drafts made from that GRN are archived.
        Event::fake([GoodsReceiptCancelled::class]);
        $order = $this->approvedOrder([], [['item_id' => $this->laptop->id, 'qty' => '1', 'unit_price_minor' => '150000']]);
        $receipt = $this->api('POST', 'procurement/receipts', ['order_id' => $order['id'],
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'received_qty' => '1']]])->assertCreated()->json('data');
        $this->api('POST', "procurement/receipts/{$receipt['id']}/cancel", ['reason' => 'Wrong order'])->assertOk();
        Event::assertDispatched(GoodsReceiptCancelled::class, fn (GoodsReceiptCancelled $e) => $e->receiptId === $receipt['id'] && $e->tenantId === $this->owner->tenant_id);

        // Only drafts of a posted GRN can exist in practice; the listener still archives any it finds, and leaves others alone.
        [$fromIt, $other] = $this->inTenant(fn () => [
            Asset::query()->create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'number' => 'FA-T-1', 'name' => 'Laptop',
                'category_id' => $this->computers->id, 'status' => Asset::DRAFT, 'currency' => 'KES', 'base_currency' => 'KES', 'source' => Asset::FROM_RECEIPT, 'source_id' => $receipt['id']])->id,
            Asset::query()->create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'number' => 'FA-T-2', 'name' => 'Laptop',
                'category_id' => $this->computers->id, 'status' => Asset::DRAFT, 'currency' => 'KES', 'base_currency' => 'KES', 'source' => Asset::FROM_RECEIPT, 'source_id' => (string) Str::uuid()])->id,
        ]);
        app(CancelDraftsOfReceipt::class)->handle(new GoodsReceiptCancelled($this->owner->tenant_id, $receipt['id'], $this->acme->id, $order['id'], (string) $receipt['number']));

        $archived = $this->inTenant(fn () => Asset::query()->whereKey([$fromIt, $other])->pluck('archived_at', 'id')->all());
        $this->assertNotNull($archived[$fromIt]);
        $this->assertNull($archived[$other]);
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
