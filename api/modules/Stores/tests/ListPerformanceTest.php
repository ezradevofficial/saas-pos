<?php

namespace Modules\Stores\Tests;

use App\Core\MasterData\Dimensions\Department;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Stores\Tests\Concerns\BuildsStores;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// STR-05, STR-12 (stage 7B performance review): the issue and return lists
// read their values in the list query, the consumption report its
// departments once per page, whatever the number of rows; a list export
// never bounds the test process's time (EXP-01).
class ListPerformanceTest extends TestCase
{
    use BuildsStores, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpStores(inventory: true);
        $this->postJson('/api/v1/inventory/opening-stock', ['item_id' => $this->soap->id, 'location_id' => $this->store->id, 'qty' => '100', 'unit_cost' => '1'], $this->headersFor())->assertCreated();
    }

    /** An issue of $qty soaps to $department, then a return of one. */
    private function issueAndReturn(string $qty, ?Department $department = null): void
    {
        $requisition = $this->approved([['item_id' => $this->soap->id, 'qty' => $qty]], $department);
        $issue = $this->issue($requisition['id'], [[$requisition['lines'][0]['id'], $qty]])->assertCreated()->json('data');
        $this->postJson("/api/v1/stores/issues/{$issue['id']}/returns", ['reason' => 'excess', 'lines' => [['issue_line_id' => $issue['lines'][0]['id'], 'qty' => '1']]],
            $this->headersFor($this->storekeeper))->assertCreated();
    }

    private function queries(string $uri): int
    {
        // Warm the per-request caches (permissions, settings) first: only the rows' cost is compared.
        $this->getJson($uri, $this->headersFor())->assertOk();
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });
        $this->getJson($uri, $this->headersFor())->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    public function test_lists_cost_the_same_queries_for_one_row_or_many(): void
    {
        $this->issueAndReturn('3', $this->kitchen);
        $one = array_map(fn (string $uri) => $this->queries($uri), ['/api/v1/stores/issues', '/api/v1/stores/returns', '/api/v1/stores/consumption?group=department']);

        $laundry = $this->inTenant(fn () => Department::create(['company_id' => $this->acme->id, 'code' => 'LDY', 'name' => 'Laundry', 'owner_user_id' => $this->head->id]));
        $this->issueAndReturn('4', $laundry);
        $this->issueAndReturn('5', $this->kitchen);
        $three = array_map(fn (string $uri) => $this->queries($uri), ['/api/v1/stores/issues', '/api/v1/stores/returns', '/api/v1/stores/consumption?group=department']);

        // Three times the rows, no more queries (a cache refreshed within the request may add one, never one per row).
        foreach ($one as $index => $queries) {
            $this->assertLessThanOrEqual($queries + 1, $three[$index], json_encode(['one' => $one, 'three' => $three]));
        }
        // The values are still there.
        $this->getJson('/api/v1/stores/issues', $this->headersFor())->assertJsonPath('data.0.value', ['amount_minor' => '500', 'currency' => 'KES']);
        $this->getJson('/api/v1/stores/returns', $this->headersFor())->assertJsonPath('data.0.value', ['amount_minor' => '100', 'currency' => 'KES']);
        $names = array_column(array_column($this->getJson('/api/v1/stores/consumption?group=department', $this->headersFor())->json('data'), 'group'), 'name');
        sort($names);
        $this->assertSame(['Kitchen', 'Laundry'], $names);
    }

    public function test_a_list_export_leaves_the_test_process_without_a_time_limit(): void
    {
        $this->issueAndReturn('2');
        $this->get('/api/v1/stores/issues?format=csv', $this->headersFor())->assertOk()->streamedContent();

        $this->assertSame('0', ini_get('max_execution_time'));
    }
}
