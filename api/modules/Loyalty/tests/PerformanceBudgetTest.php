<?php

namespace Modules\Loyalty\Tests;

use App\Core\MasterData\Items\ItemCategory;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Loyalty\GiftCards\GiftCards;
use Modules\Loyalty\Promotions\Promotions;
use Modules\Loyalty\Tests\Concerns\BuildsLoyalty;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-04, NFR-05: what a till's upload and pull cost in queries with the
 * POS, Loyalty, Inventory and Accounting modules all on. Queued work
 * (stock moves, loyalty postings, fiscal) is faked, so only the request
 * itself is counted. Budgets are the measured cost plus about 10 %.
 */
class PerformanceBudgetTest extends TestCase
{
    use BuildsLoyalty, RefreshTenantDatabase;

    /** Measured 2026-10-14: one sale with a promotion check, a gift card tender and a customer. */
    private const UPLOAD_BUDGET = 68; // measured 62

    /** Measured 2026-10-14: a full pull of every entity, Inventory's stock and Loyalty's four included. */
    private const PULL_BUDGET = 88; // measured 80

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpLoyalty();
        $this->inTenant(function () {
            foreach (['inventory', 'accounting'] as $module) {
                app(ModuleRegistry::class)->activate($module);
            }
        });
        $this->ranges()->assertOk();
    }

    /** @return array{0: int, 1: TestResponse} */
    private function counted(\Closure $request): array
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });

        try {
            $response = $request();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return [$count, $response];
    }

    public function test_one_sale_upload_stays_within_its_query_budget(): void
    {
        $shift = $this->openShift();
        [, $code] = $this->giftCard('200000');
        $sale = $this->loyaltySale($shift, 1, $this->amina->id, [$this->pay('voucher', '112500', ['code_hash' => GiftCards::proof($code)])]);

        Queue::fake();
        [$queries, $response] = $this->counted(fn () => $this->upload([$sale]));

        $response->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertLessThanOrEqual(self::UPLOAD_BUDGET, $queries, "an upload of one sale took {$queries} queries");
    }

    public function test_promotion_checks_read_the_categories_once_per_request(): void
    {
        $lines = [['id' => 'l-1', 'item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => '56250']];
        $categoryReads = 0;
        DB::listen(function (QueryExecuted $query) use (&$categoryReads) {
            $categoryReads += str_contains($query->sql, 'from "item_categories"') ? 1 : 0;
        });

        $this->inTenant(function () use ($lines) {
            $promotions = app(Promotions::class);

            foreach (range(1, 3) as $ignored) {
                $promotions->evaluateSale($this->acme->id, $this->branchA->id, null, 'KES', CarbonImmutable::now(), $lines, []);
            }
        });
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertSame(1, $categoryReads);
    }

    public function test_a_full_pull_with_inventory_and_loyalty_stays_within_its_query_budget(): void
    {
        $this->giftCard('200000');
        $this->creditAccount();
        $this->inTenant(fn () => ItemCategory::create(['name' => 'Soaps']));

        [$queries, $response] = $this->counted(fn () => $this->getJson('/api/v1/sync/pull', $this->tillHeaders()));

        $response->assertOk();
        foreach (['inventory_stock', 'loyalty_settings', 'loyalty_promotions', 'loyalty_members', 'loyalty_gift_cards'] as $entity) {
            $this->assertArrayHasKey($entity, $response->json('entities'), $entity);
        }
        $this->assertLessThanOrEqual(self::PULL_BUDGET, $queries, "a full pull took {$queries} queries");
    }
}
