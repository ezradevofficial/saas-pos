<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Rbac\AccessVersion;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// NFR-02, RBAC-08, RBAC-12: a user's assignments, the tenant's modules and the
// permission list are cached per tenant, user and permissions version; any
// role, assignment or module change is seen by the very next request.
class AccessCacheTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrganisation();
    }

    private function names(array $headers): array
    {
        return collect($this->getJson('/api/v1/me/permissions', $headers)->assertOk()->json('permissions'))->pluck('name')->all();
    }

    public function test_a_repeat_request_reads_the_cache_not_the_assignments(): void
    {
        $headers = $this->headersFor();
        $this->getJson('/api/v1/me/permissions', $headers)->assertOk();

        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql) {
            $sql[] = $q->sql;
        });
        $this->getJson('/api/v1/me/permissions', $headers)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertEmpty(array_filter($sql, fn (string $s) => str_contains($s, 'role_assignments') && ! str_contains($s, 'distinct')), 'assignments were read again');
        $this->assertEmpty(array_filter($sql, fn (string $s) => str_contains($s, 'tenant_modules')), 'modules were read again');
    }

    public function test_a_new_assignment_is_seen_by_the_next_request(): void
    {
        $user = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $headers = $this->headersFor($user);
        $before = $this->names($headers);
        $this->assertNotContains('core.user.view', $before);

        $this->inTenant(fn () => $this->assign($user, $this->roles->get('admin'), Scope::tenant()));

        $this->assertContains('core.user.view', $this->names($headers));
    }

    public function test_a_removed_assignment_and_a_changed_role_are_seen_straight_away(): void
    {
        $user = $this->userWith('admin', Scope::tenant());
        $headers = $this->headersFor($user);
        $this->assertContains('core.user.view', $this->names($headers));

        $role = $this->inTenant(function () use ($user) {
            $role = $this->role('Viewer', ['core.company.view', 'core.user.view']);
            $this->assign($user, $role, Scope::tenant());

            return $role;
        });
        $this->assertContains('core.user.view', $this->names($headers));

        $this->inTenant(fn () => $role->setPermissions(['core.company.view']));
        $this->assertContains('core.user.view', $this->names($headers), 'the admin role still carries it');

        $this->inTenant(fn () => $user->assignments()->get()->each->delete());
        $this->assertSame([], $this->names($headers));
    }

    public function test_a_module_switched_on_or_off_is_seen_straight_away(): void
    {
        $headers = $this->headersFor();
        $this->assertSame(['core'], $this->getJson('/api/v1/me/permissions', $headers)->json('modules'));

        $this->inTenant(function () {
            app(ModuleRegistry::class)->register('demo');
            app(ModuleRegistry::class)->activate('demo');
        });
        $this->assertContains('demo', $this->getJson('/api/v1/me/permissions', $headers)->json('modules'));

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('demo'));
        $this->assertNotContains('demo', $this->getJson('/api/v1/me/permissions', $headers)->json('modules'));
    }

    public function test_an_entry_of_one_tenant_is_never_used_for_another(): void
    {
        $other = $this->otherTenant();
        $access = app(AccessVersion::class);

        $keyA = $this->inTenant(fn () => $access->key('assignments', $this->owner->id));
        $keyB = $this->asTenant($other['user']->tenant_id, fn () => $access->key('assignments', $other['user']->id));

        $this->assertStringContainsString($this->owner->tenant_id, $keyA);
        $this->assertStringContainsString($other['user']->tenant_id, $keyB);
        $this->assertNotSame($keyA, $keyB);

        // Tenant A's cached view does not change B's, nor does a change in A move B's version.
        $versionB = $this->asTenant($other['user']->tenant_id, fn () => $access->current());
        $this->inTenant(fn () => $access->bump());
        $this->assertSame($versionB, $this->asTenant($other['user']->tenant_id, fn () => $access->current()));

        $this->assertNotEmpty($this->names($this->headersFor()));
        $this->assertNotEmpty($this->names($this->bearer($this->tokenFor($other['user']))));
        $this->assertFalse($this->asTenant($other['user']->tenant_id, fn () => app(ScopeResolver::class)->can($this->owner, 'core.company.view')), 'a user of another tenant is not eligible');
    }
}
