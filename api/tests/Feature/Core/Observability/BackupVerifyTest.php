<?php

namespace Tests\Feature\Core\Observability;

use App\Core\Observability\Backups\BackupComparison;
use App\Core\Observability\Console\BackupVerify;
use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-07: `backup:verify` passes a restore that matches its source and
 * names every difference otherwise: schema version, missing or extra
 * tables, extra rows, and rows lost beyond the tolerance.
 */
class BackupVerifyTest extends TestCase
{
    use RefreshTenantDatabase;

    private const SOURCE = ['migration' => '2026_12_01_000100_x', 'tables' => ['pos_sales' => 1000, 'audit_logs' => 500, 'tenants' => 0]];

    public function test_a_matching_restore_passes(): void
    {
        $this->assertSame([], BackupComparison::compare(self::SOURCE, self::SOURCE, 0.05));
    }

    public function test_a_restore_a_little_behind_its_source_passes(): void
    {
        $restored = ['migration' => self::SOURCE['migration'], 'tables' => ['pos_sales' => 960, 'audit_logs' => 499, 'tenants' => 0]];

        $this->assertSame([], BackupComparison::compare(self::SOURCE, $restored, 0.05));
    }

    public function test_every_difference_is_named(): void
    {
        $restored = ['migration' => '2026_11_28_000100_y', 'tables' => ['pos_sales' => 900, 'audit_logs' => 501, 'stray' => 1]];

        $problems = BackupComparison::compare(self::SOURCE, $restored, 0.05);

        $this->assertCount(5, $problems, implode("\n", $problems));
        $this->assertStringContainsString('schema version differs', $problems[0]);
        $this->assertContains('table tenants is missing from the restore', $problems);
        $this->assertContains('table stray exists only in the restore', $problems);
        $this->assertContains('table audit_logs has more rows in the restore (501) than the source (500)', $problems);
        $this->assertContains('table pos_sales has 900 rows in the restore, 1000 in the source (more than 5% fewer)', $problems);
    }

    public function test_the_snapshot_reads_the_schema_version_and_every_table_as_the_owner(): void
    {
        $snapshot = BackupVerify::snapshot(DB::connection(SyncPermissions::OWNER_CONNECTION));

        $this->assertSame(DB::table('migrations')->orderByDesc('id')->value('migration'), $snapshot['migration']);
        $this->assertArrayHasKey('tenants', $snapshot['tables']);
        $this->assertArrayHasKey('audit_logs', $snapshot['tables']);
        $this->assertArrayNotHasKey('cache', $snapshot['tables']);
    }

    public function test_the_command_passes_against_an_identical_copy(): void
    {
        $this->artisan('backup:verify', ['--target-connection' => SyncPermissions::OWNER_CONNECTION])
            ->expectsOutputToContain('The restore matches the source.')
            ->assertSuccessful();
    }

    public function test_the_command_needs_a_restore_that_is_not_the_source(): void
    {
        $this->artisan('backup:verify')->assertFailed();
        $this->artisan('backup:verify', ['--target-database' => DB::connection(SyncPermissions::OWNER_CONNECTION)->getDatabaseName()])
            ->expectsOutputToContain('same database')
            ->assertFailed();
    }
}
