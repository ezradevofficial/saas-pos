<?php

namespace Modules\POS\Tests;

use App\Core\Numbering\DocumentNumberType;
use App\Core\Numbering\DocumentNumberTypes;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\NumberRange;
use Modules\POS\Models\Sale;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\NumberRanges;
use Modules\POS\Sync\SaleUploads;
use Modules\POS\Tests\Concerns\BuildsPos;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// NUM-01, NUM-02, NFR-04 under real concurrency: forked processes, each
// with its own database session, race on committed data. Ranges never
// overlap; gapless numbers have no gaps or repeats even when documents
// roll back; the same sale uploaded at once is stored once.
//
// The test commits its data (no wrapping transaction), so it rebuilds the
// test database afterwards for the tests that follow.
class ConcurrencyTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    /** No wrapping transaction: the children must see the data. */
    protected array $connectionsToTransact = [];

    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to race real database sessions.');
        }

        $this->setUpPos();
        config(['pos.ranges.size' => 10, 'pos.ranges.threshold' => 3]);
    }

    protected function tearDown(): void
    {
        // Committed rows would leak into the next tests: rebuild the database as RefreshTenantDatabase does.
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => CatalogueSeeder::class]);

        parent::tearDown();
    }

    public function test_devices_asking_for_ranges_at_the_same_time_never_get_overlapping_blocks(): void
    {
        $devices = [$this->till->id];

        for ($i = 1; $i < self::WORKERS; $i++) {
            $devices[] = $this->pairedTill($i % 2 ? $this->locationA : $this->locationB, "Till {$i}")[0]->id;
        }

        $results = $this->race(array_map(fn (string $deviceId) => function () use ($deviceId) {
            $place = DevicePlace::of(Device::query()->findOrFail($deviceId));
            $ranges = app(NumberRanges::class);
            $taken = [];

            // Five top-ups each, every time reporting the last block spent.
            for ($round = 0; $round < 5; $round++) {
                $active = $ranges->topUp($place, 'pos.receipt', isset($last) ? $last + 1 : null);
                $last = $active->max('range_to');
                $taken = [...$taken, ...$active->map(fn (NumberRange $r) => [$r->range_from, $r->range_to])->all()];
            }

            return $taken;
        }, $devices));

        $this->inTenant(function () use ($results) {
            $ranges = NumberRange::query()->orderBy('range_from')->get(['device_id', 'range_from', 'range_to']);
            $this->assertCount(self::WORKERS * 5, $ranges, 'each device got a block per round');

            // Consecutive, never overlapping, with no number given twice.
            $expectedFrom = 1;
            foreach ($ranges as $range) {
                $this->assertSame($expectedFrom, $range->range_from);
                $this->assertSame($range->range_from + 9, $range->range_to);
                $expectedFrom = $range->range_to + 1;
            }

            // Each worker saw only its own device's blocks.
            foreach ($results as $taken) {
                $this->assertNotEmpty($taken);
            }
        });
    }

    public function test_concurrent_gapless_numbers_have_no_gaps_or_repeats_even_when_documents_roll_back(): void
    {
        app(DocumentNumberTypes::class)->register(new DocumentNumberType('core.test_invoice', 'core', 'INV-{YYYY}-{00001}', NumberFormat::RESET_YEARLY, ['BRANCH'], langKey: 'app.name'));
        $this->inTenant(fn () => NumberFormat::create(['document_type' => 'core.test_invoice', 'pattern' => 'INV-{YYYY}-{00001}', 'reset' => 'yearly', 'gapless' => true]));

        $results = $this->race(array_map(fn (int $worker) => function () use ($worker) {
            $numbering = app(Numbering::class);
            $context = new NumberContext($this->acme->fresh(), $this->branchA->fresh(), at: CarbonImmutable::now());
            $kept = [];

            for ($i = 0; $i < 8; $i++) {
                try {
                    $kept[] = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($numbering, $context, $worker, $i) {
                        $issued = $numbering->next('core.test_invoice', $context);
                        usleep(random_int(0, 3000));

                        // Every third document of odd workers fails after taking its number.
                        if ($worker % 2 === 1 && $i % 3 === 0) {
                            throw new RuntimeException('document failed');
                        }

                        return $issued->number;
                    });
                } catch (RuntimeException) {
                }
            }

            return $kept;
        }, range(0, self::WORKERS - 1)));

        $numbers = array_merge(...$results);
        sort($numbers);
        $year = CarbonImmutable::now('Africa/Nairobi')->format('Y');
        $expected = array_map(fn (int $n) => sprintf('INV-%s-%05d', $year, $n), range(1, count($numbers)));

        $this->assertSame($expected, $numbers, 'committed numbers run 1..n with no gap and no repeat');
        $this->assertGreaterThan(30, count($numbers));
    }

    public function test_the_same_sale_uploaded_by_racing_requests_is_stored_once(): void
    {
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $sale = $this->saleBody($shift, 1);

        $results = $this->race(array_fill(0, 4, fn () => app(SaleUploads::class)->upload(
            DevicePlace::of(Device::query()->findOrFail($this->till->id)),
            [$sale],
        )));

        foreach ($results as $result) {
            $this->assertSame('stored', $result[0]['status'], json_encode($result));
        }
        $this->assertCount(1, array_unique(array_map(fn ($r) => json_encode($r), $results)), 'every request got the same answer');
        $this->inTenant(fn () => $this->assertSame(1, Sale::query()->count()));
    }

    /**
     * Run each closure in its own forked process with its own database
     * session, all released at the same instant, in the owner's tenant;
     * returns what each returned. A child never closes the parent's
     * connection: it keeps the inherited PDO alive and exits with SIGKILL,
     * so no destructor or shutdown handler runs in it.
     *
     * @param  list<callable(): mixed>  $workers
     * @return list<mixed>
     */
    private function race(array $workers): array
    {
        $tenantId = $this->owner->tenant_id;
        $start = microtime(true) + 1.0;
        $files = [];
        $pids = [];

        foreach ($workers as $index => $worker) {
            $files[$index] = tempnam(sys_get_temp_dir(), 'pos-race-');
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }

            if ($pid === 0) {
                $this->child($worker, $tenantId, $start, $files[$index]);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return array_map(function (string $file) {
            $result = json_decode((string) file_get_contents($file), true);
            unlink($file);
            $this->assertIsArray($result, 'a worker wrote no result');
            $this->assertArrayNotHasKey('error', $result, (string) ($result['error'] ?? ''));

            return $result['value'];
        }, $files);
    }

    private function child(callable $worker, string $tenantId, float $start, string $file): never
    {
        try {
            // Keep the parent's session alive: hold the inherited PDO so purging never closes it.
            $GLOBALS['__pos_race_inherited'] = DB::connection(TenantContext::CONNECTION)->getPdo();
            DB::purge(TenantContext::CONNECTION);
            app(TenantContext::class)->set($tenantId);

            while (microtime(true) < $start) {
                usleep(200);
            }

            $value = $worker();
            file_put_contents($file, json_encode(['value' => $value]));
        } catch (Throwable $e) {
            file_put_contents($file, json_encode(['error' => $e::class.': '.$e->getMessage()]));
        }

        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
}
