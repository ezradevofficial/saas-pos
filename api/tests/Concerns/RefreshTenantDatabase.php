<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshTenantDatabase
{
    use RefreshDatabase;

    // Migrations run as the schema owner; tests run as the RLS-bound app role.
    protected function migrateFreshUsing(): array
    {
        return ['--database' => 'pgsql_owner', '--seed' => false];
    }
}
