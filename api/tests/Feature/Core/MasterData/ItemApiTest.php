<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemUniqueness;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MD-02: the items catalogue (core part): codes and barcodes unique in the
// sharing scope, case-insensitively and normalised (review focus 4), units
// with factors, filters and search, permissions, history (MD-07), field
// rules (RBAC-05) and duplicate warnings (MD-06). Archived, never deleted
// (TEN-06).
class ItemApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    /** @var array<string, string> uom ids by code */
    private array $uoms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->uoms = $this->inTenant(function () {
            app(DefaultUoms::class)->seed();

            return Uom::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all();
        });
    }

    private function create(array $body, ?array $headers = null)
    {
        return $this->postJson('/api/v1/items', [
            'name_en' => 'Item', 'type' => 'stock', 'base_uom_id' => $this->uoms['EA'], ...$body,
        ], $headers ?? $this->headersFor());
    }

    private function item(string $code, array $extra = []): string
    {
        return $this->create(['code' => $code, ...$extra])->assertCreated()->json('data.id');
    }

    private function category(string $name, ?string $parent = null): string
    {
        return $this->postJson('/api/v1/item-categories', ['name_en' => $name, 'parent_id' => $parent], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    public function test_an_item_is_created_with_units_and_normalised_barcodes(): void
    {
        $category = $this->category('Drinks');
        $taxCategory = $this->inTenant(fn () => TaxCategory::create(['name' => 'Standard'])->id);

        $response = $this->create([
            'code' => 'SODA-500',
            'name_en' => 'Soda 500 ml',
            'name_fr' => 'Soda 500 ml (FR)',
            'category_id' => $category,
            'tax_category_id' => $taxCategory,
            'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '24', 'is_purchase_default' => true], ['uom_id' => $this->uoms['PACK'], 'factor' => 6.5]],
            'barcodes' => [['barcode' => ' 0061-6123 4567 '], ['barcode' => '0061612345680', 'uom_id' => $this->uoms['BOX']]],
        ])->assertCreated();

        $response->assertJsonPath('data.shared', true)
            ->assertJsonPath('data.company_id', null)
            ->assertJsonPath('data.code', 'SODA-500')
            ->assertJsonPath('data.name', 'Soda 500 ml')
            ->assertJsonPath('data.type', 'stock')
            ->assertJsonPath('data.category_id', $category)
            ->assertJsonPath('data.tax_category_id', $taxCategory)
            ->assertJsonPath('data.barcodes', [
                ['barcode' => '006161234567', 'uom_id' => null],
                ['barcode' => '0061612345680', 'uom_id' => $this->uoms['BOX']],
            ])
            ->assertJsonPath('data.custom', [])
            ->assertJsonPath('data.images', [])
            ->assertJsonPath('meta.possible_duplicates', []);

        $uoms = collect($response->json('data.uoms'))->keyBy('code');
        $this->assertSame(['factor' => '24', 'is_sales_default' => false, 'is_purchase_default' => true], collect($uoms['BOX'])->only(['factor', 'is_sales_default', 'is_purchase_default'])->all());
        $this->assertSame('6.5', $uoms['PACK']['factor']);

        // French names follow the language; English when there is no French one.
        $this->getJson('/api/v1/items/'.$response->json('data.id'), [...$this->headersFor(), 'Accept-Language' => 'fr'])
            ->assertOk()->assertJsonPath('data.name', 'Soda 500 ml (FR)');

        $this->inTenant(function () use ($response) {
            $id = $response->json('data.id');
            $this->assertSame(['core.item.create', 'core.item.units_update', 'core.item.barcodes_update'],
                AuditEntry::where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
            $this->assertEquals([['barcode' => '006161234567', 'uom_id' => null], ['barcode' => '0061612345680', 'uom_id' => $this->uoms['BOX']]],
                AuditEntry::where('action', 'core.item.barcodes_update')->sole()->after['barcodes']);
        });
    }

    public function test_validation_refuses_bad_input(): void
    {
        $this->create(['code' => 'has space', 'type' => 'gadget'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'type']);
        $this->create(['code' => 'X1', 'name_en' => null, 'name_fr' => ''])->assertUnprocessable()->assertJsonValidationErrors('name_en');
        $this->create(['code' => 'X1', 'base_uom_id' => null])->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');
        // The base unit is implicit (factor 1); factors are positive with at most 6 decimals.
        $this->create(['code' => 'X1', 'uoms' => [['uom_id' => $this->uoms['EA'], 'factor' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('uoms.0.uom_id');
        $this->create(['code' => 'X1', 'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '0']]])
            ->assertUnprocessable()->assertJsonValidationErrors('uoms.0.factor');
        $this->create(['code' => 'X1', 'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '1.1234567']]])
            ->assertUnprocessable()->assertJsonValidationErrors('uoms.0.factor');
        $this->create(['code' => 'X1', 'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '-2']]])
            ->assertUnprocessable()->assertJsonValidationErrors('uoms.0.factor');
        $this->create(['code' => 'X1', 'uoms' => [
            ['uom_id' => $this->uoms['BOX'], 'factor' => '12', 'is_sales_default' => true],
            ['uom_id' => $this->uoms['PACK'], 'factor' => '6', 'is_sales_default' => true],
        ]])->assertUnprocessable()->assertJsonValidationErrors('uoms');
        // A barcode is for the base unit or one of the item's units, and listed once.
        $this->create(['code' => 'X1', 'barcodes' => [['barcode' => '123', 'uom_id' => $this->uoms['KG']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.uom_id');
        $this->create(['code' => 'X1', 'barcodes' => [['barcode' => '12 3'], ['barcode' => '1-23']]])
            ->assertUnprocessable()->assertJsonValidationErrors('barcodes.1.barcode');
        $this->create(['code' => 'X1', 'barcodes' => [['barcode' => '12*3']]])
            ->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.barcode');
        // Items are shared (TEN-08): no company.
        $this->create(['code' => 'X1', 'company_id' => $this->acme->id])->assertUnprocessable()->assertJsonValidationErrors('company_id');

        $archivedUom = $this->inTenant(fn () => tap(Uom::create(['code' => 'OLD', 'name_en' => 'Old', 'name_fr' => 'Ancien', 'kind' => 'count']))->archive()->id);
        $this->create(['code' => 'X1', 'base_uom_id' => $archivedUom])->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');

        $this->inTenant(fn () => $this->assertSame(0, Item::count()));
    }

    public function test_codes_are_unique_case_insensitively_and_trimmed_among_active_items(): void
    {
        $first = $this->item('Cola-1');

        $this->create(['code' => 'COLA-1'])->assertUnprocessable()->assertJsonValidationErrors('code')
            ->assertJsonPath('errors.code.0', __('core.item.code_taken', ['code' => 'COLA-1']));
        $this->create(['code' => '  cola-1  '])->assertUnprocessable()->assertJsonValidationErrors('code');

        $other = $this->item('Cola-2');
        $this->patchJson("/api/v1/items/{$other}", ['code' => 'cola-1'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');
        // Saving an item under its own code (another case) is fine.
        $this->patchJson("/api/v1/items/{$first}", ['code' => 'COLA-1'], $this->headersFor())->assertOk()->assertJsonPath('data.code', 'COLA-1');

        // An archived item frees its code; restoring it is refused while another active item uses it.
        $this->postJson("/api/v1/items/{$first}/archive", [], $this->headersFor())->assertOk();
        $reuse = $this->item('cola-1');
        $this->postJson("/api/v1/items/{$first}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson("/api/v1/items/{$reuse}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/items/{$first}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);
    }

    public function test_barcodes_are_normalised_and_unique_among_active_items(): void
    {
        $first = $this->item('A1', ['barcodes' => [['barcode' => '0012 345-678']]]);

        $this->create(['code' => 'A2', 'barcodes' => [['barcode' => '0012345678']]])
            ->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.barcode')
            ->assertJsonPath('errors', ['barcodes.0.barcode' => [__('core.item.barcode_taken', ['barcode' => '0012345678'])]]);
        // Leading zeros matter: 12345678 is another barcode.
        $second = $this->item('A2', ['barcodes' => [['barcode' => '12345678']]]);
        // Letters compare upper case.
        $this->item('A3', ['barcodes' => [['barcode' => 'abc-123']]]);
        $this->create(['code' => 'A4', 'barcodes' => [['barcode' => 'ABC123']]])->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.barcode');

        $this->patchJson("/api/v1/items/{$second}", ['barcodes' => [['barcode' => '12345678'], ['barcode' => '0012345678']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('barcodes.1.barcode');

        // Archiving frees the barcode; restoring is refused while it is taken again.
        $this->postJson("/api/v1/items/{$first}/archive", [], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/items/{$second}", ['barcodes' => [['barcode' => '0012345678']]], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/items/{$first}/restore", [], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('barcodes.0.barcode');

        // The database holds the line too (a writer that skipped the check).
        $this->inTenant(function () use ($first) {
            $this->expectException(UniqueConstraintViolationException::class);
            Item::findOrFail($first)->restore();
        });
    }

    public function test_units_and_barcodes_are_replaced_on_update_and_audited(): void
    {
        $id = $this->item('B1', [
            'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '12']],
            'barcodes' => [['barcode' => '111', 'uom_id' => $this->uoms['BOX']]],
        ]);

        // Removing a unit a stored barcode uses needs the barcodes in the same request.
        $this->patchJson("/api/v1/items/{$id}", ['uoms' => []], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('uoms');

        $this->patchJson("/api/v1/items/{$id}", [
            'uoms' => [['uom_id' => $this->uoms['PACK'], 'factor' => '6', 'is_sales_default' => true]],
            'barcodes' => [['barcode' => '222']],
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.uoms.0.code', 'PACK')
            ->assertJsonPath('data.uoms.0.factor', '6')
            ->assertJsonPath('data.barcodes', [['barcode' => '222', 'uom_id' => null]]);

        // Changing the base to a unit listed among the others is refused.
        $this->patchJson("/api/v1/items/{$id}", ['base_uom_id' => $this->uoms['PACK']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');

        $this->inTenant(function () use ($id) {
            $this->assertSame(['core.item.create', 'core.item.units_update', 'core.item.barcodes_update', 'core.item.units_update', 'core.item.barcodes_update'],
                AuditEntry::where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
            $this->assertSame(1, ItemBarcode::where('item_id', $id)->count());
        });
    }

    public function test_kits_are_allowed_and_items_are_archived_never_deleted(): void
    {
        $id = $this->item('KIT-1', ['type' => 'kit']);
        $this->getJson("/api/v1/items/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.type', 'kit');

        $this->postJson("/api/v1/items/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
        $this->getJson('/api/v1/items', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/items?status=archived', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/v1/items/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);

        $this->inTenant(fn () => $this->assertSame(['core.item.create', 'core.item.archive', 'core.item.restore'],
            AuditEntry::where('auditable_id', $id)->orderBy('seq')->pluck('action')->all()));
    }

    public function test_search_and_filters(): void
    {
        $drinks = $this->category('Drinks');
        $soft = $this->category('Soft drinks', $drinks);
        $food = $this->category('Food');

        $cola = $this->item('COLA-330', ['name_en' => 'Cola can', 'name_fr' => 'Canette de cola', 'category_id' => $soft, 'barcodes' => [['barcode' => '5449000000996']]]);
        $water = $this->item('WAT-1', ['name_en' => 'Mineral water', 'category_id' => $drinks]);
        $bread = $this->item('BRD-1', ['name_en' => 'Bread', 'category_id' => $food]);
        $delivery = $this->item('SRV-DEL', ['name_en' => 'Delivery', 'type' => 'service']);

        $ids = fn (string $query) => array_column($this->getJson('/api/v1/items?'.$query, $this->headersFor())->assertOk()->json('data'), 'id');

        // Code prefix, case-insensitive.
        $this->assertSame([$cola], $ids('search=cola-'));
        // Names in either language, contains or similar.
        $this->assertSame([$cola], $ids('search=canette'));
        $this->assertSame([$water], $ids('search=minral+water'));
        // The exact barcode, normalised.
        $this->assertSame([$cola], $ids('search=5449-0000-00996'));
        $this->assertSame([$cola], $ids('barcode=5449%20000000996'));
        $this->assertSame([], $ids('barcode=544900000099'));
        $this->getJson('/api/v1/items?barcode=%2A%2A', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('barcode');
        // A category includes its subcategories.
        $this->assertEqualsCanonicalizing([$cola, $water], $ids("category={$drinks}"));
        $this->assertSame([$cola], $ids("category={$soft}"));
        $this->assertSame([$bread], $ids("category={$food}"));
        $this->assertSame([$delivery], $ids('type=service'));
        $this->getJson('/api/v1/items?type=gadget', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_categories_and_tax_categories_are_in_the_items_scope(): void
    {
        $archived = $this->category('Old');
        $this->postJson("/api/v1/item-categories/{$archived}/archive", [], $this->headersFor())->assertOk();
        $this->create(['code' => 'C1', 'category_id' => $archived])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        // A company's tax category (left from a per-company period) does not fit a shared item.
        $companyTax = $this->inTenant(fn () => TaxCategory::create(['company_id' => $this->acme->id, 'name' => 'Acme only'])->id);
        $this->create(['code' => 'C1', 'tax_category_id' => $companyTax])->assertUnprocessable()->assertJsonValidationErrors('tax_category_id');
    }

    public function test_permissions_follow_the_templates(): void
    {
        $id = $this->item('P1', ['name_en' => 'Pen']);

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson('/api/v1/items', $cashier)->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/items/{$id}", $cashier)->assertOk();
        $this->getJson('/api/v1/item-categories', $cashier)->assertOk();
        $this->getJson('/api/v1/uoms', $cashier)->assertOk();
        $this->create(['code' => 'P2'], $cashier)->assertForbidden();
        $this->patchJson("/api/v1/items/{$id}", ['name_en' => 'X'], $cashier)->assertForbidden();
        $this->postJson("/api/v1/items/{$id}/archive", [], $cashier)->assertForbidden();

        // Storekeepers and buyers keep the catalogue; only Owner and Admin archive.
        $storekeeper = $this->headersFor($this->userWith('storekeeper', Scope::location($this->locationA->id)));
        $this->create(['code' => 'P2'], $storekeeper)->assertCreated();
        $this->patchJson("/api/v1/items/{$id}", ['name_en' => 'Blue pen'], $storekeeper)->assertOk();
        $this->postJson("/api/v1/items/{$id}/archive", [], $storekeeper)->assertForbidden();
        $this->postJson('/api/v1/item-categories', ['name_en' => 'Stationery'], $storekeeper)->assertCreated();

        $auditor = $this->headersFor($this->userWith('read_only_auditor', Scope::tenant()));
        $this->getJson("/api/v1/items/{$id}", $auditor)->assertOk();
        $this->create(['code' => 'P3'], $auditor)->assertForbidden();

        // No item permission at all: the list is forbidden and an item not found.
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));
        $this->getJson('/api/v1/items', $hr)->assertForbidden();
        $this->getJson("/api/v1/items/{$id}", $hr)->assertNotFound();
    }

    public function test_history_and_field_rules(): void
    {
        // RBAC-05: a clerk whose role hides barcodes sees neither them nor their changes.
        $clerk = $this->inTenant(function () {
            $role = $this->role('Catalogue clerk', ['core.item.view', 'core.item.edit']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'barcodes', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        $id = $this->item('H1', ['name_en' => 'Hammer', 'barcodes' => [['barcode' => '999']]]);
        $this->patchJson("/api/v1/items/{$id}", ['name_en' => 'Claw hammer'], $this->headersFor())->assertOk();

        $this->getJson("/api/v1/history/item/{$id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.action', 'core.item.update')
            ->assertJsonPath('data.0.after', ['name_en' => 'Claw hammer'])
            ->assertJsonCount(3, 'data');

        $this->getJson("/api/v1/items/{$id}", $this->headersFor($clerk))->assertOk()->assertJsonMissingPath('data.barcodes')->assertJsonPath('data.code', 'H1');
        $history = $this->getJson("/api/v1/history/item/{$id}", $this->headersFor($clerk))->assertOk();
        $this->assertSame(['core.item.update', 'core.item.create'], array_column($history->json('data'), 'action'));
    }

    public function test_possible_duplicates_are_warned_never_blocking(): void
    {
        $first = $this->item('D1', ['name_en' => 'Sugar 1 kg']);

        $this->create(['code' => 'D2', 'name_en' => 'Sugar 1kg'])->assertCreated()
            ->assertJsonPath('meta.possible_duplicates', [['id' => $first, 'code' => 'D1', 'name' => 'Sugar 1 kg', 'reason' => 'name']]);
        $this->create(['code' => 'D3', 'name_en' => 'Salt'])->assertCreated()->assertJsonPath('meta.possible_duplicates', []);
    }

    public function test_the_base_unit_changes_only_with_the_full_unit_and_barcode_lists(): void
    {
        $id = $this->item('BASE-1', [
            'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '12']],
            'barcodes' => [['barcode' => '100'], ['barcode' => '200', 'uom_id' => $this->uoms['BOX']]],
        ]);

        // Factors and unit barcodes are counted in the base unit: no silent change.
        $this->patchJson("/api/v1/items/{$id}", ['base_uom_id' => $this->uoms['PACK']], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');
        $this->patchJson("/api/v1/items/{$id}", ['base_uom_id' => $this->uoms['PACK'], 'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '2']]], $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors('base_uom_id');

        // With both lists: a barcode naming the new base is stored for the base (uom_id null).
        $this->patchJson("/api/v1/items/{$id}", [
            'base_uom_id' => $this->uoms['PACK'],
            'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '2']],
            'barcodes' => [['barcode' => '100', 'uom_id' => $this->uoms['PACK']], ['barcode' => '200', 'uom_id' => $this->uoms['BOX']]],
        ], $this->headersFor())->assertOk()
            ->assertJsonPath('data.base_uom_id', $this->uoms['PACK'])
            ->assertJsonPath('data.uoms.0.factor', '2')
            ->assertJsonPath('data.barcodes', [['barcode' => '100', 'uom_id' => null], ['barcode' => '200', 'uom_id' => $this->uoms['BOX']]]);

        // An item with no other units or unit barcodes changes its base freely.
        $plain = $this->item('BASE-2', ['barcodes' => [['barcode' => '300']]]);
        $this->patchJson("/api/v1/items/{$plain}", ['base_uom_id' => $this->uoms['KG']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.base_uom_id', $this->uoms['KG']);
    }

    public function test_restore_is_refused_while_the_category_tax_category_or_base_unit_is_archived(): void
    {
        $category = $this->category('Seasonal');
        $taxCategory = $this->inTenant(fn () => TaxCategory::create(['name' => 'Seasonal tax'])->id);
        $crate = $this->postJson('/api/v1/uoms', ['code' => 'CRATE', 'name_en' => 'Crate', 'name_fr' => 'Caisse', 'kind' => 'count'], $this->headersFor())->json('data.id');
        $id = $this->item('R1', ['category_id' => $category, 'tax_category_id' => $taxCategory, 'base_uom_id' => $crate]);

        $this->postJson("/api/v1/items/{$id}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/item-categories/{$category}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/uoms/{$crate}/archive", [], $this->headersFor())->assertOk();
        $this->inTenant(fn () => TaxCategory::findOrFail($taxCategory)->archive());

        $this->postJson("/api/v1/items/{$id}/restore", [], $this->headersFor())->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'tax_category_id', 'base_uom_id']);

        $this->postJson("/api/v1/item-categories/{$category}/restore", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/uoms/{$crate}/restore", [], $this->headersFor())->assertOk();
        $this->inTenant(fn () => TaxCategory::findOrFail($taxCategory)->restore());
        $this->postJson("/api/v1/items/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.archived_at', null);
    }

    public function test_references_archived_between_validation_and_the_lock_are_refused(): void
    {
        // Simulates a concurrent archive: the category and the unit are archived
        // right after the writer takes the items sharing lock, past validation.
        $category = $this->category('Racing');
        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($category, &$raced) {
            if (! $raced && str_contains($query->sql, 'pg_advisory_xact_lock_shared')) {
                $raced = true;
                ItemCategory::findOrFail($category)->archive();
                Uom::findOrFail($this->uoms['BOX'])->archive();
            }
        });

        $this->create(['code' => 'RACE', 'category_id' => $category, 'uoms' => [['uom_id' => $this->uoms['BOX'], 'factor' => '4']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['category_id', 'uoms']);
        $this->assertTrue($raced);
        $this->inTenant(fn () => $this->assertSame(0, Item::count()));
    }

    public function test_only_code_and_barcode_index_violations_become_validation_errors(): void
    {
        $violation = fn (string $index) => new UniqueConstraintViolationException('pgsql', 'insert', [], new PDOException("duplicate key value violates unique constraint \"{$index}\""));
        $uniqueness = app(ItemUniqueness::class);

        $this->assertArrayHasKey('code', $uniqueness->fromViolation($violation('items_code_company_unique'))->errors());
        $this->assertArrayHasKey('barcodes', $uniqueness->fromViolation($violation('item_barcodes_shared_unique'))->errors());
        $this->assertNull($uniqueness->fromViolation($violation('item_uoms_item_id_uom_id_unique')));
        $this->assertNull($uniqueness->fromViolation($violation('item_images_path_unique')));
    }
}
