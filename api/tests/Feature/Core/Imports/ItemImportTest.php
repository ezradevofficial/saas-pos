<?php

namespace Tests\Feature\Core\Imports;

use App\Core\Audit\AuditEntry;
use App\Core\CustomFields\CustomFieldDefinition;
use App\Core\Imports\Import;
use App\Core\Imports\ImportRow;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Taxes\TaxCategory;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// IMP-01..IMP-03: items through the import wizard, with the item API's own
// rules (MD-02), custom fields (CF-03), field rules (RBAC-05), audit (AUD-01)
// and tenant isolation (TEN-01).
class ItemImportTest extends TestCase
{
    use BuildsImports, BuildsOrganisation, RefreshTenantDatabase;

    private const HEADERS = ['Code', 'Name', 'Type', 'Base unit', 'Category', 'Tax category', 'Barcodes', 'Other units'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(DefaultUoms::class)->seed();
            ItemCategory::create(['name' => 'Drinks']);
            TaxCategory::create(['name' => 'Standard']);
        });
    }

    private function rows(int $count, int $from = 1): array
    {
        $rows = [];

        for ($i = $from; $i < $from + $count; $i++) {
            $rows[] = ["ITEM-{$i}", "Item {$i}", 'stock', 'EA', 'Drinks', 'Standard', '61610000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'BOX=24'];
        }

        return $rows;
    }

    public function test_valid_rows_are_imported_through_the_item_rules_and_audited_as_one_batch(): void
    {
        $import = $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(3)], bom: true));

        $this->assertSame('completed', $import['status']);
        $this->assertSame([3, 3, 0, 0], [$import['processed_rows'], $import['created_rows'], $import['updated_rows'], $import['failed_rows']]);
        $this->assertNotNull($import['result_file_url']);
        $this->assertTrue($import['can_undo']);

        $this->inTenant(function () use ($import) {
            $item = Item::query()->where('code', 'ITEM-2')->sole();
            $this->assertSame('Item 2', $item->name);
            $this->assertSame(ItemCategory::query()->value('id'), $item->category_id);
            $this->assertSame(TaxCategory::query()->value('id'), $item->tax_category_id);
            $this->assertSame(['6161000000002'], ItemBarcode::query()->where('item_id', $item->id)->pluck('barcode')->all());
            $this->assertSame('24', rtrim(rtrim((string) ItemUom::query()->where('item_id', $item->id)->value('factor'), '0'), '.'));

            // AUD-01: every entry of the import carries its id.
            $entries = AuditEntry::query()->where('auditable_id', $item->id)->get();
            $this->assertSame(['core.item.create', 'core.item.units_update', 'core.item.barcodes_update'], $entries->pluck('action')->all());
            $this->assertTrue($entries->every(fn (AuditEntry $entry) => ($entry->after['metadata']['import_id'] ?? null) === $import['id']));
            $this->assertSame($this->owner->id, $entries->first()->user_id);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.import.complete')->count());
            $this->assertSame(3, ImportRow::query()->where('import_id', $import['id'])->count());
        });
    }

    public function test_preview_and_dry_run_report_row_errors_and_nothing_is_written(): void
    {
        $rows = $this->rows(3);
        $rows[1][3] = 'NOPE';     // unknown unit
        $rows[2][2] = 'gadget';   // not a type
        $file = $this->csvFile([self::HEADERS, ...$rows, $this->rows(1)[0]]); // the last row repeats row 2
        $id = $this->upload('items', $file)->assertCreated()
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.mapping.code', 0)
            ->assertJsonPath('data.mapping.units', 7)
            ->json('data.id');

        $preview = $this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->assertOk()->json('data');
        $this->assertSame([1, 3, 4], [$preview['valid'], $preview['invalid'], $preview['total_rows']]);
        $this->assertSame('valid', $preview['rows'][0]['status']);
        $this->assertSame(3, $preview['rows'][1]['row']);
        $this->assertSame('base_unit', $preview['rows'][1]['errors'][0]['column']);
        $this->assertSame('type', $preview['rows'][2]['errors'][0]['column']);
        $this->assertSame(__('imports.errors.repeated', ['row' => 2]), $preview['rows'][3]['errors'][0]['message']);

        $this->postJson("/api/v1/imports/{$id}/dry-run", [], $this->headersFor())->assertStatus(202);
        $checked = $this->getJson("/api/v1/imports/{$id}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'checked')
            ->assertJsonPath('data.failed_rows', 3)
            ->assertJsonPath('data.created_rows', 1)
            ->json('data');
        $this->assertNotNull($checked['error_file_url']);
        $this->inTenant(fn () => $this->assertSame(0, Item::query()->count()));

        // The error file: the failed rows as uploaded, with an errors column.
        $this->get($checked['error_file_url'])->assertOk();
        $this->inTenant(function () use ($id) {
            $path = Import::query()->findOrFail($id)->error_file_path;
            $this->assertStringEndsWith('/errors.xlsx', $path);
            $this->assertStringContainsString("tenants/{$this->owner->tenant_id}/imports/", $path);
        });
    }

    public function test_french_headers_map_themselves_and_update_mode_matches_by_code(): void
    {
        $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(2)]));

        $french = ['Code', 'Nom', 'Type d’article', 'Unité de base', 'Codes-barres'];
        $file = $this->csvFile([$french, ['ITEM-1', 'Article un', 'Article stocké', 'EA', '6161000000001'], ['ITEM-9', 'Article neuf', 'Service', 'EA', '']], separator: ';');
        $id = $this->upload('items', $file)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())
            ->assertJsonPath('data.mapping.name', 1)->assertJsonPath('data.mapping.type', 2)->assertJsonPath('data.mapping.base_unit', 3);

        // Create only: the existing code is refused.
        $preview = $this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->json('data');
        $this->assertSame(__('imports.errors.exists', ['value' => 'ITEM-1']), $preview['rows'][0]['errors'][0]['message']);

        $this->patchJson("/api/v1/imports/{$id}", ['mode' => 'upsert'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);
        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())
            ->assertJsonPath('data.created_rows', 1)->assertJsonPath('data.updated_rows', 1)->assertJsonPath('data.failed_rows', 0);

        $this->inTenant(function () {
            $this->assertSame('Article un', Item::query()->where('code', 'ITEM-1')->value('name'));
            $this->assertSame('service', Item::query()->where('code', 'ITEM-9')->value('type'));
            // Blank cells keep the stored value: the category stays.
            $this->assertNotNull(Item::query()->where('code', 'ITEM-1')->value('category_id'));
        });

        // The mapping is remembered for the next file of this importer.
        $next = $this->upload('items', $this->csvFile([['Nom', 'Code', 'Type d’article', 'Unité de base'], ['X', 'X-1', 'stock', 'EA']]))->json('data.mapping');
        $this->assertEqualsCanonicalizing(['name' => 0, 'code' => 1, 'type' => 2, 'base_unit' => 3], array_intersect_key($next, array_flip(['name', 'code', 'type', 'base_unit'])));
    }

    public function test_custom_fields_go_through_the_custom_field_validator(): void
    {
        $this->inTenant(function () {
            CustomFieldDefinition::create(['entity' => 'item', 'key' => 'colour', 'type' => 'select', 'label' => 'Colour', 'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']]]);
            CustomFieldDefinition::create(['entity' => 'item', 'key' => 'shelf_life', 'type' => 'number', 'label' => 'Shelf life', 'max_value' => '365']);
        });

        $file = $this->xlsxFile([
            ['Code', 'Name', 'Type', 'Base unit', 'Colour', 'Shelf life'],
            ['C-1', 'One', 'stock', 'EA', 'Red', 30],
            ['C-2', 'Two', 'stock', 'EA', 'green', 10],
            ['C-3', 'Three', 'stock', 'EA', 'blue', 400],
        ]);
        $import = $this->importFile('items', $file);

        $this->assertSame([1, 2], [$import['created_rows'], $import['failed_rows']]);
        $this->inTenant(fn () => $this->assertSame(['colour' => 'red', 'shelf_life' => '30'], Item::query()->where('code', 'C-1')->sole()->custom));
    }

    public function test_field_rules_are_never_bypassed(): void
    {
        $manager = $this->inTenant(function () {
            $role = $this->role('Importer, names read-only', ['core.import.run', 'core.item.view', 'core.item.create', 'core.uom.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'name', 'mode' => 'readonly']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        $import = $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(2)]), user: $manager);

        $this->assertSame([0, 2], [$import['created_rows'], $import['failed_rows']]);
        $this->inTenant(fn () => $this->assertSame(0, Item::query()->count()));
    }

    public function test_undo_archives_created_items_and_restores_updated_ones(): void
    {
        $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(1)]));
        $import = $this->importFile('items', $this->csvFile([['Code', 'Name', 'Type', 'Base unit'], ['ITEM-1', 'Renamed', 'stock', 'EA'], ['NEW-1', 'New', 'stock', 'EA']]), 'upsert');

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->getJson("/api/v1/imports/{$import['id']}", $this->headersFor())->assertJsonPath('data.status', 'undone')->assertJsonPath('data.can_undo', false);

        $this->inTenant(function () use ($import) {
            $this->assertSame('Item 1', Item::query()->where('code', 'ITEM-1')->sole()->name);
            $this->assertNotNull(Item::query()->where('code', 'NEW-1')->sole()->archived_at);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.import.undo')->count());
        });
    }

    public function test_undo_is_refused_when_something_depends_on_the_batch(): void
    {
        $import = $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(2)]));

        // Someone changes ITEM-2 after the import.
        $itemId = $this->inTenant(fn () => Item::query()->where('code', 'ITEM-2')->value('id'));
        $this->patchJson("/api/v1/items/{$itemId}", ['name' => 'Changed'], $this->headersFor())->assertOk();

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'import_undo_blocked')
            ->assertJsonPath('blocking.count', 1)
            ->assertJsonPath('blocking.rows.0.row', 3)
            ->assertJsonPath('blocking.rows.0.reason', __('imports.usage.changed'));

        $this->inTenant(fn () => $this->assertSame(0, Item::query()->whereNotNull('archived_at')->count()));
    }

    public function test_undo_is_refused_after_24_hours(): void
    {
        $import = $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(1)]));
        $this->inTenant(fn () => Import::query()->whereKey($import['id'])->update(['finished_at' => now()->subHours(25)]));

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'import_not_undoable');
    }

    public function test_permission_denied_and_other_tenants(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->upload('items', $this->csvFile([self::HEADERS, ...$this->rows(1)]), $cashier)->assertForbidden();
        $this->getJson('/api/v1/imports', $this->headersFor($cashier))->assertForbidden();
        $this->getJson('/api/v1/imports/importers/items/template', $this->headersFor($cashier))->assertForbidden();

        $import = $this->importFile('items', $this->csvFile([self::HEADERS, ...$this->rows(1)]));
        $other = $this->otherTenant()['user'];

        $this->getJson("/api/v1/imports/{$import['id']}", $this->headersFor($other))->assertNotFound();
        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor($other))->assertNotFound();
        $this->getJson('/api/v1/imports', $this->headersFor($other))->assertOk()->assertJsonCount(0, 'data');

        // A signed URL is bound to its user: the other tenant's owner can't use it.
        $url = $import['result_file_url'];
        $forged = preg_replace('/user=[0-9a-f-]+/', 'user='.$other->id, $url);
        $this->get($forged)->assertForbidden();
        $this->get($url)->assertOk();
    }

    public function test_templates_download_in_english_and_french(): void
    {
        foreach (['en', 'fr'] as $locale) {
            $response = $this->get("/api/v1/imports/importers/items/template?locale={$locale}", $this->headersFor())->assertOk();
            $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));
        }

        $this->getJson('/api/v1/imports/importers', $this->headersFor())->assertOk()
            ->assertJsonPath('data.0.key', 'items')
            ->assertJsonPath('data.0.columns.0.key', 'code');
    }

    public function test_one_import_runs_per_tenant_and_importer(): void
    {
        $first = $this->upload('items', $this->csvFile([self::HEADERS, ...$this->rows(1)]))->json('data.id');
        $second = $this->upload('items', $this->csvFile([self::HEADERS, ...$this->rows(1, 5)]))->json('data.id');
        $this->inTenant(fn () => Import::query()->whereKey($first)->update(['status' => 'importing']));

        $this->postJson("/api/v1/imports/{$second}/run", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'import_running');
    }

    public function test_a_50000_row_file_runs_through_the_job(): void
    {
        $rows = [['Code', 'Name', 'Type', 'Base unit']];

        for ($i = 1; $i <= 50000; $i++) {
            $rows[] = ["B-{$i}", "Bulk {$i}", 'stock', 'EA'];
        }

        $id = $this->upload('items', $this->csvFile($rows))->assertCreated()->assertJsonPath('data.total_rows', 50000)->json('data.id');
        $started = microtime(true);
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);
        $seconds = microtime(true) - $started;

        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.created_rows', 50000)
            ->assertJsonPath('data.failed_rows', 0);
        $this->inTenant(fn () => $this->assertSame(50000, Item::query()->count()));
        $this->assertLessThan(600, $seconds, "50,000 items took {$seconds} s");
        fwrite(STDERR, sprintf("\n50,000 items imported in %.1f s\n", $seconds));
    }
}
