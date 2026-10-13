<?php

namespace Modules\Inventory\Tests;

use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventorySetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Stock\Posting;
use Modules\Inventory\Stock\PostingLine;
use Modules\Inventory\Stock\Qty;
use Modules\Inventory\Stock\StockLedger;
use Modules\Inventory\Tests\Concerns\BuildsInventory;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// INV-04, INV-12 under real concurrency: forked processes, each with its
// own database session, move the same balance at once. No update is lost,
// the company's cost stays equal to its layers, and one source posted by
// several sessions at once moves stock once.
//
// The test commits its data (no wrapping transaction), so it rebuilds the
// test database afterwards for the tests that follow.
class ConcurrencyTest extends TestCase
{
    use BuildsInventory, RefreshTenantDatabase;

    /** No wrapping transaction: the children must see the data. */
    protected array $connectionsToTransact = [];

    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to race real database sessions.');
        }

        $this->setUpInventory();
        $this->costing(InventorySetting::FIFO);
        $this->move($this->soap, Movement::RECEIPT, '100', 10000);
        $this->move($this->soap, Movement::RECEIPT, '100', 30000);
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => CatalogueSeeder::class]);

        parent::tearDown();
    }

    private function sale(string $source, string $qty): void
    {
        app(StockLedger::class)->post(new Posting($this->acme->id, 'pos.sale', $source, CarbonImmutable::now(), null, [
            new PostingLine($this->soap->id, $this->locationA->id, Movement::SALE, Qty::of($qty)->negated(), sourceLineId: $source),
        ], manual: false));
    }

    public function test_concurrent_moves_on_one_balance_lose_nothing_and_cost_matches_the_layers(): void
    {
        $this->race(array_map(fn () => function () {
            for ($i = 0; $i < 10; $i++) {
                $this->sale((string) Str::uuid7(), '3');
            }

            return true;
        }, range(1, self::WORKERS)));

        $this->assertSame('20', $this->onHand($this->soap));
        // 180 units out, FIFO: 100 at 100 and 80 at 300: 34,000 of 40,000; 20 left at 300.
        $this->assertSame(['qty' => '20', 'value' => '6000'], $this->costState($this->soap));
        $this->inTenant(function () {
            $this->assertSame(60, Movement::query()->where('kind', 'sale')->count());
            $this->assertSame('-34000', (string) Movement::query()->where('kind', 'sale')->sum('value_minor'));
            $this->assertSame('6000', (string) DB::table('inventory_cost_layers')->sum('remaining_cost_minor'));
        });
    }

    public function test_one_source_posted_by_several_sessions_at_once_moves_stock_once(): void
    {
        $source = (string) Str::uuid7();

        $this->race(array_map(fn () => function () use ($source) {
            $this->sale($source, '5');

            return true;
        }, range(1, self::WORKERS)));

        $this->assertSame('195', $this->onHand($this->soap));
        $this->assertSame(1, $this->inTenant(fn () => Movement::query()->where('source_id', $source)->count()));
    }

    /** @param list<callable(): mixed> $workers */
    private function race(array $workers): array
    {
        $tenantId = $this->owner->tenant_id;
        $start = microtime(true) + 1.0;
        $files = [];
        $pids = [];

        foreach ($workers as $index => $worker) {
            $files[$index] = tempnam(sys_get_temp_dir(), 'inventory-race-');
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
            $GLOBALS['__inventory_race_inherited'] = DB::connection(TenantContext::CONNECTION)->getPdo();
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
