<?php

namespace Tests\Feature\Core\Imports;

use App\Core\Audit\Auditor;
use App\Core\Imports\ImportRow;
use App\Core\MasterData\Items\DefaultUoms;
use App\Core\MasterData\Items\Item;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * IMP-03: a 50,000-row file, generated here, runs through the import job
 * with every row passing the item API's rules, in committed batches of
 * 500 as in production. Commits for real (no wrapping transaction: one
 * transaction holding 50,000 rows' savepoints runs out of lock space),
 * so the next test migrates afresh, as SyncConcurrencyTest does.
 */
#[Group('large')]
class LargeImportTest extends TestCase
{
    use BuildsImports, BuildsOrganisation, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    /** Measured locally at about 6 minutes (8 ms a row); the bound leaves room for slower runners. */
    private const MAX_SECONDS = 1200;

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_a_50000_row_file_runs_through_the_job_in_batches(): void
    {
        Storage::fake('media');
        $this->setUpOrganisation();
        $this->inTenant(fn () => app(DefaultUoms::class)->seed());
        $rows = [['Code', 'Name', 'Type', 'Base unit']];

        for ($i = 1; $i <= 49998; $i++) {
            $rows[] = ["B-{$i}", "Bulk {$i}", 'stock', 'EA'];
        }

        // Two rows the domain refuses: a code already in the file, an unknown unit.
        $rows[] = ['B-7', 'Again', 'stock', 'EA'];
        $rows[] = ['B-49999', 'Odd', 'stock', 'XX'];

        $id = $this->upload('items', $this->csvFile($rows))->assertCreated()->assertJsonPath('data.total_rows', 50000)->json('data.id');
        $started = microtime(true);
        $this->postJson("/api/v1/imports/{$id}/run", [], $this->headersFor())->assertStatus(202);
        $seconds = microtime(true) - $started;

        $this->getJson("/api/v1/imports/{$id}", $this->headersFor())
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.processed_rows', 50000)
            ->assertJsonPath('data.created_rows', 49998)
            ->assertJsonPath('data.failed_rows', 2);

        $this->inTenant(function () use ($id) {
            $this->assertSame(49998, Item::query()->count());
            $this->assertSame(49998, ImportRow::query()->where('import_id', $id)->count());
            // AUD-03: one entry per item, chained without a gap across the 100 batches.
            $this->assertNull(app(Auditor::class)->verify($this->owner->tenant_id));
        });
        $this->assertLessThan(self::MAX_SECONDS, $seconds, sprintf('50,000 rows took %.0f s', $seconds));
        fwrite(STDERR, sprintf("\nIMP-03: 50,000 items imported in %.0f s\n", $seconds));
    }
}
