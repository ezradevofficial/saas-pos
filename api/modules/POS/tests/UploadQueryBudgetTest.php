<?php

namespace Modules\POS\Tests;

use App\Core\Rbac\ModuleRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Accounting\Chart\Charts;
use Modules\POS\Tests\Concerns\BuildsPos;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-04, stage 7B performance review: uploading sales with every module
// on (POS, Inventory, Loyalty, Accounting, Stores, Procurement, Manufacturing) costs a
// bounded number of queries per sale, and deciding which listeners to
// queue costs none per sale: module flags are memoised per request and
// tenant, and listeners already in the event's tenant do not switch to it
// again.
class UploadQueryBudgetTest extends TestCase
{
    use BuildsPos, RefreshTenantDatabase;

    /** Queries per extra sale in one upload, measured at 35 on 2026-10-14 with every module on, plus headroom. */
    private const PER_SALE = 40;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPos();
        $this->inTenant(function () {
            foreach (['inventory', 'loyalty', 'accounting', 'stores', 'procurement', 'manufacturing'] as $module) {
                app(ModuleRegistry::class)->activate($module);
            }

            app(Charts::class)->fromTemplate($this->acme);
        });
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
        // Only the upload is measured: the listeners' jobs are queued, not run.
        Queue::fake();
    }

    /** @return array{all: int, modules: int, tenant: int} the queries an upload of $count sales ran */
    private function upload(int $count, int $from): array
    {
        $sales = [];

        for ($seq = $from; $seq < $from + $count; $seq++) {
            $sales[] = $this->saleBody($this->shift, $seq);
        }

        // Each upload is a request of its own: nothing memoised from the last one.
        app(ModuleRegistry::class)->flush();
        $queries = ['all' => 0, 'modules' => 0, 'tenant' => 0];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries['all']++;
            $queries['modules'] += str_contains($query->sql, '"tenant_modules"') ? 1 : 0;
            $queries['tenant'] += str_contains($query->sql, 'app.tenant_id') ? 1 : 0;
        });

        $this->postJson('/api/v1/pos/sales', ['sales' => $sales], $this->tillHeaders())->assertOk()
            ->assertJsonCount($count, 'results')->assertJsonPath('results.0.status', 'stored');
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $queries;
    }

    public function test_an_upload_costs_a_bounded_number_of_queries_per_sale_with_every_module_on(): void
    {
        $one = $this->upload(1, 1);
        $six = $this->upload(6, 2);
        $perSale = intdiv($six['all'] - $one['all'], 5);

        $this->assertLessThanOrEqual(self::PER_SALE, $perSale, json_encode(['one' => $one, 'six' => $six]));
        // Which listeners to queue is decided from memoised module flags, in the tenant already set.
        $this->assertSame($one['modules'], $six['modules'], json_encode(['one' => $one, 'six' => $six]));
        $this->assertSame($one['tenant'], $six['tenant'], json_encode(['one' => $one, 'six' => $six]));
    }
}
