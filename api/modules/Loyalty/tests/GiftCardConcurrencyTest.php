<?php

namespace Modules\Loyalty\Tests;

use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\GiftCardEntry;
use Modules\Loyalty\Models\LoyaltySale;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Modules\POS\Models\NumberRange;
use Modules\POS\Sync\DevicePlace;
use Modules\POS\Sync\SaleUploads;
use RuntimeException;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;
use Throwable;

// LOY-22, NFR-04 under real concurrency: two tills spend the same gift
// card at the same instant (forked processes, each with its own database
// session, on committed data). Both sales are kept (device wins), both
// redemptions are posted under the card's row lock, the balance is exact
// (no lost update) and exactly one sale is flagged overdrawn.
//
// The test commits its data, so it rebuilds the test database afterwards.
class GiftCardConcurrencyTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    /** No wrapping transaction: the children must see the data. */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is needed to race real database sessions.');
        }

        $this->setUpLoyalty();
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--seed' => true, '--seeder' => CatalogueSeeder::class]);

        parent::tearDown();
    }

    public function test_two_tills_spending_one_card_at_once_never_lose_an_update(): void
    {
        [$card, $code] = $this->giftCard('150000');
        [$second, $secondToken] = $this->pairedTill($this->locationA, 'Till 2');
        $this->ranges()->assertOk();
        $this->ranges(token: $secondToken)->assertOk();
        $shifts = [$this->openShift(), $this->openShift($secondToken)];
        $patterns = ['R-L01-%06d', 'R-L01-%06d'];
        $first = $this->inTenant(fn () => NumberRange::query()->where('device_id', $this->till->id)->value('range_from'));
        $other = $this->inTenant(fn () => NumberRange::query()->where('device_id', $second->id)->value('range_from'));

        $sales = [
            $this->loyaltySale($shifts[0], (int) $first, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])], extra: ['receipt_number' => sprintf($patterns[0], $first)]),
            $this->loyaltySale($shifts[1], (int) $other, null, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])], extra: [
                'receipt_number' => sprintf($patterns[1], $other), 'actor_proof' => $this->actorProof($this->owner->id, $second),
            ]),
        ];
        $devices = [$this->till->id, $second->id];

        $results = $this->race(array_map(fn (int $i) => fn () => app(SaleUploads::class)->upload(
            DevicePlace::of(Device::query()->findOrFail($devices[$i])), [$sales[$i]],
        )[0]['status'], [0, 1]));

        $this->assertSame(['stored', 'stored'], $results);
        $this->inTenant(function () use ($card, $sales) {
            $this->assertSame('-75000', (string) GiftCard::query()->findOrFail($card->id)->balance_minor);
            $this->assertSame(2, GiftCardEntry::query()->where('gift_card_id', $card->id)->where('kind', 'redeem')->count());
            $this->assertSame('-75000', (string) GiftCardEntry::query()->where('gift_card_id', $card->id)->sum('amount_minor'));

            $flagged = LoyaltySale::query()->whereIn('id', array_column($sales, 'id'))->get()
                ->filter(fn (LoyaltySale $s) => in_array('gift_card_overdrawn', array_column($s->flags ?? [], 'code'), true));
            $this->assertCount(1, $flagged, 'exactly the later redemption is flagged');
        });
    }

    /** @param list<callable(): mixed> $workers @return list<mixed> */
    private function race(array $workers): array
    {
        $tenantId = $this->owner->tenant_id;
        $start = microtime(true) + 1.0;
        $files = [];
        $pids = [];

        foreach ($workers as $index => $worker) {
            $files[$index] = tempnam(sys_get_temp_dir(), 'loyalty-race-');
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
            $GLOBALS['__loyalty_race_inherited'] = DB::connection(TenantContext::CONNECTION)->getPdo();
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
