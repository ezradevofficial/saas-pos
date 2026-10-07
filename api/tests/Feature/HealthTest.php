<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_responds(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_tests_run_against_postgresql(): void
    {
        // Tenant isolation relies on PostgreSQL row-level security (TEN-01),
        // so tests must never fall back to SQLite.
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('app_test', DB::connection()->getDatabaseName());
    }
}
