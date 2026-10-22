<?php

namespace Tests\Feature\Core\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-01, NFR-01 (Phase 8 review L9): migrations run on the owner
// connection with a lock timeout, so one never queues behind a running
// transaction and blocks every query on a table; the deploy runs them
// through migrate-with-retry.sh, which tries again only on a lock timeout.
class MigrationLockTimeoutTest extends TestCase
{
    use RefreshTenantDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/migrate-retry-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_the_owner_connection_sets_a_lock_timeout(): void
    {
        $this->assertSame('5s', DB::connection('pgsql_owner')->selectOne('show lock_timeout')->lock_timeout);
        // The runtime connection keeps its own settings (no lock timeout added there).
        $this->assertSame('0', DB::connection('pgsql')->selectOne('show lock_timeout')->lock_timeout);
    }

    public function test_a_migration_blocked_by_a_running_transaction_gives_up_with_a_lock_timeout(): void
    {
        config(['database.connections.pgsql_owner.server_options.lock_timeout' => '200ms']);
        DB::purge('pgsql_owner');

        // This test's own transaction (the runtime role) holds the table, as a busy worker would.
        DB::connection('pgsql')->statement('lock table items in access exclusive mode');

        try {
            DB::connection('pgsql_owner')->statement('alter table items add column lock_probe integer');
            $this->fail('The migration waited for the lock instead of giving up.');
        } catch (QueryException $e) {
            $this->assertSame('55P03', $e->getCode());
        } finally {
            DB::purge('pgsql_owner');
        }
    }

    /** A fake migrate command that fails $failures times with $message, then succeeds. */
    private function fake(int $failures, string $message): string
    {
        $script = $this->dir.'/fake-migrate.sh';
        file_put_contents($script, <<<BASH
            #!/usr/bin/env bash
            count=\$(cat "{$this->dir}/count" 2>/dev/null || echo 0)
            count=\$((count + 1))
            echo "\$count" > "{$this->dir}/count"
            if [ "\$count" -le {$failures} ]; then echo "{$message}"; exit 1; fi
            echo "Migrated."
            BASH);

        return "bash {$script}";
    }

    private function runScript(string $command): Process
    {
        $process = new Process(['bash', base_path('database/scripts/migrate-with-retry.sh')], null, ['MIGRATE_COMMAND' => $command, 'MIGRATE_RETRY_WAIT' => '0', 'MIGRATE_ATTEMPTS' => '3']);
        $process->run();

        return $process;
    }

    private function runs(): int
    {
        return (int) trim((string) file_get_contents($this->dir.'/count'));
    }

    public function test_the_deploy_retries_migrations_only_on_a_lock_timeout(): void
    {
        $lock = 'SQLSTATE[55P03]: Lock not available: 7 ERROR:  canceling statement due to lock timeout';

        $process = $this->runScript($this->fake(2, $lock));
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(3, $this->runs());

        unlink($this->dir.'/count');
        $this->assertSame(1, $this->runScript($this->fake(5, $lock))->getExitCode());
        $this->assertSame(3, $this->runs(), 'it stops after the last attempt');

        unlink($this->dir.'/count');
        $this->assertSame(1, $this->runScript($this->fake(1, 'SQLSTATE[42P07]: Duplicate table'))->getExitCode());
        $this->assertSame(1, $this->runs(), 'any other failure is not retried');
    }
}
