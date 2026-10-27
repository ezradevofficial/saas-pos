<?php

namespace Modules\Salon\Tests;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Salon\Appointments\Bookings;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\Redemption;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Payments\Credits;
use Modules\Salon\Support\Errors;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// SAL-08, SAL-17, SAL-18 under real concurrency: forked processes, each with its own database session, race on committed data.
// One staff member's time is booked once; the last package session and a deposit are used once.
//
// The test commits its data (no wrapping transaction), so it rebuilds the test database afterwards for the tests that follow.
class ConcurrencyTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    /** No wrapping transaction: the children must see the data. */
    protected array $connectionsToTransact = [];

    private const WORKERS = 6;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to race real database sessions.');
        }

        $this->setUpSalon();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => CatalogueSeeder::class]);

        parent::tearDown();
    }

    public function test_six_requests_for_the_same_time_of_one_stylist_book_it_once(): void
    {
        $deskId = $this->desk->id;
        $body = $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00');

        $results = $this->race(array_fill(0, self::WORKERS, fn () => $this->attempt(fn () => app(Bookings::class)->create($body, User::query()->findOrFail($deskId)))));

        sort($results);
        $this->assertSame(['booked', ...array_fill(0, self::WORKERS - 1, 'slot_taken')], $results);
        $this->assertSame(1, $this->inTenant(fn () => Appointment::query()->count()));
    }

    public function test_overlapping_requests_never_leave_two_bookings_on_top_of_each_other(): void
    {
        $deskId = $this->desk->id;
        $workers = [];

        // Quarter-hour starts from 10:00 to 11:15 for a 30-minute haircut by the same stylist, two of each.
        foreach (['10:00', '10:15', '10:30', '10:45', '11:00', '11:15'] as $time) {
            $body = $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], $time);
            $workers[] = fn () => $this->attempt(fn () => app(Bookings::class)->create($body, User::query()->findOrFail($deskId)));
            $workers[] = fn () => $this->attempt(fn () => app(Bookings::class)->create($body, User::query()->findOrFail($deskId)));
        }

        $results = $this->race($workers);

        $this->assertContains('booked', $results);
        $this->assertEqualsCanonicalizing(['booked', 'slot_taken'], array_values(array_unique($results)));
        $lines = $this->inTenant(fn () => Line::query()->where('holds', true)->orderBy('starts_at')->get());
        $this->assertSame($lines->count(), count(array_filter($results, fn ($r) => $r === 'booked')));

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $this->assertTrue($lines[$index - 1]->blocked_until->lessThanOrEqualTo($line->starts_at), 'two bookings overlap');
            }
        }
    }

    public function test_two_sales_cannot_use_the_last_session_of_a_package(): void
    {
        $package = $this->inTenant(fn () => Package::create([
            'company_id' => $this->acme->id, 'type_id' => $this->fiveCuts->id, 'customer_id' => $this->client->id, 'name' => 'One left', 'sale_id' => $this->id(), 'sale_line_id' => $this->id(),
            'sessions_total' => 1, 'price_minor' => 90000, 'remaining_minor' => 90000, 'currency' => 'KES', 'service_ids' => [],
        ]));
        $companyId = $this->acme->id;
        $methodId = $this->creditMethodId();
        $workers = [];

        foreach (range(1, 2) as $n) {
            $sale = $this->creditSale($package->id, $methodId);
            $workers[] = fn () => $this->attempt(function () use ($companyId, $sale) {
                Errors::transaction(fn () => app(Credits::class)->apply($companyId, $sale));
            });
        }

        $this->race($workers);

        $this->assertSame([1, 0, 'exhausted'], $this->inTenant(fn () => [$package->refresh()->sessions_used, $package->remaining_minor, $package->status]));
        $this->assertSame(1, $this->inTenant(fn () => Redemption::query()->count()));
        $this->assertSame(['package_exhausted'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
    }

    public function test_two_sales_cannot_spend_the_same_deposit_twice(): void
    {
        $made = $this->book('10:00');
        $this->inTenant(fn () => Payment::create(['appointment_id' => $made['id'], 'kind' => 'deposit', 'status' => 'settled', 'method_type' => 'cash', 'currency' => 'KES', 'amount_minor' => 50000]));
        $companyId = $this->acme->id;
        $methodId = $this->creditMethodId();
        $workers = [];

        foreach (range(1, 3) as $n) {
            $sale = $this->creditSale(null, $methodId, ['source' => 'deposit', 'appointment_id' => $made['id']], '50000');
            $workers[] = fn () => $this->attempt(function () use ($companyId, $sale) {
                Errors::transaction(fn () => app(Credits::class)->apply($companyId, $sale));
            });
        }

        $this->race($workers);

        $this->assertSame(50000, (int) $this->inTenant(fn () => Payment::query()->where('kind', 'applied')->sum('amount_minor')), 'the deposit is spent once, not three times');
        $this->assertSame(2, $this->inTenant(fn () => SalonException::query()->where('kind', 'deposit_insufficient')->count()));
    }

    /** @return array<string, mixed> a sale snapshot with one `salon_credit` payment */
    private function creditSale(?string $packageId, string $methodId, ?array $tender = null, string $amount = '90000'): array
    {
        return [
            'id' => (string) Str::uuid7(), 'branch_id' => $this->branchA->id, 'currency' => 'KES', 'customer_id' => $this->client->id, 'sold_at' => now()->toIso8601String(), 'extensions' => [],
            'payments' => [['id' => (string) Str::uuid7(), 'payment_method_id' => $methodId, 'method_type' => 'salon_credit', 'amount_in_sale_minor' => $amount,
                'tender' => $tender ?? ['source' => 'package', 'package_id' => $packageId, 'service_id' => $this->haircut->id]]],
        ];
    }

    private function attempt(callable $do): string
    {
        try {
            $do();

            return 'booked';
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
            $files[$index] = tempnam(sys_get_temp_dir(), 'salon-race-');
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
            $GLOBALS['__salon_race_inherited'] = DB::connection(TenantContext::CONNECTION)->getPdo();
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
