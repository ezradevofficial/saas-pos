<?php

namespace Tests\Feature\Core\Sync;

use App\Core\Identity\Pin\Pins;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use App\Core\Sync\DeviceSecrets;
use App\Core\Tenancy\Models\Device;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-05 sanity (50,000 devices): a pull costs a fixed number of queries
 * whatever the number of rows, staff or devices, so load grows with pulls,
 * not with catalogue size. 50 devices pull the whole catalogue.
 */
class SyncPerformanceTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    private const DEVICES = 50;

    public function test_a_full_pull_costs_the_same_number_of_queries_for_any_number_of_rows_and_devices(): void
    {
        $this->registerTillModule();
        $this->setUpOrganisation();
        $this->activateTill($this->owner->tenant_id);
        $taxes = $this->taxes($this->acme);

        $tills = $this->inTenant(fn () => collect(range(1, self::DEVICES))->map(function (int $n) {
            $device = Device::create(['location_id' => $this->locationA->id, 'name' => "Till {$n}"]);
            $device->forceFill(['status' => Device::STATUS_ACTIVE, 'paired_at' => now()])->save();

            return ['id' => $device->id, 'token' => $device->issueToken("Till {$n}", null, null)->plainTextToken, 'secret' => app(DeviceSecrets::class)->issueFirst($device)['secret']];
        })->all());

        foreach (range(1, 3) as $n) {
            $user = $this->userWith('cashier', Scope::location($this->locationA->id));
            $this->inTenant(fn () => app(Pins::class)->set($user, '48'.$n.'6', null));
        }

        $this->rows(10, $taxes['category']->id);
        $small = $this->queriesFor($tills[0]);

        $this->rows(90, $taxes['category']->id);
        $large = $this->queriesFor($tills[1]);

        $this->assertSame($small['queries'], $large['queries'], 'queries grow with rows');
        $this->assertSame(100, $large['items']);
        $this->assertLessThanOrEqual(60, $large['queries']);

        $counts = [];
        foreach ($tills as $till) {
            $counts[] = $this->queriesFor($till)['queries'];
        }

        // Every device costs the same, give or take a token's first-use write.
        $this->assertLessThanOrEqual(1, max($counts) - min($counts), json_encode($counts));
        $this->assertLessThanOrEqual(60, max($counts));
    }

    /** N items with a unit and a barcode each, and N customers. */
    private function rows(int $count, string $taxCategoryId): void
    {
        static $made = 0;
        $box = $this->uom('BOX');

        foreach (range(1, $count) as $ignored) {
            $made++;
            $item = $this->makeItem("PERF{$made}", null, $taxCategoryId);
            $this->inTenant(function () use ($item, $box, $made) {
                ItemUom::create(['item_id' => $item->id, 'uom_id' => $box->id, 'factor' => '6']);
                ItemBarcode::create(['item_id' => $item->id, 'barcode' => sprintf('61610%08d', $made)]);
                Party::create(['kind' => 'person', 'name' => "Customer {$made}", 'roles' => ['customer']]);
            });
        }
    }

    /** @return array{queries: int, items: int} */
    private function queriesFor(array $till): array
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });

        $response = $this->pull($till, [])->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return ['queries' => $count, 'items' => count($response->json('entities.items.upserts'))];
    }
}
