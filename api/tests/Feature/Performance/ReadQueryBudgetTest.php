<?php

namespace Tests\Feature\Performance;

use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-02: standard reads cost a fixed number of queries, whatever the
 * number of rows on the page (no N+1), within a budget. Timing in CI is
 * too noisy to gate p95 under 500 ms; query counts are the stable proxy,
 * and the k6 load test (docs/performance.md) measures latency before a
 * release.
 */
class ReadQueryBudgetTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    /** Queries per read, measured on 2026-10-20, plus headroom. */
    private const BUDGET = [
        '/api/v1/parties?per_page=50' => 25,
        '/api/v1/users?per_page=50' => 30,
        '/api/v1/privacy/requests' => 25,
        '/api/v1/me' => 20,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    private function seedRows(int $count): void
    {
        $this->inTenant(function () use ($count) {
            for ($n = 0; $n < $count; $n++) {
                $party = Party::query()->create(['company_id' => $this->acme->id, 'kind' => 'person', 'name' => "Customer {$n}", 'roles' => ['customer']]);
                $this->userWith('branch_manager', Scope::branch($this->branchA->id));
                DB::table('privacy_requests')->insert([
                    'id' => (string) str()->uuid7(), 'subject_type' => 'customer', 'subject_id' => $party->id, 'kind' => 'access', 'status' => 'received',
                    'received_at' => now(), 'due_at' => now()->addDays(7), 'requested_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function queries(string $path): int
    {
        $headers = $this->headersFor();
        // Warm once: per-process caches (permissions, module flags) are not what is measured.
        $this->getJson($path, $headers)->assertOk();
        $count = 0;
        // Sanctum writes a token's last_used_at only when the second has changed, so
        // whether a measured request pays that write depends on the runner's speed.
        DB::listen(function (QueryExecuted $query) use (&$count) {
            if (! str_starts_with($query->sql, 'update "personal_access_tokens"')) {
                $count++;
            }
        });
        $this->getJson($path, $headers)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    public function test_standard_reads_cost_the_same_queries_for_few_or_many_rows_within_budget(): void
    {
        $this->seedRows(3);
        $few = array_map(fn (string $path) => $this->queries($path), array_combine(array_keys(self::BUDGET), array_keys(self::BUDGET)));
        $this->seedRows(27);
        $many = array_map(fn (string $path) => $this->queries($path), array_combine(array_keys(self::BUDGET), array_keys(self::BUDGET)));

        foreach (self::BUDGET as $path => $budget) {
            $this->assertSame($few[$path], $many[$path], "{$path} grows with its rows (N+1): ".json_encode(['few' => $few[$path], 'many' => $many[$path]]));
            $this->assertLessThanOrEqual($budget, $many[$path], "{$path} runs {$many[$path]} queries, over its budget of {$budget}");
        }
    }
}
