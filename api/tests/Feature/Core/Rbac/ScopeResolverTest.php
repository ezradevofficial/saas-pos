<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-04: scoped assignments with downward inheritance
// (tenant > company > branch > location).
class ScopeResolverTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private User $user;

    private Company $companyOne;

    private Company $companyTwo;

    private Branch $branchA;

    private Branch $branchB;

    private Branch $branchD;

    private Location $locationA;

    private Location $locationA2;

    private Location $locationB;

    private Location $locationD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
        app(TenantContext::class)->set($this->user->tenant_id);

        $this->companyOne = $this->company('One');
        $this->branchA = $this->branch($this->companyOne, 'A');
        $this->branchB = $this->branch($this->companyOne, 'B');
        $this->locationA = $this->location($this->branchA, 'A1');
        $this->locationA2 = $this->location($this->branchA, 'A2');
        $this->locationB = $this->location($this->branchB, 'B1');

        $this->companyTwo = $this->company('Two');
        $this->branchD = $this->branch($this->companyTwo, 'D');
        $this->locationD = $this->location($this->branchD, 'D1');
    }

    private function resolver(): ScopeResolver
    {
        return app(ScopeResolver::class);
    }

    private function branchManager(): Role
    {
        return $this->role('Branch Manager', ['core.location.view', 'core.branch.view']);
    }

    public function test_a_branch_role_covers_the_branch_and_its_locations_only(): void
    {
        $this->assign($this->user, $this->branchManager(), Scope::branch($this->branchA->id));

        $this->assertTrue($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationA->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationA2->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchA->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.location.view'));

        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationB->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchB->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::company($this->companyOne->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::tenant()));
        // A permission the role lacks.
        $this->assertFalse($this->resolver()->can($this->user, 'core.location.edit', Scope::location($this->locationA->id)));
    }

    public function test_a_location_role_covers_that_location_only(): void
    {
        $this->assign($this->user, $this->branchManager(), Scope::location($this->locationA->id));

        $this->assertTrue($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationA->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationA2->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchA->id)));
    }

    public function test_a_company_role_reaches_every_branch_of_that_company_and_none_of_another(): void
    {
        $this->assign($this->user, $this->role('Admin', ['core.branch.view', 'core.location.view']), Scope::company($this->companyOne->id));

        $this->assertTrue($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchA->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchB->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationB->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.branch.view', Scope::company($this->companyOne->id)));

        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::branch($this->branchD->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationD->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.branch.view', Scope::company($this->companyTwo->id)));
    }

    public function test_a_tenant_role_reaches_everything(): void
    {
        $this->assign($this->user, $this->role('Owner', ['core.location.view', 'core.company.view']), Scope::tenant());

        foreach ([$this->locationA, $this->locationB, $this->locationD] as $location) {
            $this->assertTrue($this->resolver()->can($this->user, 'core.location.view', Scope::location($location->id)));
        }
        $this->assertTrue($this->resolver()->can($this->user, 'core.company.view', Scope::company($this->companyTwo->id)));
        $this->assertTrue($this->resolver()->can($this->user, 'core.company.view', Scope::tenant()));
        $this->assertTrue($this->resolver()->can($this->user, 'core.company.view'));
    }

    public function test_an_archived_role_grants_nothing(): void
    {
        $role = $this->role('Owner', ['core.location.view']);
        $this->assign($this->user, $role, Scope::tenant());
        $role->archive();

        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view', Scope::location($this->locationA->id)));
        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view'));
    }

    public function test_a_deactivated_user_gets_nothing(): void
    {
        $this->assign($this->user, $this->role('Owner', ['core.location.view']), Scope::tenant());
        $this->user->forceFill(['status' => User::STATUS_DEACTIVATED])->save();

        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view'));
    }

    public function test_an_unknown_permission_or_target_is_refused(): void
    {
        $this->assign($this->user, $this->role('Owner', ['core.location.view']), Scope::tenant());

        $this->assertFalse($this->resolver()->can($this->user, 'core.nothing.view'));
        // An id that does not exist in this tenant is never covered, even by a tenant role.
        $this->assertFalse($this->resolver()->can($this->user, 'core.location.view', Scope::location('0190a0a0-0000-7000-8000-000000000000')));
    }

    public function test_visible_ids_for_a_branch_manager_are_exactly_the_branch_and_its_locations(): void
    {
        $this->assign($this->user, $this->branchManager(), Scope::branch($this->branchA->id));

        $visible = $this->resolver()->visibleIds($this->user, 'core.location.view');

        $this->assertFalse($visible->all);
        $this->assertSame([], $visible->companyIds);
        $this->assertSame([$this->branchA->id], $visible->branchIds);
        $this->assertEqualsCanonicalizing([$this->locationA->id, $this->locationA2->id], $visible->locationIds);

        $ids = $visible->applyTo(Location::query(), 'location')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$this->locationA->id, $this->locationA2->id], $ids);

        $this->assertSame([$this->branchA->id], $visible->applyTo(Branch::query(), 'branch')->pluck('id')->all());
        $this->assertSame([], $visible->applyTo(Company::query(), 'company')->pluck('id')->all());
    }

    public function test_visible_ids_expand_a_company_downwards_and_a_tenant_role_sees_all(): void
    {
        $this->assign($this->user, $this->role('Admin', ['core.location.view']), Scope::company($this->companyOne->id));

        $visible = $this->resolver()->visibleIds($this->user, 'core.location.view');
        $this->assertSame([$this->companyOne->id], $visible->companyIds);
        $this->assertEqualsCanonicalizing([$this->branchA->id, $this->branchB->id], $visible->branchIds);
        $this->assertEqualsCanonicalizing([$this->locationA->id, $this->locationA2->id, $this->locationB->id], $visible->locationIds);

        $this->assertSame([], $this->resolver()->visibleIds($this->user, 'core.company.edit')->locationIds);

        $owner = $this->colleague($this->user);
        $this->assign($owner, $this->role('Owner', ['core.location.view']), Scope::tenant());
        $all = $this->resolver()->visibleIds($owner, 'core.location.view');
        $this->assertTrue($all->all);
        $this->assertSame(4, $all->applyTo(Location::query(), 'location')->count());
    }

    public function test_the_gate_delegates_module_permissions_to_the_resolver_with_the_model_scope(): void
    {
        $this->assign($this->user, $this->branchManager(), Scope::branch($this->branchA->id));
        $deviceA = Device::create(['location_id' => $this->locationA->id, 'name' => 'Till 1']);
        $deviceB = Device::create(['location_id' => $this->locationB->id, 'name' => 'Till 2']);
        $this->role('Device', ['core.device.view']);
        $this->assign($this->user, Role::where('name', 'Device')->sole(), Scope::branch($this->branchA->id));

        $this->assertTrue($this->user->can('core.location.view', $this->locationA));
        $this->assertFalse($this->user->can('core.location.view', $this->locationB));
        $this->assertTrue($this->user->can('core.branch.view', $this->branchA));
        $this->assertFalse($this->user->can('core.branch.view', $this->companyOne));
        $this->assertTrue($this->user->can('core.device.view', $deviceA));
        $this->assertFalse($this->user->can('core.device.view', $deviceB));
        $this->assertTrue($this->user->can('core.location.view'));
        $this->assertTrue($this->user->can('core.location.view', Scope::location($this->locationA->id)));

        // Abilities that are not permission names are left to policies.
        $this->assertFalse($this->user->can('view-anything'));
    }
}
