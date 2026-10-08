<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * RBAC-03: `permissions:sync` re-expands every tenant's system roles from
 * their templates when the catalogue grows. The sync lists tenants as the
 * schema owner, which cannot see uncommitted rows, so this test commits
 * (no wrapping transaction) and the next test migrates afresh.
 */
class SystemRoleRefreshTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        // Committed tenants and permissions: rebuild the database next time.
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function signUp(string $email, string $business): string
    {
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Owner',
            'email' => $email,
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => $business,
        ])->assertCreated()->json('challenge_id');
        $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $this->lastCode()])->assertOk();

        return VerificationChallenge::findOrFail($challengeId)->tenant_id;
    }

    public function test_sync_gives_new_permissions_to_system_roles_of_every_tenant_and_leaves_custom_roles_alone(): void
    {
        $tenantA = $this->signUp('a@example.com', 'Amani Stores');
        $tenantB = $this->signUp('b@example.com', 'Baraka Stores');

        [$custom, $owner] = $this->asTenant($tenantA, function () {
            $custom = $this->role('Custom viewer', ['core.company.view']);
            $owner = RoleAssignment::with('user')->whereHas('role', fn ($q) => $q->where('template_key', 'owner'))->sole()->user;
            // Warm this tenant's permission cache before the catalogue grows.
            $this->assertTrue(app(ScopeResolver::class)->can($owner, 'core.company.view'));
            $this->assertFalse(app(ScopeResolver::class)->can($owner, 'core.refresh_probe.view'));

            return [$custom, $owner];
        });

        app(PermissionRegistry::class)->register('core', ['refresh_probe' => ['view', 'edit']]);
        $this->assertSame(0, Artisan::call('permissions:sync'));
        $this->assertMatchesRegularExpression('/2 new; system roles refreshed in \d+ tenants/', Artisan::output());

        foreach ([$tenantA, $tenantB] as $tenantId) {
            $this->asTenant($tenantId, function () {
                $names = fn (string $key) => Role::where('template_key', $key)->sole()->permissionNames();

                $this->assertContains('core.refresh_probe.edit', $names('owner'));
                $this->assertContains('core.refresh_probe.edit', $names('admin'));
                $this->assertContains('core.refresh_probe.view', $names('read_only_auditor'));
                $this->assertNotContains('core.refresh_probe.edit', $names('read_only_auditor'));
                $this->assertNotContains('core.refresh_probe.view', $names('cashier'));

                // RBAC-12: audited, by the system (no user).
                $entry = AuditEntry::where('action', 'rbac.role.permissions_update')
                    ->where('auditable_id', Role::where('template_key', 'owner')->sole()->id)
                    ->sole();
                $this->assertNull($entry->user_id);
                $this->assertContains('core.refresh_probe.view', $entry->after['permissions']);
                $this->assertNotContains('core.refresh_probe.view', $entry->before['permissions']);
            });
        }

        $this->asTenant($tenantA, function () use ($custom, $owner) {
            $this->assertSame(['core.company.view'], $custom->fresh()->permissionNames());
            // The tenant's permission cache was flushed: the Owner has it now.
            $this->assertTrue(app(ScopeResolver::class)->can($owner, 'core.refresh_probe.view'));
        });

        // A second sync changes nothing and records nothing more.
        Artisan::call('permissions:sync');
        $this->asTenant($tenantA, fn () => $this->assertSame(
            1,
            AuditEntry::where('action', 'rbac.role.permissions_update')
                ->where('auditable_id', Role::where('template_key', 'owner')->sole()->id)
                ->count(),
        ));
    }

    /**
     * `composer migrate:fresh` seeds with `--database=pgsql_owner`, which
     * makes the owner (BYPASSRLS) the default connection while the seeder
     * runs `permissions:sync`. Each tenant's refresh must still run on the
     * runtime connection, under row-level security: it reads, changes and
     * audits only that tenant's roles.
     */
    public function test_the_seeder_refreshes_each_tenant_under_row_level_security_when_the_owner_is_the_default(): void
    {
        $tenantA = $this->signUp('a@example.com', 'Amani Stores');
        $tenantB = $this->signUp('b@example.com', 'Baraka Stores');

        $tenantQueriesOnOwner = [];
        DB::listen(function (QueryExecuted $query) use (&$tenantQueriesOnOwner) {
            if ($query->connectionName === 'pgsql_owner'
                && preg_match('/"(roles|role_has_permissions|audit_logs)"/', $query->sql)) {
                $tenantQueriesOnOwner[] = $query->sql;
            }
        });

        app(PermissionRegistry::class)->register('core', ['seed_probe' => ['view']]);
        $this->assertSame(0, Artisan::call('db:seed', [
            '--class' => 'PermissionCatalogueSeeder',
            '--database' => 'pgsql_owner',
            '--force' => true,
        ]));

        $this->assertSame([], $tenantQueriesOnOwner);
        $this->assertSame('pgsql', DB::getDefaultConnection());

        foreach ([$tenantA, $tenantB] as $tenantId) {
            $this->asTenant($tenantId, function () use ($tenantId) {
                $owner = Role::where('template_key', 'owner')->sole();
                $this->assertContains('core.seed_probe.view', $owner->permissionNames());

                // Audited once, by this tenant's own refresh.
                $entry = AuditEntry::where('action', 'rbac.role.permissions_update')
                    ->where('auditable_id', $owner->id)
                    ->sole();
                $this->assertSame($tenantId, $entry->tenant_id);
                $this->assertContains('core.seed_probe.view', $entry->after['permissions']);
            });
        }
    }

    public function test_the_sync_restores_the_default_connection_it_was_given(): void
    {
        $this->signUp('a@example.com', 'Amani Stores');

        DB::setDefaultConnection('pgsql_owner');

        try {
            $this->assertSame(0, Artisan::call('permissions:sync'));
            // The refresh switched to the runtime connection, then back.
            $this->assertSame('pgsql_owner', DB::getDefaultConnection());
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    }
}
