<?php

namespace App\Core\Observability\Console;

use App\Core\Observability\Backups\BackupComparison;
use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * NFR-07: the monthly restore test's check (docs/backups.md). Compares a
 * restored copy of the database with the source: the schema version (the
 * last migration), the set of tables, and every table's row count (the
 * restore may hold fewer rows than the source, which kept writing, within
 * `--tolerance`; never more). Exits 1 when anything differs.
 *
 * Both sides are read as the schema owner (row-level security does not
 * hide rows from it, so counts cover every tenant), each in one
 * repeatable-read, read-only transaction. Only counts and names are
 * printed, never rows. The target is the source connection's settings with
 * the restored database's name, or BACKUP_VERIFY_HOST / _PORT / _USERNAME
 * / _PASSWORD / _DATABASE when the restore lives on another cluster.
 */
class BackupVerify extends Command
{
    protected $signature = 'backup:verify
        {--target-database= : The restored database (or BACKUP_VERIFY_DATABASE)}
        {--source='.SyncPermissions::OWNER_CONNECTION.' : The source connection}
        {--target-connection= : An already configured connection for the restore, instead of --target-database}
        {--tolerance=0.05 : The largest fraction of rows a table may lack in the restore}';

    protected $description = 'Compare a restored database with the source: schema version, tables and row counts';

    /** Framework tables that change constantly and are not business data. */
    public const VOLATILE = ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'personal_access_tokens', 'verification_challenges'];

    public function handle(): int
    {
        $source = DB::connection((string) $this->option('source'));
        $target = $this->target();

        if ($target === null) {
            $this->components->error('Name the restored database: --target-database=<name> (or BACKUP_VERIFY_DATABASE).');

            return self::FAILURE;
        }

        if ($target->getDatabaseName() === $source->getDatabaseName() && $target->getConfig('host') === $source->getConfig('host') && $this->option('target-connection') === null) {
            $this->components->error('The restore and the source are the same database.');

            return self::FAILURE;
        }

        $sourceSnapshot = self::snapshot($source);
        $targetSnapshot = self::snapshot($target);
        $problems = BackupComparison::compare($sourceSnapshot, $targetSnapshot, (float) $this->option('tolerance'));

        $this->components->twoColumnDetail('Schema version (source)', (string) $sourceSnapshot['migration']);
        $this->components->twoColumnDetail('Schema version (restore)', (string) $targetSnapshot['migration']);
        $this->components->twoColumnDetail('Tables compared', (string) count($sourceSnapshot['tables']));
        $this->components->twoColumnDetail('Rows (source / restore)', array_sum($sourceSnapshot['tables']).' / '.array_sum($targetSnapshot['tables']));

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        $this->components->info('The restore matches the source.');

        return self::SUCCESS;
    }

    private function target(): ?ConnectionInterface
    {
        if (($name = $this->option('target-connection')) !== null) {
            return DB::connection((string) $name);
        }

        $database = $this->option('target-database') ?: env('BACKUP_VERIFY_DATABASE');
        if (! $database) {
            return null;
        }

        $base = config('database.connections.'.$this->option('source'));
        config(['database.connections.backup_verify_target' => array_merge($base, array_filter([
            'host' => env('BACKUP_VERIFY_HOST'),
            'port' => env('BACKUP_VERIFY_PORT'),
            'username' => env('BACKUP_VERIFY_USERNAME'),
            'password' => env('BACKUP_VERIFY_PASSWORD'),
        ]), ['database' => $database, 'url' => null])]);
        DB::purge('backup_verify_target');

        return DB::connection('backup_verify_target');
    }

    /** @return array{migration: ?string, tables: array<string, int>} */
    public static function snapshot(ConnectionInterface $connection): array
    {
        $read = function () use ($connection) {
            $migration = $connection->selectOne('select migration from migrations order by id desc limit 1')?->migration;
            $names = array_map(fn ($row) => $row->table_name, $connection->select(
                "select table_name from information_schema.tables where table_schema = 'public' and table_type = 'BASE TABLE' order by table_name"
            ));

            $tables = [];
            foreach (array_diff($names, self::VOLATILE) as $name) {
                $tables[$name] = (int) $connection->selectOne('select count(*) as n from '.$connection->getQueryGrammar()->wrapTable($name))->n;
            }

            return ['migration' => $migration, 'tables' => $tables];
        };

        if ($connection->transactionLevel() > 0) {
            return $read();
        }

        $connection->beginTransaction();
        try {
            $connection->statement('set transaction isolation level repeatable read read only');

            return $read();
        } finally {
            $connection->rollBack();
        }
    }
}
