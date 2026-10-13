<?php

namespace Tests\Feature\Core\Imports;

use App\Core\Currency\TenantCurrencies;
use App\Core\Imports\Import;
use App\Core\Imports\ImportRow;
use App\Core\Imports\Jobs\RunImport;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\RecordUsage;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use ZipArchive;

// IMP-01..IMP-03 review fixes: one worker per run and conditional status
// changes, chunked and partial undo, undo as the undoer (RBAC-05),
// scoped reading (RBAC-04), lock order (AUD-03), tax ID matching in scope,
// encodings, and files that would expand without bound.
class ImportSafetyTest extends TestCase
{
    use BuildsImports, BuildsOrganisation, RefreshTenantDatabase;

    private const ITEMS = ['Code', 'Name', 'Type', 'Base unit'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(function () {
            app(DefaultUoms::class)->seed();
            app(TenantCurrencies::class)->provisionFor($this->acme);
        });
    }

    /** @return list<list<string>> */
    private function items(int $count): array
    {
        return array_map(fn (int $i) => ["IT-{$i}", "Item {$i}", 'stock', 'EA'], range(1, $count));
    }

    private function handleAgain(array $import, string $action): void
    {
        $this->inTenant(function () use ($import, $action) {
            $token = Import::query()->findOrFail($import['id'])->run_token;
            app()->call([new RunImport($this->owner->tenant_id, $import['id'], $action, $token, $this->owner->id), 'handle']);
        });
    }

    public function test_a_second_worker_of_the_same_run_changes_nothing(): void
    {
        $import = $this->importFile('items', $this->csvFile([self::ITEMS, ...$this->items(3)]));
        $this->assertSame('completed', $import['status']);

        // The same job delivered again: the run is claimed already.
        $this->handleAgain($import, RunImport::IMPORT);
        $this->inTenant(fn () => $this->assertSame(3, Item::query()->count()));
        $this->getJson("/api/v1/imports/{$import['id']}", $this->headersFor())->assertJsonPath('data.status', 'completed')->assertJsonPath('data.created_rows', 3);

        // Undone, a stale import job (or its failure handler) never moves it back.
        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->handleAgain($import, RunImport::UNDO);
        $this->inTenant(function () use ($import) {
            (new RunImport($this->owner->tenant_id, $import['id'], RunImport::IMPORT, (string) Import::query()->findOrFail($import['id'])->run_token))->failed(null);
            $this->assertSame(Import::UNDONE, Import::query()->findOrFail($import['id'])->status);
        });
    }

    public function test_undo_runs_in_chunks_and_stops_part_way_when_something_comes_to_depend(): void
    {
        config(['imports.undo_chunk' => 2]);
        $import = $this->importFile('items', $this->csvFile([self::ITEMS, ...$this->items(5)]));
        $first = $this->inTenant(fn () => Item::query()->where('code', 'IT-1')->value('id'));

        // A sale of IT-1 arrives while the undo runs: not seen by the request's
        // check (the first question), seen by the chunk that reaches it.
        $asked = 0;
        app(RecordUsage::class)->register('item', 'sale', function (array $ids) use (&$asked, $first) {
            return ++$asked > 1 && in_array($first, $ids, true) ? [$first] : [];
        });

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);

