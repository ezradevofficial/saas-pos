<?php

namespace Modules\Manufacturing\Tests;

use Illuminate\Support\Facades\Storage;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Tests\Concerns\BuildsManufacturing;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MFG-23, IMP-01, IMP-02: BOM lines come in from a file into the item's
// draft version (made when missing), checked as in the editor; an import
// is undone while its version is still a draft, never once published.
class ImportTest extends TestCase
{
    use BuildsImports, BuildsManufacturing, RefreshTenantDatabase;

    private const HEADERS = ['Company', 'Item code', 'BOM kind', 'Output quantity', 'Output unit', 'Labour minutes', 'Line kind', 'Component code', 'Quantity', 'Unit',
        'Yield %', 'Wastage %', 'Explode', 'Cost share %'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->setUpManufacturing();
    }

    public function test_lines_go_into_a_draft_and_bad_rows_are_reported(): void
    {
        $import = $this->importFile('bom_lines', $this->csvFile([self::HEADERS,
            ['Acme', 'BREAD', 'production', '100', 'EA', '120', 'component', 'FLOUR', '50000', 'G', '', '2', '', ''],
            ['Acme', 'BREAD', 'production', '100', 'EA', '120', '', 'YEAST', '0.5', '', '', '', '', ''],
            ['Acme', 'BREAD', 'production', '100', 'EA', '120', 'by_product', 'BRAN', '5', 'KG', '', '', '', '10'],
            ['Acme', 'BREAD', 'production', '100', 'EA', '120', 'component', 'NOPE', '1', '', '', '', '', ''],
            ['Acme', 'BREAD', 'recipe', '1', '', '', 'component', 'FLOUR', '1', '', '', '', '', ''],
        ]));

        // The unknown component and the recipe on a stock item are refused.
        $this->assertSame([3, 2], [$import['created_rows'], $import['failed_rows']]);
        $bom = $this->api('GET', 'manufacturing/boms')->assertOk()->json('data.0');
        $draft = $this->api('GET', "manufacturing/bom-versions/{$bom['draft_id']}")->assertOk()->json('data');
        $this->assertSame([['FLOUR', '50000', '50', '2'], ['YEAST', '0.5', '0.5', '0'], ['BRAN', '5', '5', '0']],
            array_map(fn ($l) => [$l['item']['code'], $l['qty'], $l['qty_base'], $l['wastage_percent']], $draft['lines']));
        $this->assertSame(['100', 120], [$draft['output_qty'], $draft['labour_minutes']]);

        // Undone while the version is a draft.
        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->assertSame(0, $this->inTenant(fn () => BomLine::query()->count()));
    }

    public function test_an_import_whose_version_was_published_is_not_undone(): void
    {
        $import = $this->importFile('bom_lines', $this->csvFile([self::HEADERS,
            ['Acme', 'BREAD', 'production', '100', 'EA', '120', 'component', 'FLOUR', '50', 'KG', '', '', '', ''],
        ]));
        $bom = $this->api('GET', 'manufacturing/boms')->assertOk()->json('data.0');
        $this->api('POST', "manufacturing/bom-versions/{$bom['draft_id']}/publish")->assertOk();

        $this->postJson("/api/v1/imports/{$import['id']}/undo", [], $this->headersFor())->assertStatus(202);
        $this->assertSame(1, $this->inTenant(fn () => BomLine::query()->count()));
        $this->api('GET', "imports/{$import['id']}")->assertOk()->assertJsonPath('data.undo_failed_rows', 1);
    }
}
