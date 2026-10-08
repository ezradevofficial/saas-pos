<?php

namespace Tests\Feature\Core\MasterData;

use App\Core\Audit\AuditEntry;
use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\TenantCurrencies;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Lists and pickers plan, API contract: `?sort` whitelisted per list (422
// otherwise, ties by id) and exports to CSV, Excel and PDF of every
// matching row in the user's language (EXP-01), without fields hidden by
// field rules (RBAC-05), audited (AUD-01), never another tenant's rows
// (TEN-01). Wired into items (MD-02) and parties (MD-01).
class ListSortAndExportTest extends TestCase
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
            app(TenantCurrencies::class)->activate('KES');

            return Uom::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [strtoupper($code) => $id])->all();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function item(string $code, array $extra = []): string
    {
        return $this->postJson('/api/v1/items', [
            'code' => $code, 'name' => 'Item '.$code, 'type' => 'stock', 'base_uom_id' => $this->uoms['EA'], ...$extra,
        ], $this->headersFor())->assertCreated()->json('data.id');
    }

    private function category(string $name): string
    {
        return $this->postJson('/api/v1/item-categories', ['name' => $name], $this->headersFor())->assertCreated()->json('data.id');
    }

    private function party(string $name, array $extra = []): string
    {
        return $this->postJson('/api/v1/parties', ['kind' => 'organisation', 'name' => $name, 'roles' => ['customer'], ...$extra], $this->headersFor())
            ->assertCreated()->json('data.id');
    }

    /** @return list<string> */
    private function ids(string $url, ?array $headers = null): array
    {
        return array_column($this->getJson($url, $headers ?? $this->headersFor())->assertOk()->json('data'), 'id');
    }

    /** @return list<list<string>> */
    private function csv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        $this->assertStringStartsWith("\u{FEFF}", $content, 'CSV starts with a UTF-8 BOM');

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @return list<list<string>> */
    private function xlsx(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($path, $response->streamedContent());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($cell) => (string) $cell->getValue(), $row->cells);
            }

            break;
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    public function test_items_sort_by_whitelisted_keys_both_ways_with_ties_by_id(): void
    {
        $drinks = $this->category('Drinks');
        $bakery = $this->category('Bakery');
        $b = $this->item('B-1', ['name' => 'Apple juice', 'category_id' => $drinks]);
        $a = $this->item('A-1', ['name' => 'Bread', 'category_id' => $bakery]);
        $c = $this->item('C-1', ['name' => 'Cake', 'type' => 'service']);

        // Default: by code.
        $this->assertSame([$a, $b, $c], $this->ids('/api/v1/items'));
        $this->assertSame([$c, $b, $a], $this->ids('/api/v1/items?sort=-code'));
        $this->assertSame([$b, $a, $c], $this->ids('/api/v1/items?sort=name'));
        $this->assertSame([$c, $a, $b], $this->ids('/api/v1/items?sort=-name'));
        // Category by its name; items without one last.
        $this->assertSame([$a, $b, $c], $this->ids('/api/v1/items?sort=category'));
        $this->assertSame([$b, $a, $c], $this->ids('/api/v1/items?sort=-category'));

        // Equal sort values (two stock items): ties broken by id, so pages are stable.
        $stock = [$a, $b];
        sort($stock);
        $this->assertSame([$c, ...$stock], $this->ids('/api/v1/items?sort=type'));
        $this->assertSame([...array_reverse($stock), $c], $this->ids('/api/v1/items?sort=-type'));
        $this->assertSame([$stock[0]], $this->ids('/api/v1/items?sort=type&per_page=1&page=2'));

        $this->getJson('/api/v1/items?sort=price', $this->headersFor())->assertUnprocessable()
            ->assertJsonValidationErrors('sort')
            ->assertJsonPath('errors.sort.0', 'This list can’t be sorted by “price”. Choose another column.');
        $this->getJson('/api/v1/items?sort=--code', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/items?sort=price', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertUnprocessable()
            ->assertJsonPath('errors.sort.0', 'Cette liste ne peut pas être triée par « price ». Choisissez une autre colonne.');
        $this->getJson('/api/v1/items?per_page=201', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_a_search_orders_by_relevance_unless_a_sort_is_asked_for(): void
    {
        $exact = $this->item('Z-1', ['name' => 'Water']);
        $near = $this->item('A-2', ['name' => 'Waterbottle']);

        $this->assertSame([$exact, $near], $this->ids('/api/v1/items?search=water'));
        $this->assertSame([$near, $exact], $this->ids('/api/v1/items?search=water&sort=code'));
    }

    public function test_parties_sort_by_whitelisted_keys(): void
    {
        $b = $this->party('Beta', ['payment_terms_days' => 7]);
        $a = $this->party('Alpha', ['payment_terms_days' => 30]);

        $this->assertSame([$a, $b], $this->ids('/api/v1/parties'));
        $this->assertSame([$b, $a], $this->ids('/api/v1/parties?sort=-name'));
        $this->assertSame([$b, $a], $this->ids('/api/v1/parties?sort=payment_terms'));
        $this->assertSame([$b, $a], $this->ids('/api/v1/parties?sort=created_at'));
        $this->assertSame([$a, $b], $this->ids('/api/v1/parties?sort=-created_at'));
        $this->getJson('/api/v1/parties?sort=credit_limit_minor', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_items_export_to_csv_in_the_users_language_and_is_audited(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-08 21:30:00', 'UTC'));
        $drinks = $this->category('Drinks');
        $this->item('SODA', ['name' => 'Soda 500 ml', 'category_id' => $drinks, 'barcodes' => [['barcode' => '111'], ['barcode' => '222']]]);
        $this->item('DEL', ['name' => 'Délivraison', 'type' => 'service']);
        $archived = $this->item('OLD', ['name' => 'Old']);
        $this->postJson("/api/v1/items/{$archived}/archive", [], $this->headersFor())->assertOk();

        $response = $this->get('/api/v1/items?format=csv&sort=-code', $this->headersFor())->assertOk();

        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        // The file is dated in the company's time zone (00:30 on the 9th in Nairobi).
        $this->assertStringContainsString('attachment; filename=items-2026-10-09.csv', $response->headers->get('Content-Disposition'));
        $rows = $this->csv($response);
        $this->assertSame(['Code', 'Name', 'Category', 'Type', 'Base unit', 'Barcodes', 'Tax category', 'Status', 'Created', 'Updated'], $rows[0]);
        $this->assertCount(3, $rows, 'Archived items are not listed by default');
        $this->assertSame(['SODA', 'Soda 500 ml', 'Drinks', 'Stock item', 'EA', '111, 222', '', 'Active', '9 Oct 2026, 00:30', '9 Oct 2026, 00:30'], $rows[1]);
        $this->assertSame(['DEL', 'Délivraison', '', 'Service'], array_slice($rows[2], 0, 4));

        // Chosen columns, in the order asked; French headers and values.
        $fr = $this->get('/api/v1/items?format=csv&status=all&columns[]=status&columns[]=code&columns[]=type', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk();
        $this->assertSame([['Statut', 'Code', 'Type'], ['Actif', 'DEL', 'Service'], ['Archivé', 'OLD', 'Article stocké'], ['Actif', 'SODA', 'Article stocké']], $this->csv($fr));

        // The list's search applies, and every matching row is exported (no paging).
        $this->assertSame([['Code'], ['SODA']], $this->csv($this->get('/api/v1/items?format=csv&search=soda&columns[]=code', $this->headersFor())));
        $this->assertCount(4, $this->csv($this->get('/api/v1/items?format=csv&status=all&per_page=1&columns[]=code', $this->headersFor())));

        $this->get('/api/v1/items?format=csv&columns[]=price', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('columns.0');
        $this->get('/api/v1/items?format=docx', $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('format');

        $this->inTenant(function () {
            $entries = AuditEntry::where('action', 'core.item.export')->orderBy('seq')->get();
            $this->assertCount(4, $entries);
            $this->assertSame($this->owner->id, $entries[0]->user_id);
            $this->assertSame('csv', $entries[0]->after['format']);
            $this->assertSame(2, $entries[0]->after['rows']);
            $this->assertSame(['code', 'name', 'category', 'type', 'base_unit', 'barcodes', 'tax_category', 'status', 'created_at', 'updated_at'], $entries[0]->after['columns']);
            $this->assertSame(['sort' => '-code'], $entries[0]->after['filters']);
            $this->assertSame(['status', 'code', 'type'], $entries[1]->after['columns']);
            $this->assertSame(3, $entries[1]->after['rows']);
        });
    }

    public function test_items_export_to_xlsx_with_a_bold_frozen_header(): void
    {
        $this->item('A-1', ['name' => 'Ananas']);
        $this->item('B-1', ['name' => '=1+1']);

        $response = $this->get('/api/v1/items?format=xlsx&columns[]=code&columns[]=name', $this->headersFor())->assertOk();

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertMatchesRegularExpression('/attachment; filename=items-\d{4}-\d{2}-\d{2}\.xlsx/', $response->headers->get('Content-Disposition'));
        $content = $response->streamedContent();
        $this->assertSame([['Code', 'Name'], ['A-1', 'Ananas'], ['B-1', '=1+1']], $this->xlsx($response));

        // The sheet freezes the header row and keeps text as text (no formula).
        $zip = tempnam(sys_get_temp_dir(), 'xlsx-zip-');
        file_put_contents($zip, $content);
        $archive = new \ZipArchive;
        $archive->open($zip);
        $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
        $styles = $archive->getFromName('xl/styles.xml');
        $archive->close();
        unlink($zip);
        $this->assertStringContainsString('state="frozen"', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        $this->assertStringContainsString('<b/>', $styles);
    }

    public function test_parties_export_to_pdf_and_csv_with_money_and_translated_values(): void
    {
        $this->party('Duka Moja', [
            'roles' => ['customer', 'supplier'],
            'phones' => [['number' => '0712345678']],
            'emails' => [['address' => 'orders@duka.example']],
            'tax_id' => 'P051234567A',
            'payment_terms_days' => 30,
            'credit_limit' => '150000.50',
            'credit_limit_currency' => 'KES',
        ]);

        $columns = 'columns[]=name&columns[]=kind&columns[]=roles&columns[]=phones&columns[]=emails&columns[]=tax_id&columns[]=credit_limit&columns[]=payment_terms&columns[]=status';
        $rows = $this->csv($this->get("/api/v1/parties?format=csv&{$columns}", $this->headersFor())->assertOk());
        $this->assertSame(['Name', 'Kind', 'Roles', 'Phone numbers', 'Email addresses', 'Tax ID', 'Credit limit', 'Payment terms', 'Status'], $rows[0]);
        $this->assertSame(['Duka Moja', 'Organisation', 'Customer, Supplier', '+254712345678', 'orders@duka.example', 'P051234567A', 'KES 150,000.50', '30 days', 'Active'], $rows[1]);

        $fr = $this->csv($this->get("/api/v1/parties?format=csv&{$columns}", [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk());
        $this->assertSame(['Organisation', 'Client, Fournisseur'], array_slice($fr[1], 1, 2));
        $this->assertMatchesRegularExpression('/^KES 150\h000,50$/u', $fr[1][6]);
        $this->assertSame('30 jours', $fr[1][7]);

        $pdf = $this->get('/api/v1/parties?format=pdf&role=customer', $this->headersFor())->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertMatchesRegularExpression('/attachment; filename=parties-\d{4}-\d{2}-\d{2}\.pdf/', $pdf->headers->get('Content-Disposition'));
        $content = $pdf->streamedContent();
        $this->assertStringStartsWith('%PDF-', $content);
        // The title (filtered by role) is in the document info, UTF-16 encoded.
        $this->assertStringContainsString('/Title ('."\xFE\xFF".mb_convert_encoding('Customers', 'UTF-16BE', 'UTF-8').')', $content);

        $this->inTenant(fn () => $this->assertSame(['csv', 'csv', 'pdf'], AuditEntry::where('action', 'core.party.export')->orderBy('seq')->pluck('after')->pluck('format')->all()));
    }

    /** $count items written straight to the table (the first a service), each with a category and two barcodes. */
    private function bulkItems(int $count): void
    {
        $category = $this->category('Household and cleaning supplies');

        $this->inTenant(function () use ($count, $category) {
            $now = now();
            $tenantId = app(TenantContext::class)->require();
            $db = DB::connection(TenantContext::CONNECTION);
            $items = [];
            $barcodes = [];

            for ($i = 0; $i < $count; $i++) {
                $id = (string) Str::uuid7();
                $items[] = ['id' => $id, 'tenant_id' => $tenantId, 'code' => 'BULK-'.$i, 'name' => "Bulk item number {$i} with a longer name",
                    'type' => $i === 0 ? 'service' : 'stock', 'category_id' => $category, 'base_uom_id' => $this->uoms['EA'], 'created_at' => $now, 'updated_at' => $now];
                $barcodes[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'item_id' => $id, 'barcode' => sprintf('600%010d', $i), 'created_at' => $now, 'updated_at' => $now];
                $barcodes[] = ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'item_id' => $id, 'barcode' => sprintf('700%010d', $i), 'created_at' => $now, 'updated_at' => $now];
            }

            foreach (array_chunk($items, 1000) as $chunk) {
                $db->table('items')->insert($chunk);
            }

            foreach (array_chunk($barcodes, 1000) as $chunk) {
                $db->table('item_barcodes')->insert($chunk);
            }
        });
    }

    /** Peak memory (bytes above the start) of a full items PDF of $rows rows, all columns. */
    private function pdfPeakMemory(int $rows): int
    {
        $this->bulkItems($rows);
        $headers = $this->headersFor();
        gc_collect_cycles();
        $start = memory_get_usage();
        memory_reset_peak_usage();

        $content = $this->get('/api/v1/items?format=pdf', $headers)->assertOk()->streamedContent();
        $peak = memory_get_peak_usage() - $start;

        $this->assertStringStartsWith('%PDF-', $content);

        return $peak;
    }

    public function test_a_pdf_at_the_cap_stays_within_its_memory_budget(): void
    {
        // Measured 2026-10-08 (all ten columns): 61 MB / 1.5 s at 250 rows, 111 MB / 3.1 s at 500.
        $peak = $this->pdfPeakMemory(ListExport::PDF_MAX_ROWS);

        $this->assertLessThan(192 * 1024 * 1024, $peak, sprintf('a %d-row PDF peaked at %.0f MB', ListExport::PDF_MAX_ROWS, $peak / 1048576));
    }

    public function test_a_pdf_of_more_than_the_cap_is_refused_before_streaming(): void
    {
        $this->bulkItems(ListExport::PDF_MAX_ROWS + 1);

        $this->getJson('/api/v1/items?format=pdf', $this->headersFor())->assertUnprocessable()
            ->assertJsonPath('code', 'export_too_many_rows')
            ->assertJsonPath('message', 'Too many rows for a PDF. Narrow the filters or export to Excel.');
        $this->getJson('/api/v1/items?format=pdf', [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertUnprocessable()
            ->assertJsonPath('message', 'Trop de lignes pour un PDF. Affinez les filtres ou exportez vers Excel.');
        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.item.export')->count()));

        // Narrowed by a filter, the PDF is allowed.
        $this->get('/api/v1/items?format=pdf&type=service', $this->headersFor())->assertOk();
    }

    public function test_fields_hidden_by_field_rules_never_reach_an_export(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.item.view', 'core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'barcodes', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'credit_limit_minor', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'tax_id', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $this->item('H1', ['name' => 'Hammer', 'barcodes' => [['barcode' => '999111']]]);
        $this->party('Secret Ltd', ['tax_id' => 'P000SECRET', 'credit_limit' => '777', 'credit_limit_currency' => 'KES']);

        $items = $this->get('/api/v1/items?format=csv&columns[]=code&columns[]=barcodes', $this->headersFor($clerk))->assertOk();
        $this->assertSame([['Code'], ['H1']], $this->csv($items));

        // Excel: the decoded cells hold neither the columns nor their values.
        $rows = $this->xlsx($this->get('/api/v1/parties?format=xlsx', $this->headersFor($clerk))->assertOk());
        $this->assertNotContains('Credit limit', $rows[0]);
        $this->assertNotContains('Tax ID', $rows[0]);
        $this->assertContains('Name', $rows[0]);
        $this->assertSame(['Secret Ltd'], array_column(array_slice($rows, 1), array_search('Name', $rows[0], true)));
        $cells = implode("\n", array_merge(...$rows));
        $this->assertStringNotContainsString('P000SECRET', $cells);
        $this->assertStringNotContainsString('777', $cells);

        // PDF: the HTML handed to dompdf has neither.
        $html = $this->capturePdfHtml(fn () => $this->get('/api/v1/parties?format=pdf', $this->headersFor($clerk))->assertOk()->streamedContent());
        $this->assertStringContainsString('Secret Ltd', $html);
        $this->assertStringNotContainsString('P000SECRET', $html);
        $this->assertStringNotContainsString('KES 777', $html);
        $this->assertStringNotContainsString('Credit limit', $html);

        // The owner, without those rules, gets them.
        $owner = $this->csv($this->get('/api/v1/parties?format=csv', $this->headersFor()));
        $this->assertContains('Credit limit', $owner[0]);
        $this->assertContains('KES 777.00', $owner[1]);
        $this->assertContains('P000SECRET', $owner[1]);

        $this->inTenant(fn () => $this->assertSame(['code'], AuditEntry::where('action', 'core.item.export')->sole()->after['columns']));
    }

    /** The HTML ListExport hands to dompdf while $run runs. */
    private function capturePdfHtml(callable $run): string
    {
        $captured = new \ArrayObject;
        $this->app->bind(ListExport::class, fn ($app) => new class($app->make(TenantContext::class), $app->make(Auditor::class), $app->make(CurrencyDecimals::class), $captured) extends ListExport
        {
            public function __construct($tenants, $auditor, $decimals, private \ArrayObject $captured)
            {
                parent::__construct($tenants, $auditor, $decimals);
            }

            protected function renderPdf(string $html): string
            {
                $this->captured->append($html);

                return parent::renderPdf($html);
            }
        });

        try {
            $content = $run();
            $this->assertStringStartsWith('%PDF-', $content);
        } finally {
            $this->app->offsetUnset(ListExport::class);
        }

        $this->assertCount(1, $captured);

        return $captured[0];
    }

    public function test_pdf_values_are_escaped_and_rows_split_into_tables_with_the_header_repeated(): void
    {
        $this->item('X-1', ['name' => '<b onmouseover="x">Bold</b> & co']);

        $html = $this->capturePdfHtml(fn () => $this->get('/api/v1/items?format=pdf&columns[]=code&columns[]=name', $this->headersFor())->assertOk()->streamedContent());

        $this->assertStringContainsString('&lt;b onmouseover=&quot;x&quot;&gt;Bold&lt;/b&gt; &amp; co', $html);
        $this->assertStringNotContainsString('<b onmouseover', $html);
        $this->assertStringContainsString('table-layout: fixed', $html);
        $this->assertStringNotContainsString('page-break-inside', $html);

        // More rows than one table holds: a new table, with its header row, every PDF_TABLE_ROWS rows.
        $this->bulkItems(ListExport::PDF_TABLE_ROWS + 5);
        $html = $this->capturePdfHtml(fn () => $this->get('/api/v1/items?format=pdf&columns[]=code', $this->headersFor())->assertOk()->streamedContent());
        $this->assertSame(2, substr_count($html, '<table>'));
        $this->assertSame(2, substr_count($html, '<th>Code</th>'));
        $this->assertSame(ListExport::PDF_TABLE_ROWS + 6, substr_count($html, '<td>'));
    }

    public function test_sorting_or_searching_by_a_hidden_field_is_refused_or_ignored(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.item.view', 'core.party.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'barcodes', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'category_id', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'tax_id', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'party', 'field' => 'payment_terms_days', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $item = $this->item('H1', ['name' => 'Hammer', 'barcodes' => [['barcode' => '999111']]]);
        $party = $this->party('Secret Ltd', ['tax_id' => 'P000SECRET', 'payment_terms_days' => 30]);
        $clerkHeaders = $this->headersFor($clerk);

        // Sorting by a hidden field would reveal its ranking (RBAC-05).
        $this->getJson('/api/v1/items?sort=category', $clerkHeaders)->assertUnprocessable()
            ->assertJsonPath('errors.sort.0', 'You can’t sort by a field you can’t see. Choose another column.');
        $this->getJson('/api/v1/parties?sort=-tax_id', [...$clerkHeaders, 'Accept-Language' => 'fr'])->assertUnprocessable()
            ->assertJsonPath('errors.sort.0', 'Vous ne pouvez pas trier par un champ que vous ne voyez pas. Choisissez une autre colonne.');
        $this->get('/api/v1/parties?sort=payment_terms&format=csv', [...$clerkHeaders, 'Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/parties?sort=name', $clerkHeaders)->assertOk();
        // The owner may.
        $this->getJson('/api/v1/items?sort=category', $this->headersFor())->assertOk();
        $this->getJson('/api/v1/parties?sort=-tax_id', $this->headersFor())->assertOk();

        // A search never matches a hidden field: the owner finds by tax ID and barcode, the clerk does not.
        $this->assertSame([$party], $this->ids('/api/v1/parties?search=P000SECRET'));
        $this->assertSame([], $this->ids('/api/v1/parties?search=P000SECRET', $clerkHeaders));
        $this->assertSame([$party], $this->ids('/api/v1/parties?search=Secret', $clerkHeaders));
        $this->assertSame([$item], $this->ids('/api/v1/items?search=999111'));
        $this->assertSame([], $this->ids('/api/v1/items?search=999111', $clerkHeaders));
        $this->assertSame([$item], $this->ids('/api/v1/items?search=H1', $clerkHeaders));
    }

    public function test_a_default_sort_on_a_hidden_field_falls_back_to_id(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.item.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'code', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $z = $this->item('Z-1');
        $a = $this->item('A-1');

        $this->assertSame([$a, $z], $this->ids('/api/v1/items'));
        $this->assertSame([$z, $a], $this->ids('/api/v1/items', $this->headersFor($clerk)));
        $this->getJson('/api/v1/items?sort=code', $this->headersFor($clerk))->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_a_category_filter_outside_the_users_companies_is_refused(): void
    {
        $globexCategory = $this->inTenant(fn () => ItemCategory::create(['company_id' => $this->company('Globex')->id, 'name' => 'Globex secret'])->id);
        $acmeCategory = $this->inTenant(fn () => ItemCategory::create(['company_id' => $this->acme->id, 'name' => 'Acme goods'])->id);
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));

        $this->getJson("/api/v1/items?category={$globexCategory}", $cashier)->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->get("/api/v1/items?format=pdf&category={$globexCategory}", [...$cashier, 'Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->getJson("/api/v1/items?category={$acmeCategory}", $cashier)->assertOk();
        // The owner reaches every company.
        $this->getJson("/api/v1/items?category={$globexCategory}", $this->headersFor())->assertOk();

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.item.export')->count()));
    }

    public function test_an_export_of_only_hidden_columns_is_refused_and_not_audited(): void
    {
        $clerk = $this->inTenant(function () {
            $role = $this->role('Clerk', ['core.item.view']);
            FieldRule::create(['role_id' => $role->id, 'resource' => 'item', 'field' => 'barcodes', 'mode' => 'hidden']);
            $user = $this->colleague($this->owner);
            $this->assign($user, $role, Scope::tenant());

            return $user;
        });
        $this->item('H1', ['barcodes' => [['barcode' => '999111']]]);

        $this->get('/api/v1/items?format=csv&columns[]=barcodes', [...$this->headersFor($clerk), 'Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('code', 'export_no_columns')
            ->assertJsonPath('errors.columns.0', 'You can’t see any of the columns asked for. Choose other columns to export.');

        $this->inTenant(fn () => $this->assertSame(0, AuditEntry::where('action', 'core.item.export')->count()));
    }

    public function test_exports_are_rate_limited_per_user(): void
    {
        $this->item('P1');

        for ($i = 0; $i < ListExport::EXPORTS_PER_MINUTE; $i++) {
            $this->get('/api/v1/items?format=csv&columns[]=code', $this->headersFor())->assertOk();
        }

        $refused = $this->get('/api/v1/parties?format=xlsx', [...$this->headersFor(), 'Accept' => 'application/json'])->assertStatus(429)
            ->assertJsonPath('code', 'too_many_exports');
        $this->assertMatchesRegularExpression('/^Too many exports\. Try again in \d+ seconds?\.$/', $refused->json('message'));
        $this->assertGreaterThan(0, (int) $refused->headers->get('Retry-After'));

        // The JSON list is not an export; another user has their own allowance.
        $this->getJson('/api/v1/items', $this->headersFor())->assertOk();
        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->get('/api/v1/items?format=csv', $cashier)->assertOk();

        $this->inTenant(fn () => $this->assertSame(ListExport::EXPORTS_PER_MINUTE + 1, AuditEntry::where('action', 'core.item.export')->count()));
    }

    public function test_rows_are_read_in_the_requesters_tenant_whatever_context_is_left_when_the_body_streams(): void
    {
        $this->item('MINE', ['name' => 'Mine']);
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, function () {
            app(DefaultUoms::class)->seed();
            Item::create(['code' => 'THEIRS', 'name' => 'Theirs', 'type' => 'stock', 'base_uom_id' => Uom::query()->value('id')]);
        });
        $context = app(TenantContext::class);

        foreach ([$other['user']->tenant_id, null] as $leftOver) {
            $response = $this->get('/api/v1/items?format=csv&columns[]=code', $this->headersFor())->assertOk();
            // The body streams later (after middleware ran): set another tenant, or none.
            $context->set($leftOver);

            $this->assertSame([['Code'], ['MINE']], $this->csv($response));
            $this->assertSame($leftOver, $context->id(), 'the context is restored after streaming');
        }
    }

    public function test_an_export_never_includes_another_tenants_rows(): void
    {
        $this->item('MINE', ['name' => 'Mine']);
        $this->party('My customer');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, function () {
            app(DefaultUoms::class)->seed();
            Item::create(['code' => 'THEIRS', 'name' => 'Theirs', 'type' => 'stock', 'base_uom_id' => Uom::query()->value('id')]);
            Party::create(['kind' => 'person', 'name' => 'Their customer', 'roles' => ['customer']]);
        });

        $items = $this->csv($this->get('/api/v1/items?format=csv&columns[]=code', $this->headersFor()));
        $this->assertSame([['Code'], ['MINE']], $items);
        $parties = $this->csv($this->get('/api/v1/parties?format=csv&columns[]=name', $this->headersFor()));
        $this->assertSame([['Name'], ['My customer']], $parties);

        $theirs = $this->bearer($this->tokenFor($other['user']));
        $this->assertSame([['Code'], ['THEIRS']], $this->csv($this->get('/api/v1/items?format=csv&columns[]=code', $theirs)));
    }

    public function test_an_export_needs_the_lists_view_permission(): void
    {
        $this->item('P1');
        $hr = $this->headersFor($this->userWith('hr_officer', Scope::company($this->acme->id)));

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->get("/api/v1/items?format={$format}", [...$hr, 'Accept' => 'application/json'])->assertForbidden();
        }

        $cashier = $this->headersFor($this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->get('/api/v1/items?format=csv', $cashier)->assertOk();

        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.item.export')->count()));
    }
}
