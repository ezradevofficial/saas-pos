<?php

namespace Tests\Feature\Schema;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * TEN-01: a migration that moves tenant data enters each tenant on the
 * tenant connection. Run inside the migration's transaction on the owner
 * connection, the tenant connection waits on the owner's locks (an altered
 * table) while the owner sits idle in its transaction, and the database's
 * 60-second idle-in-transaction timeout ends the migration wherever tenants
 * already exist (found by the Phase 8 walkthrough on a copy of the dev
 * database). Such migrations opt out of the wrapping transaction.
 */
class TenantDataMigrationTest extends TestCase
{
    public function test_migrations_using_the_tenant_connection_run_outside_the_migration_transaction(): void
    {
        $api = dirname(__DIR__, 3);
        $finder = Finder::create()->files()->name('*.php')
            ->in([$api.'/database/migrations', ...glob($api.'/modules/*/database/migrations')]);

        $offenders = [];

        foreach ($finder as $file) {
            $source = $file->getContents();

            if (str_contains($source, 'TenantContext::CONNECTION')
                && ! preg_match('/public\s+\$withinTransaction\s*=\s*false\s*;/', $source)) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'These migrations use the tenant connection inside the migration transaction.');
    }
}
