<?php

namespace Tests\Feature\Core\Sync;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-04: no gaps or duplicates under concurrent writes. Transactions on
 * other connections commit in any order around a device's pulls; a row
 * written by a transaction that commits after a pull still reaches the
 * device. (A cursor on updated_at alone would skip it: the row's time is
 * older than rows already handed out.) Commits for real, so no wrapping
 * transaction, and the next test migrates afresh.
 */
class SyncConcurrencyTest extends TestCase
{
    use BuildsTill, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    private array $till;

    private string $uomId;

    /** @var list<string> */
    private array $writers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->till = $this->pairTill($this->locationA);
        $this->uomId = $this->uom()->id;
    }

    protected function tearDown(): void
    {
        foreach ($this->writers as $name) {
            DB::purge($name);
        }

        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_a_row_committed_after_a_later_one_still_reaches_a_device_that_pulled_in_between(): void
    {
        $writer = $this->writer('slow');
        $writer->beginTransaction();
        $slow = $this->insertItem($writer, 'SLOW');

        // A later transaction commits first.
        $fast = $this->makeItem('FAST')->id;

        $first = $this->pullAll($this->till, 'items');
        $this->assertSame([], $first['seen'], 'nothing past a running transaction is handed out');

        $writer->commit();
        $this->skipWhileHeldElsewhere();

        $second = $this->pullAll($this->till, 'items', $first['cursor']);
        $this->assertSame([$slow, $fast], $second['seen']);

        $third = $this->pullAll($this->till, 'items', $second['cursor']);
        $this->assertSame([], $third['seen']);
    }

    public function test_interleaved_commits_and_small_pages_end_with_every_row_exactly_once(): void
    {
        $connections = array_map(fn (int $n) => $this->writer("w{$n}"), range(1, 3));
        $expected = [];
        $seen = [];
        $cursor = null;
        mt_srand(4);

        for ($round = 0; $round < 6; $round++) {
            $open = [];

            foreach ($connections as $index => $connection) {
                $connection->beginTransaction();
                foreach (range(1, mt_rand(1, 3)) as $n) {
                    $expected[] = $this->insertItem($connection, "R{$round}C{$index}N{$n}");
                }
                $open[] = $connection;
            }

            // Commit in a random order, pulling small pages in between.
            shuffle($open);
            foreach ($open as $connection) {
                $page = $this->pull($this->till, ['items'], $cursor === null ? [] : ['items' => $cursor], 2)->assertOk()->json('entities.items');
                array_push($seen, ...array_column($page['upserts'], 'id'));
                $cursor = $page['cursor'];
                $connection->commit();
            }
        }

        $this->skipWhileHeldElsewhere();
        $rest = $this->pullAll($this->till, 'items', $cursor, 2);
        array_push($seen, ...$rest['seen']);

        $this->assertCount(count($expected), $seen, 'every row once, none twice');
        $this->assertEqualsCanonicalizing($expected, $seen);
    }

    /**
     * The horizon is cluster wide: a transaction of another backend (another
     * test run, another database) older than our writes holds them back, by
     * design. Wait a little for it, then skip rather than fail.
     */
    private function skipWhileHeldElsewhere(): void
    {
        $ours = (int) $this->asTenant($this->owner->tenant_id, fn () => DB::table('items')->max('sync_xid'));

        for ($try = 0; $try < 50; $try++) {
            $xmin = (int) DB::selectOne('select pg_snapshot_xmin(pg_current_snapshot())::text::bigint as x')->x;

            if ($xmin > $ours) {
                return;
            }

            usleep(100_000);
        }

        $holders = DB::select('select pid, datname, backend_xid::text as xid, state from pg_stat_activity where backend_xid is not null and pid <> pg_backend_pid()');
        $this->markTestSkipped(sprintf(
            'Another backend holds the transaction horizon below our writes (xmin %d <= %d): %s. Device sync waits for it by design (ADR 004); rerun when it ends.',
            $xmin, $ours, json_encode($holders),
        ));
    }

    private function writer(string $name): Connection
    {
        $connection = "pgsql_sync_{$name}";
        config(["database.connections.{$connection}" => config('database.connections.pgsql')]);
        $this->writers[] = $connection;

        $db = DB::connection($connection);
        $db->statement("select set_config('app.tenant_id', ?, false)", [$this->owner->tenant_id]);

        return $db;
    }

    private function insertItem(Connection $db, string $code): string
    {
        $id = (string) Str::uuid7();
        $db->table('items')->insert([
            'id' => $id,
            'tenant_id' => $this->owner->tenant_id,
            'code' => $code,
            'name' => "Item {$code}",
            'type' => 'stock',
            'base_uom_id' => $this->uomId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
