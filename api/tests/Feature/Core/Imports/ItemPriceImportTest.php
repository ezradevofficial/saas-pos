<?php

namespace Tests\Feature\Core\Imports;

use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// IMP-01..IMP-03: item prices by price list and unit, effective-dated, in
// minor units of the list's currency (ADR 003), through the price API's own
// rules (MD-03 follow-up), field rules (RBAC-05), undo (IMP-02) and tenant
// isolation (TEN-01).
class ItemPriceImportTest extends TestCase
{
    use BuildsImports, BuildsOrganisation, RefreshTenantDatabase;

    private const HEADERS = ['Item code', 'Price list', 'Unit', 'Price', 'Effective from'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00:00', 'UTC'));
        Storage::fake('media');
        $this->setUpOrganisation();
        $uoms = $this->inTenant(function () {
            app(TenantCurrencies::class)->provisionFor($this->acme);
            app(DefaultUoms::class)->seed();

            return Uom::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all();
        });
        $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", ['name' => 'Retail', 'currency' => 'KES', 'is_default' => true], $this->headersFor())->assertCreated();

        foreach (['SODA', 'WATER'] as $code) {
            $this->postJson('/api/v1/items', [
                'code' => $code, 'name' => $code, 'type' => 'stock', 'base_uom_id' => $uoms['EA'], 'uoms' => [['uom_id' => $uoms['BOX'], 'factor' => '12']],
            ], $this->headersFor())->assertCreated();
        }
    }

    public function test_prices_are_imported_in_minor_units_of_the_list_currency(): void
    {
        $import = $this->importFile('item_prices', $this->csvFile([self::HEADERS, ['SODA', 'Retail', '', '60.50', ''], ['soda', 'retail', 'BOX', '700', '2026-11-01'], ['WATER', 'Retail', 'EA', '45', '']]));

        $this->assertSame([3, 0], [$import['created_rows'], $import['failed_rows']]);
        $this->inTenant(function () {
            $prices = ItemPrice::query()->orderBy('amount_minor')->get();
            $this->assertSame(['4500', '6050', '70000'], $prices->pluck('amount_minor')->map(fn ($a) => (string) $a)->all());
            $this->assertSame('2026-10-08', $prices[1]->effective_from->toDateString());
            $this->assertSame('2026-11-01', $prices[2]->effective_from->toDateString());
        });
    }

    public function test_row_errors_and_update_mode(): void
    {
        $this->importFile('item_prices', $this->csvFile([self::HEADERS, ['SODA', 'Retail', '', '60', '']]));

        $file = $this->csvFile([self::HEADERS, ['NOPE', 'Retail', '', '1', ''], ['SODA', 'Wholesale', '', '1', ''], ['SODA', 'Retail', 'KG', '1', ''], ['WATER', 'Retail', '', '1.234', ''], ['SODA', 'Retail', '', '65', '']]);
        $id = $this->upload('item_prices', $file)->json('data.id');
        $rows = $this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->json('data.rows');
        $this->assertSame(['item_code', 'price_list', 'unit', 'amount', 'amount'], array_map(fn ($row) => $row['errors'][0]['column'], $rows));

        $this->patchJson("/api/v1/imports/{$id}", ['mode' => 'upsert'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);
        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())->assertJsonPath('data.updated_rows', 1)->assertJsonPath('data.failed_rows', 4);
        $this->inTenant(fn () => $this->assertSame('6500', (string) ItemPrice::query()->sole()->amount_minor));

        // Undo puts the amount before back.
        $this->postJson("/api/v1/imports/{$id}/undo", [], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => $this->assertSame('6000', (string) ItemPrice::query()->sole()->amount_minor));
    }

    public function test_undo_archives_created_prices_and_items_with_prices_cannot_be_undone(): void
    {
        $items = $this->importFile('items', $this->csvFile([['Code', 'Name', 'Type', 'Base unit'], ['NEW', 'New', 'stock', 'EA']]));
        $prices = $this->importFile('item_prices', $this->csvFile([self::HEADERS, ['NEW', 'Retail', '', '10', '']]));

        // The new item has a price now: its import can't be undone.
        $this->postJson("/api/v1/imports/{$items['id']}/undo", [], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('blocking.rows.0.reason', __('imports.usage.price'));

        $this->postJson("/api/v1/imports/{$prices['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->inTenant(fn () => $this->assertNotNull(ItemPrice::query()->sole()->archived_at));
        $this->postJson("/api/v1/imports/{$items['id']}/undo", [], $this->headersFor())->assertStatus(202);
    }

    public function test_frozen_prices_permission_denied_and_other_tenants(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Importer, prices read-only', ['core.import.run', 'core.price.view', 'core.price.edit', 'core.item.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'prices', 'mode' => 'readonly']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $import = $this->importFile('item_prices', $this->csvFile([self::HEADERS, ['SODA', 'Retail', '', '60', '']]), user: $clerk);
        $this->assertSame([0, 1], [$import['created_rows'], $import['failed_rows']]);

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->upload('item_prices', $this->csvFile([self::HEADERS, ['SODA', 'Retail', '', '60', '']]), $cashier)->assertForbidden();

        $other = $this->otherTenant()['user'];
        $this->postJson("/api/v1/imports/{$import['id']}/run", [], $this->headersFor($other))->assertNotFound();
        $this->getJson('/api/v1/imports?per_page=50', $this->headersFor($other))->assertOk()->assertJsonCount(0, 'data');
    }
}