        $this->getJson("/api/v1/imports/{$import['id']}", $this->headersFor())
            ->assertJsonPath('data.status', 'partly_undone')
            ->assertJsonPath('data.blocking.0.row', 2)
            ->assertJsonPath('data.blocking.0.reason', __('imports.usage.sale'))
            ->assertJsonPath('data.can_undo', true);
        $this->inTenant(function () use ($import) {
            // Rows 6..3 were undone (last first, two a chunk), row 2 stays.
            $this->assertSame(4, ImportRow::query()->where('import_id', $import['id'])->whereNotNull('undone_at')->count());
            $this->assertSame(['IT-1'], Item::query()->whereNull('archived_at')->pluck('code')->all());
        });
    }

    public function test_undo_goes_through_the_undoers_field_rules(): void
    {
        $this->importFile('items', $this->csvFile([self::ITEMS, ...$this->items(1)]));
        $update = $this->importFile('items', $this->csvFile([self::ITEMS, ['IT-1', 'Renamed', 'stock', 'EA']]), 'upsert');

        $clerk = $this->inTenant(function () {
            $role = $this->role('Undoer, names read-only', ['core.import.run', 'core.item.view', 'core.item.create', 'core.item.edit', 'core.item.archive', 'core.uom.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'name', 'mode' => 'readonly']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });

        // The clerk reaches the import (shared items) but may not put the name back.
        $this->postJson("/api/v1/imports/{$update['id']}/undo", [], $this->headersFor($clerk))->assertStatus(202);
        $this->getJson("/api/v1/imports/{$update['id']}", $this->headersFor($clerk))
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.blocking.0.row', 2)
            ->assertJsonPath('data.error_file_url', null)
            ->assertJsonPath('data.result_file_url', null);
        $this->inTenant(fn () => $this->assertSame('Renamed', Item::query()->where('code', 'IT-1')->value('name')));
    }

    public function test_readers_must_reach_every_company_the_import_touched(): void
    {
        $beta = $this->inTenant(fn () => $this->company('Beta'));
        $this->postJson("/api/v1/companies/{$this->acme->id}/price-lists", ['name' => 'Retail', 'currency' => 'KES', 'is_default' => true], $this->headersFor())->assertCreated();
        $this->importFile('items', $this->csvFile([self::ITEMS, ...$this->items(1)]));
        $prices = $this->importFile('item_prices', $this->csvFile([['Item code', 'Price list', 'Price'], ['IT-1', 'Retail', '10']]));

        $betaPricer = $this->inTenant(function () use ($beta) {
            $role = $this->role('Beta pricer', ['core.import.run', 'core.price.view', 'core.price.edit', 'core.item.view']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::company($beta->id));

            return $user;
        });
        $admin = $this->userWith('admin', Scope::tenant());

        $this->getJson("/api/v1/imports/{$prices['id']}", $this->headersFor($betaPricer))->assertNotFound();
        $this->getJson('/api/v1/imports?per_page=50', $this->headersFor($betaPricer))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/imports/{$prices['id']}/undo", [], $this->headersFor($betaPricer))->assertNotFound();

        // An admin reaches Acme: sees the import, but the file's own rows stay the uploader's.
        $this->getJson("/api/v1/imports/{$prices['id']}", $this->headersFor($admin))->assertOk()->assertJsonPath('data.result_file_url', null);
        $this->assertCount(2, $this->getJson('/api/v1/imports?per_page=50', $this->headersFor($admin))->json('data'));
        $this->assertNotNull($prices['result_file_url']);
        $this->get(preg_replace('/user=[0-9a-f-]+/', 'user='.$admin->id, $prices['result_file_url']))->assertForbidden();
    }

    public function test_the_importers_locks_come_before_the_audit_head(): void
    {
        $id = $this->upload('items', $this->csvFile([self::ITEMS, ...$this->items(2)]))->json('data.id');
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);

        // From the worker's claim on: the request audits the start (its own head lock) before.
        $job = collect($queries)->slice((int) collect($queries)->search(fn (string $sql) => str_contains($sql, '"claimed_at" is null')));
        $sharing = $job->search(fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock'));
        $head = $job->search(fn (string $sql) => str_contains($sql, 'from audit_chain_heads') && str_contains($sql, 'for update'));
        $this->assertNotFalse($sharing);
        $this->assertNotFalse($head);
        $this->assertLessThan($head, $sharing, 'the sharing lock is taken before the audit head');
    }

    public function test_tax_ids_match_in_the_rows_scope_and_ambiguous_matches_are_refused(): void
    {
        foreach (['One', 'Two'] as $name) {
            $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => $name, 'tax_id' => 'P0511', 'roles' => ['customer']], $this->headersFor())->assertCreated();
        }

        $id = $this->upload('customers', $this->csvFile([['Name', 'Tax ID'], ['Three', 'P0511']]))->json('data.id');
        $this->patchJson("/api/v1/imports/{$id}", ['mode' => 'upsert'], $this->headersFor())->assertOk();
        $row = $this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->json('data.rows.0');

        $this->assertSame('tax_id', $row['errors'][0]['column']);
        $this->assertSame(__('imports.errors.ambiguous', ['value' => 'P0511']), $row['errors'][0]['message']);
    }

    public function test_latin1_csv_is_read_as_utf8_and_other_encodings_are_refused(): void
    {
        $latin1 = mb_convert_encoding("Nom;Numéro fiscal\nCafé Élysée;P0599\n", 'ISO-8859-1', 'UTF-8');
        $path = tempnam(sys_get_temp_dir(), 'latin1-');
        file_put_contents($path, $latin1);

        $import = $this->importFile('customers', new UploadedFile($path, 'clients.csv', 'text/csv', null, true));
        $this->assertSame(1, $import['created_rows']);
        $this->inTenant(fn () => $this->assertSame('Café Élysée', Party::query()->where('tax_id', 'P0599')->value('name')));

        $utf16 = tempnam(sys_get_temp_dir(), 'utf16-');
        file_put_contents($utf16, "\xFF\xFE".mb_convert_encoding("Name\nX\n", 'UTF-16LE', 'UTF-8'));
        $this->upload('customers', new UploadedFile($utf16, 'clients.csv', 'text/csv', null, true))
            ->assertUnprocessable()->assertJsonPath('code', 'file_encoding')->assertJsonPath('message', __('imports.errors.file_encoding'));
    }

    public function test_files_that_expand_without_bound_are_refused(): void
    {
        // 100,001 physical rows, almost all empty.
        $path = tempnam(sys_get_temp_dir(), 'blank-');
        file_put_contents($path, "Code,Name,Type,Base unit\nX,Y,stock,EA\n".str_repeat(",,,\n", 100000));
        $this->upload('items', new UploadedFile($path, 'blank.csv', 'text/csv', null, true))->assertUnprocessable()->assertJsonPath('code', 'too_many_rows');

        // An XLSX declaring more shared strings than anyone types.
        $xlsx = tempnam(sys_get_temp_dir(), 'bomb-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($xlsx, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="x" count="5000000" uniqueCount="5000000"></sst>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();
        $this->upload('items', new UploadedFile($xlsx, 'bomb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true))
            ->assertUnprocessable()->assertJsonPath('code', 'file_too_large');

        // A cell longer than 10,000 characters is a row error.
        $id = $this->upload('items', $this->csvFile([self::ITEMS, ['LONG', str_repeat('x', 10001), 'stock', 'EA']]))->json('data.id');
        $this->assertSame(__('imports.cells.too_long'), $this->getJson("/api/v1/imports/{$id}/preview", $this->headersFor())->json('data.rows.0.errors.0.message'));
        $this->inTenant(fn () => $this->assertSame(0, Import::query()->whereIn('file_name', ['blank.csv', 'bomb.xlsx'])->count()));
    }
}
