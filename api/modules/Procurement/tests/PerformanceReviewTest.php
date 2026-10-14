<?php

namespace Modules\Procurement\Tests;

use App\Core\Rbac\Scope;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Tests\Concerns\BuildsProcurement;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Stage 7B performance review of Procurement (and Stores' indexes): the
// landed cost detail checks scope once per location, committee candidates
// come from one join of role assignments, the tender list counts bids in
// its query, and every foreign key column of the two modules is indexed
// (tenant_id first), document lists by company, status and date.
class PerformanceReviewTest extends TestCase
{
    use BuildsProcurement, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpProcurement();
    }

    private function queries(string $uri): int
    {
        // Warm the per-request caches first: only what grows with the rows is compared.
        $this->api('GET', $uri)->assertOk();
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count) {
            $count++;
        });
        $this->api('GET', $uri)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    public function test_the_landed_cost_detail_checks_scope_once_per_location(): void
    {
        $lines = fn (int $n) => array_map(fn (int $i) => ['item_id' => $this->soap->id, 'qty' => '1', 'unit_price_minor' => (string) (1000 + $i)], range(1, $n));
        $one = $this->postedReceipt($this->approvedOrder(lines: $lines(1)));
        $many = $this->postedReceipt($this->approvedOrder(lines: $lines(6)));
        $landed = fn (array $receipt, string $ref) => $this->api('POST', 'procurement/landed-costs', ['branch_id' => $this->branchA->id, 'cost_date' => '2026-10-15', 'method' => 'value',
            'charges' => [['description' => 'Freight', 'party_id' => $this->otherSupplier->id, 'bill_reference' => $ref, 'currency' => 'KES', 'amount_minor' => '6000']],
            'receipt_ids' => [$receipt['id']]])->assertCreated()->json('data');

        $small = $this->queries('procurement/landed-costs/'.$landed($one, 'A')['id']);
        $large = $this->queries('procurement/landed-costs/'.$landed($many, 'B')['id']);

        // Six lines at one location cost no more scope checks than one line (the plan's own reads aside).
        $this->assertLessThanOrEqual($small + 12, $large, "one line: {$small}, six lines: {$large}");
        $this->assertCount(6, $this->api('GET', 'procurement/landed-costs/'.$landed($many, 'C')['id'])->json('data.allocation.lines'));
    }

    public function test_committee_candidates_cost_the_same_queries_for_any_number_of_users(): void
    {
        $uri = "procurement/branches/{$this->branchA->id}/committee-candidates";
        $before = $this->queries($uri);

        foreach (range(1, 8) as $i) {
            $this->userWith($i % 2 ? 'branch_manager' : 'cashier', Scope::branch($i % 3 ? $this->branchA->id : $this->branchB->id));
        }

        $after = $this->queries($uri);
        $this->assertSame($before, $after);

        // Who is offered: holders at branch A or above, with their roles.
        $candidates = collect($this->api('GET', $uri)->json('data'))->keyBy('id');
        $this->assertSame(['opener', 'evaluator'], $candidates[$this->manager->id]['roles']);
        $this->assertArrayHasKey($this->owner->id, $candidates->all());
        $this->assertSame(['opener', 'evaluator'], $candidates[$this->buyer->id]['roles']);
    }

    public function test_the_tender_list_counts_bids_in_its_query(): void
    {
        $tender = fn () => $this->api('POST', 'procurement/tenders', ['branch_id' => $this->branchA->id, 'title' => 'Chairs', 'kind' => 'invited', 'deadline_at' => '2026-10-20T12:00:00Z',
            'opening_at' => '2026-10-20T12:00:00Z', 'technical_weight' => 0, 'lines' => [['description' => 'Chair', 'qty' => '2']], 'openers' => [$this->owner->id, $this->manager->id]])->assertCreated();

        $tender();
        $one = $this->queries('procurement/tenders');
        $tender();
        $tender();
        $this->assertSame($one, $this->queries('procurement/tenders'));
    }

    public function test_every_foreign_key_column_of_procurement_and_stores_is_indexed(): void
    {
        $missing = DB::connection('pgsql_owner')->select("
            with fk as (
                select c.conrelid::regclass::text as tbl, a.attname as col, c.confrelid::regclass::text as ref
                from pg_constraint c join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[array_upper(c.conkey, 1)]
                where c.contype = 'f' and c.conrelid::regclass::text ~ '^(procurement_|stores_)' and a.attname <> 'tenant_id'
            )
            select distinct fk.tbl, fk.col from fk
            where fk.ref <> 'currencies' and not exists (
                select 1 from pg_index i
                join pg_attribute a1 on a1.attrelid = i.indrelid and a1.attnum = i.indkey[0]
                left join pg_attribute a2 on a2.attrelid = i.indrelid and a2.attnum = i.indkey[1]
                where i.indrelid = fk.tbl::regclass and (a1.attname = fk.col or (a1.attname = 'tenant_id' and a2.attname = fk.col))
            )
            order by 1, 2
        ");
        $this->assertSame([], array_map(fn ($row) => "{$row->tbl}.{$row->col}", $missing));

        $indexes = collect(DB::connection('pgsql_owner')->select("select tablename, indexdef from pg_indexes where schemaname = 'public' and tablename ~ '^(procurement_|stores_|acc_source_queue)'"));
        $has = fn (string $table, string $columns) => $indexes->contains(fn ($i) => $i->tablename === $table && str_contains($i->indexdef, "({$columns})"));

        foreach (['procurement_orders', 'procurement_receipts', 'procurement_invoices', 'procurement_landed_costs', 'stores_requisitions', 'stores_issues'] as $table) {
            $this->assertTrue($has($table, 'tenant_id, company_id, status, created_at'), "{$table} is listed by company, status and date");
        }

        $this->assertTrue($has('stores_movements', 'tenant_id, location_id, ledger, seq'));
        $this->assertTrue($has('stores_movements', 'tenant_id, location_id, item_id, ledger, seq'));
        $this->assertTrue($indexes->contains(fn ($i) => $i->tablename === 'acc_source_queue' && str_contains($i->indexdef, '(tenant_id, company_id, local_date) WHERE (summarised_at IS NULL)')));
        $this->assertTrue($has('acc_source_queue', 'company_id, summary_source_id'));
    }
}
