<?php

namespace Modules\Inventory\Tests;

use App\Core\Layouts\Dashboards\DashboardSources;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\MasterData\Taxes\PriceList;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\Inventory\Events\StockLow;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// INV-01, INV-02, INV-04, INV-05, INV-11, INV-12, INV-18 to INV-22: stock
// lists and exports, item stock detail and settings, opening stock,
// reorder alerts, valuation, labels, dashboards; scope, values only for
// valuation holders (RBAC-05), the append-only ledger.
class StockApiTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpInventory();
    }

    private function opening(string $item, string $qty, string $cost, array $extra = []): array
    {
        return $this->postJson('/api/v1/inventory/opening-stock', [
            'item_id' => $item, 'location_id' => $this->locationA->id, 'qty' => $qty, 'unit_cost' => $cost, ...$extra,
        ], $this->headersFor())->assertCreated()->json('data');
    }

    public function test_opening_stock_and_the_stock_list_with_values_for_valuation_holders_only(): void
    {
        $movement = $this->opening($this->soap->id, '2', '120', ['uom_id' => $this->box->id]);
        $this->assertSame(['opening', '24', '2', ['amount_minor' => '24000', 'currency' => 'KES']], [$movement['kind'], $movement['qty'], $movement['uom_qty'], $movement['value']]);

        $owner = $this->getJson('/api/v1/inventory/stock', $this->headersFor())->assertOk()->json('data');
        $this->assertSame([['SOAP', '24', ['amount_minor' => '24000', 'currency' => 'KES']]], array_map(fn ($r) => [$r['item']['code'], $r['qty'], $r['value']], $owner));

        // A storekeeper sees the stock of their location, values included (inventory.*);
        // a procurement officer reads it without values.
        $buyer = $this->userWith('procurement_officer', Scope::company($this->acme->id));
        $this->inTenant(fn () => DB::table('role_has_permissions')->where('role_id', $this->roles->get('procurement_officer')->id)
            ->whereIn('permission_id', DB::table('permissions')->where('name', 'inventory.valuation.view')->pluck('id'))->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $rows = $this->getJson('/api/v1/inventory/stock', $this->headersFor($buyer))->assertOk()->json('data');
        $this->assertNull($rows[0]['value']);

        $keeperB = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->assertSame([], $this->getJson('/api/v1/inventory/stock', $this->headersFor($keeperB))->assertOk()->json('data'));

        $csv = $this->get('/api/v1/inventory/stock?format=csv', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString('SOAP', $csv);
        $this->assertStringContainsString('KES 240.00', $csv);
    }

    public function test_the_ledger_is_append_only(): void
    {
        $this->move($this->soap, Movement::RECEIPT, '1', 100);

        $this->expectException(QueryException::class);
        $this->inTenant(fn () => DB::table('inventory_movements')->update(['qty' => '5']));
    }

    public function test_item_detail_settings_and_variants(): void
    {
        $this->opening($this->milk->id, '5', '1', ['batch_number' => 'LOT-1', 'expires_on' => '2027-01-31']);

        $detail = $this->getJson("/api/v1/inventory/items/{$this->milk->id}", $this->headersFor())->assertOk()->json('data');
        $this->assertSame('batch', $detail['settings']['tracking']);
        $this->assertSame('LOT-1', $detail['batches'][0]['batch_number']);
        $this->assertSame('5', $detail['on_hand']);
        $this->assertTrue($detail['has_movements']);

        $this->putJson("/api/v1/inventory/items/{$this->milk->id}/settings", ['tracking' => 'none'], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'tracking_locked');
        $this->putJson("/api/v1/inventory/items/{$this->sugar->id}/settings", ['tracking' => 'batch', 'track_expiry' => true], $this->headersFor())
            ->assertOk()->assertJsonPath('data.tracking', 'batch');

        // INV-02: variants of a parent, attributes unique among them.
        $small = $this->inTenant(fn () => Item::create(['code' => 'SOAP-S', 'name' => 'Soap small', 'type' => 'stock', 'base_uom_id' => $this->each->id]));
        $large = $this->inTenant(fn () => Item::create(['code' => 'SOAP-L', 'name' => 'Soap large', 'type' => 'stock', 'base_uom_id' => $this->each->id]));
        $this->putJson("/api/v1/inventory/items/{$small->id}/variant", ['parent_item_id' => $this->soap->id, 'attributes' => ['Size' => 'S']], $this->headersFor())
            ->assertOk()->assertJsonPath('data.attributes', ['size' => 'S']);
        $this->putJson("/api/v1/inventory/items/{$large->id}/variant", ['parent_item_id' => $this->soap->id, 'attributes' => ['size' => 'S']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'variant_attributes_taken');
        $this->putJson("/api/v1/inventory/items/{$this->soap->id}/variant", ['parent_item_id' => $small->id, 'attributes' => ['size' => 'X']], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'variant_parent_is_variant');
        $this->assertSame(['SOAP-S'], array_column(array_column($this->getJson("/api/v1/inventory/items/{$this->soap->id}", $this->headersFor())->json('data.variants'), 'item'), 'code'));
        $this->putJson("/api/v1/inventory/items/{$small->id}/variant", ['parent_item_id' => null], $this->headersFor())->assertOk()->assertJsonPath('data', null);

        // Without inventory.item.configure: refused.
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->putJson("/api/v1/inventory/items/{$this->sugar->id}/settings", ['tracking' => 'none'], $this->headersFor($cashier))->assertForbidden();
    }

    public function test_reorder_levels_alert_once_per_crossing(): void
    {
        Event::fake([StockLow::class]);
        $this->opening($this->soap->id, '20', '1');

        $this->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'reorder_level' => '10', 'reorder_qty' => '48'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.low', false);

        $this->move($this->soap, Movement::SALE, '-12', manual: false);
        $this->move($this->soap, Movement::SALE, '-1', manual: false);
        Event::assertDispatchedTimes(StockLow::class, 1);

        $rows = $this->getJson('/api/v1/inventory/reorder-rules?flag=low', $this->headersFor())->assertOk()->json('data');
        $this->assertSame([['7', true]], array_map(fn ($r) => [$r['on_hand'], $r['low']], $rows));
        $this->assertSame(1, count($this->getJson('/api/v1/inventory/stock?flag=low', $this->headersFor())->json('data')));

        // Back above the level re-arms the alert.
        $this->move($this->soap, Movement::RECEIPT, '10', 10);
        $this->move($this->soap, Movement::SALE, '-10', manual: false);
        Event::assertDispatchedTimes(StockLow::class, 2);

        $this->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'reorder_level' => null], $this->headersFor())
            ->assertOk()->assertJsonPath('data', null);
        $this->assertSame([], $this->getJson('/api/v1/inventory/reorder-rules', $this->headersFor())->json('data'));
    }

    public function test_low_stock_notifies_the_reorder_editors_where_it_happened(): void
    {
        $keeper = $this->userWith('storekeeper', Scope::location($this->locationA->id));
        $keeperB = $this->userWith('storekeeper', Scope::location($this->locationB->id));
        $this->opening($this->soap->id, '5', '1');
        $this->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'reorder_level' => '10'], $this->headersFor())->assertOk();

        $notified = $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'inventory.stock.low')->pluck('user_id')->all());
        $this->assertContains($keeper->id, $notified);
        $this->assertContains($this->owner->id, $notified);
        $this->assertNotContains($keeperB->id, $notified);
        // INV-11: the message names the unit of the quantities.
        $unit = $this->inTenant(fn () => $this->soap->baseUom()->value('code'));
        $body = $this->inTenant(fn () => InAppNotification::query()->where('event_type', 'inventory.stock.low')->where('user_id', $this->owner->id)->value('body'));
        $this->assertStringContainsString("down to 5 {$unit} at", $body);
        $this->assertStringContainsString("reorder level is 10 {$unit}", $body);
    }

    public function test_valuation_report_and_dashboards(): void
    {
        $this->opening($this->soap->id, '10', '1.5');
        $this->opening($this->sugar->id, '2.5', '100');

        $report = $this->getJson('/api/v1/inventory/valuation', $this->headersFor())->assertOk();
        $this->assertSame(['SUGAR', 'SOAP'], array_column(array_column($report->json('data'), 'item'), 'code'));
        $this->assertSame([['amount_minor' => '26500', 'currency' => 'KES']], $report->json('meta.totals'));
        $this->assertSame('150', $report->json('data.1.unit_cost_minor'));

        // Branch-level holders do not get the company's pooled valuation.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->assertSame([], $this->getJson('/api/v1/inventory/valuation', $this->headersFor($manager))->assertOk()->json('data'));
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/inventory/valuation', $this->headersFor($cashier))->assertForbidden();

        $value = $this->inTenant(fn () => app(DashboardSources::class)->find('inventory.stock_value')->data($this->owner, []));
        $this->assertSame(['money', '26500', 'KES'], [$value['kind'], $value['value'], $value['currency']]);
        $this->putJson('/api/v1/inventory/reorder-rules', ['item_id' => $this->soap->id, 'location_id' => $this->locationA->id, 'reorder_level' => '20'], $this->headersFor())->assertOk();
        $low = $this->inTenant(fn () => app(DashboardSources::class)->find('inventory.low_stock')->data($this->owner, []));
        $this->assertSame([1, 'Soap'], [$low['total'], $low['rows'][0]['title']]);
    }

    public function test_company_settings_lock_the_costing_method_once_stock_moved(): void
    {
        $url = "/api/v1/inventory/companies/{$this->acme->id}/settings";
        $this->getJson($url, $this->headersFor())->assertOk()->assertJsonPath('data.costing_method', 'weighted_average')->assertJsonPath('data.costing_locked', false);
        $this->putJson($url, ['costing_method' => 'fifo', 'allow_negative' => false], $this->headersFor())->assertOk()->assertJsonPath('data.costing_method', 'fifo');

        $this->opening($this->soap->id, '1', '1');
        $this->putJson($url, ['costing_method' => 'weighted_average', 'allow_negative' => true], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'costing_method_locked');

        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $this->putJson($url, ['costing_method' => 'fifo', 'allow_negative' => true], $this->headersFor($manager))->assertForbidden();
    }

    public function test_labels_print_through_the_label_template(): void
    {
        $list = $this->inTenant(function () {
            ItemBarcode::create(['item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'barcode' => '6161000000001']);
            $list = PriceList::create(['company_id' => $this->acme->id, 'name' => 'Retail', 'currency' => 'KES', 'tax_inclusive' => true, 'is_default' => true]);
            ItemPrice::create(['price_list_id' => $list->id, 'item_id' => $this->soap->id, 'uom_id' => $this->each->id, 'amount_minor' => '12500', 'currency' => 'KES', 'effective_from' => '2026-01-01']);

            return $list;
        });

        $html = $this->postJson('/api/v1/inventory/labels', ['labels' => [['item_id' => $this->soap->id, 'copies' => 2]], 'price_list_id' => $list->id], $this->headersFor())
            ->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')->getContent();
        $this->assertSame(2, substr_count($html, '<div class="doc">'));
        $this->assertStringContainsString('Soap', $html);
        $this->assertStringContainsString('KES', $html);

        $this->postJson('/api/v1/inventory/labels', ['labels' => [['item_id' => $this->soap->id, 'copies' => 1]], 'format' => 'pdf'], $this->headersFor())
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson('/api/v1/inventory/labels', ['labels' => [['item_id' => $this->soap->id, 'copies' => 400], ['item_id' => $this->milk->id, 'copies' => 200]]], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'too_many_labels');
    }

    public function test_movements_lists_filter_by_item_kind_and_place(): void
    {
        $this->opening($this->soap->id, '5', '1');
        $this->move($this->soap, Movement::SALE, '-2', manual: false);

        $this->assertSame(['sale', 'opening'], array_column($this->getJson('/api/v1/inventory/movements', $this->headersFor())->assertOk()->json('data'), 'kind'));
        $this->assertSame(['sale'], array_column($this->getJson('/api/v1/inventory/movements?kind=sale', $this->headersFor())->json('data'), 'kind'));
        $this->assertCount(2, $this->getJson("/api/v1/inventory/items/{$this->soap->id}/movements", $this->headersFor())->assertOk()->json('data'));
        $this->assertCount(0, $this->getJson("/api/v1/inventory/movements?location={$this->locationB->id}", $this->headersFor())->json('data'));
    }
}
