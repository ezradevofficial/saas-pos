<?php

namespace Modules\Hotel\Tests;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Property;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\NightAudit\NightAudits;
use Modules\Hotel\Reservations\Reservations;
use Modules\Hotel\Stays\Stays;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// HOT-13, HOT-30 under real concurrency: forked processes, each with its own database session, race on committed data.
// The last room is sold once; a room goes to one guest; a night is audited once.
//
// The test commits its data (no wrapping transaction), so it rebuilds the test database afterwards for the tests that follow.
class ConcurrencyTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    /** No wrapping transaction: the children must see the data. */
    protected array $connectionsToTransact = [];

    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to race real database sessions.');
        }

        $this->setUpHotel();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => CatalogueSeeder::class]);

        parent::tearDown();
    }

    public function test_six_requests_for_the_last_room_sell_it_once(): void
    {
        $this->book(2);
        $this->book(2);
        $ownerId = $this->owner->id;
        $body = $this->bookingBody(2);

        $results = $this->race(array_fill(0, self::WORKERS, function () use ($ownerId, $body) {
            try {
                app(Reservations::class)->create($body, User::query()->findOrFail($ownerId));

                return 'booked';
            } catch (ApiException $e) {
                return $e->errorCode;
            }
        }));

        sort($results);
        $this->assertSame(['booked', ...array_fill(0, self::WORKERS - 1, 'not_available')], $results);
        $this->assertSame(3, $this->inTenant(fn () => Stay::query()->whereIn('status', Stay::HOLDING)->count()));
    }

    public function test_two_desks_cannot_give_one_room_to_two_guests(): void
    {
        $a = $this->book(2)['stays'][0]['id'];
        $b = $this->book(2)['stays'][0]['id'];
        $roomId = $this->rooms['101']->id;
        $ownerId = $this->owner->id;

        $results = $this->race([
            fn () => $this->try(fn () => app(Stays::class)->assignRoom(Stay::query()->findOrFail($a), $roomId, User::query()->findOrFail($ownerId))),
            fn () => $this->try(fn () => app(Stays::class)->assignRoom(Stay::query()->findOrFail($b), $roomId, User::query()->findOrFail($ownerId))),
        ]);

        sort($results);
        $this->assertSame(['ok', 'room_taken'], $results);
    }

    public function test_the_same_night_audited_by_racing_runs_posts_once(): void
    {
        $stay = $this->checkedIn(3, '101');
        $branch = $this->branchA->id;
        $date = $this->day(0);

        $results = $this->race(array_fill(0, 4, fn () => app(NightAudits::class)->run($branch, null, $date)['created'] ? 'created' : 'repeat'));

        sort($results);
        $this->assertSame(['created', 'repeat', 'repeat', 'repeat'], $results);
        $this->assertSame(1, $this->inTenant(fn () => FolioLine::query()->where('kind', 'room')->count()));
        $this->assertSame($this->day(1), $this->inTenant(fn () => Property::query()->where('branch_id', $branch)->first()->business_date->toDateString()));
        $this->assertNotNull($stay);
    }

    private function try(callable $do): string
    {
        try {
            $do();

            return 'ok';
        } catch (ApiException $e) {
            return $e->errorCode;
        }
    }

    /**
     * Run each closure in its own forked process with its own database session, started together, and return what each answered.
     *
     * @param  list<callable>  $workers
     * @return list<mixed>
     */
    private function race(array $workers): array
    {
        $tenantId = $this->owner->tenant_id;
        $start = microtime(true) + 1.0;
        $files = [];
        $pids = [];

        foreach ($workers as $index => $worker) {
            $files[$index] = tempnam(sys_get_temp_dir(), 'hotel-race-');
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
            $GLOBALS['__hotel_race_inherited'] = DB::connection(TenantContext::CONNECTION)->getPdo();
            DB::purge(TenantContext::CONNECTION);
            app(TenantContext::class)->set($tenantId);

            while (microtime(true) < $start) {
                usleep(200);
            }

            file_put_contents($file, json_encode(['value' => $worker()]));
        } catch (Throwable $e) {
            file_put_contents($file, json_encode(['error' => $e::class.': '.$e->getMessage()]));
        }

        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
}
