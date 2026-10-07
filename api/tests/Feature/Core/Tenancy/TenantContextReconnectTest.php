<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// TEN-01: a new or re-established connection carries the current tenant.
// No RefreshDatabase: purging the connection would drop the test transaction.
class TenantContextReconnectTest extends TestCase
{
    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);

        parent::tearDown();
    }

    public function test_reconnect_reapplies_the_tenant(): void
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->set($id);

        DB::reconnect('pgsql');

        $this->assertSame($id, $this->databaseTenant());
    }

    public function test_purged_connection_reapplies_the_tenant_when_recreated(): void
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->set($id);

        DB::purge('pgsql');

        $this->assertSame($id, $this->databaseTenant());
    }

    private function databaseTenant(): ?string
    {
        $value = DB::connection('pgsql')->selectOne("select current_setting('app.tenant_id', true) as id")->id;

        return $value === '' ? null : $value;
    }
}
