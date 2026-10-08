<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-02 custom roles, RBAC-12 audited changes, no privilege escalation.
class RoleApiTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function createRole(array $data, ?User $as = null)
    {
        return $this->postJson('/api/v1/roles', array_merge(['name' => 'Supervisor'], $data), $this->headersFor($as));
    }

    /** @return list<string> */
    private function permissionNames(User $user): array
    {
        return collect($this->getJson('/api/v1/me/permissions', $this->headersFor($user))->assertOk()->json('permissions'))
            ->pluck('name')->all();
    }

    public function test_a_custom_role_is_created_with_permissions_and_audited(): void
    {
        $id = $this->createRole([
            'description' => 'Floor lead',
            'permissions' => ['core.location.view', 'core.company.view'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Supervisor')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.permissions', ['core.company.view', 'core.location.view'])
            ->json('data.id');

        $this->getJson("/api/v1/roles/{$id}", $this->headersFor())->assertOk()->assertJsonPath('data.description', 'Floor lead');

        $this->inTenant(function () use ($id) {
            $this->assertSame(1, AuditEntry::where('action', 'rbac.role.create')->where('auditable_id', $id)->count());
            $entry = AuditEntry::where('action', 'rbac.role.permissions_update')->where('auditable_id', $id)->sole();
            $this->assertSame(['permissions' => []], $entry->before);
            $this->assertSame(['permissions' => ['core.company.view', 'core.location.view']], $entry->after);
        });
    }

    public function test_a_permission_edit_takes_effect_immediately_and_is_audited(): void
    {
        $id = $this->createRole(['permissions' => ['core.company.view']])->assertCreated()->json('data.id');
        $user = $this->inTenant(function () use ($id) {
            $user = $this->colleague($this->owner);
            $this->assign($user, Role::findOrFail($id), Scope::tenant());

            return $user;
        });

        $this->assertSame(['core.company.view'], $this->permissionNames($user));

        $this->patchJson("/api/v1/roles/{$id}", ['permissions' => ['core.branch.view']], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.permissions', ['core.branch.view']);

        $this->assertSame(['core.branch.view'], $this->permissionNames($user));

        $this->inTenant(function () use ($id) {
            $entry = AuditEntry::where('action', 'rbac.role.permissions_update')->where('auditable_id', $id)->orderByDesc('seq')->first();
            $this->assertSame(['permissions' => ['core.company.view']], $entry->before);
            $this->assertSame(['permissions' => ['core.branch.view']], $entry->after);
        });

        // No permission change, no permission entry.
        $this->patchJson("/api/v1/roles/{$id}", ['description' => 'x'], $this->headersFor())->assertOk();
        $this->inTenant(fn () => $this->assertSame(2, AuditEntry::where('action', 'rbac.role.permissions_update')->where('auditable_id', $id)->count()));
    }

    public function test_system_roles_cannot_be_edited_or_archived_but_can_be_copied(): void
    {
        $owner = $this->roles->get('owner');

        $this->patchJson("/api/v1/roles/{$owner->id}", ['name' => 'Boss'], $this->headersFor())
            ->assertForbidden()
            ->assertJsonPath('code', 'system_role');
        $this->postJson("/api/v1/roles/{$owner->id}/archive", [], $this->headersFor())
            ->assertForbidden()
            ->assertJsonPath('code', 'system_role');

        $copy = $this->postJson("/api/v1/roles/{$this->roles->get('cashier')->id}/copy", ['name' => 'Senior cashier'], $this->headersFor())
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.is_owner', false)
            ->json('data');

        $this->assertSame($this->inTenant(fn () => $this->roles->get('cashier')->permissions->pluck('name')->sort()->values()->all()), $copy['permissions']);
        $this->inTenant(fn () => $this->assertSame(
            ['permissions' => $copy['permissions']],
            AuditEntry::where('action', 'rbac.role.permissions_update')->where('auditable_id', $copy['id'])->sole()->after,
        ));
    }

    public function test_role_names_are_unique_among_active_roles_and_permissions_come_from_the_catalogue(): void
    {
        $this->createRole(['name' => 'Cashier'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->createRole(['permissions' => ['core.nothing.view']])->assertUnprocessable()->assertJsonValidationErrors(['permissions.0']);

        $id = $this->createRole([])->assertCreated()->json('data.id');
        $this->createRole([])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->postJson("/api/v1/roles/{$id}/archive", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.archived_at', fn ($at) => $at !== null);
        $this->createRole([])->assertCreated();
    }

    public function test_an_archived_role_stops_granting(): void
    {
        $id = $this->createRole(['permissions' => ['core.company.view']])->assertCreated()->json('data.id');
        $user = $this->inTenant(function () use ($id) {
            $user = $this->colleague($this->owner);
            $this->assign($user, Role::findOrFail($id), Scope::tenant());

            return $user;
        });

        $this->postJson("/api/v1/roles/{$id}/archive", [], $this->headersFor())->assertOk();

        $this->assertSame([], $this->permissionNames($user));
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'rbac.role.archive')->where('auditable_id', $id)->count()));
    }

    public function test_a_role_editor_cannot_add_permissions_they_do_not_hold(): void
    {
        $editor = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Role editor', ['core.role.view', 'core.role.create', 'core.role.edit']), Scope::tenant());

            return $user;
        });

        $this->createRole(['permissions' => ['core.company.create']], $editor)
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant');
        $id = $this->createRole(['permissions' => ['core.role.view']], $editor)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/roles/{$id}", ['permissions' => ['core.role.view', 'core.user.deactivate']], $this->headersFor($editor))
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant');
    }

    private function registerInactiveDemoModule(): void
    {
        app(ModuleRegistry::class)->register('demo');
        app(PermissionRegistry::class)->register('demo', ['thing' => ['view']]);
        $this->syncPermissionCatalogue();
    }

    public function test_permissions_of_an_inactive_module_cannot_be_handed_out_by_someone_without_them(): void
    {
        $this->registerInactiveDemoModule();
        // Built before any request: a request switches the default guard.
        $admin = $this->userWith('admin', Scope::tenant());
        $role = $this->inTenant(fn () => $this->role('Demo viewer', ['demo.thing.view']));
        $user = $this->userWith('cashier', Scope::location($this->locationA->id));

        $this->createRole(['permissions' => ['core.company.view', 'demo.thing.view']], $admin)
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant');

        // Nor granted: a role carrying it cannot be assigned by the Admin.
        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $role->id, 'scope_type' => 'tenant', 'scope_id' => $this->owner->tenant_id,
        ], $this->headersFor($admin))->assertForbidden()->assertJsonPath('code', 'cannot_grant');
    }

    public function test_editing_permissions_keeps_those_of_inactive_modules(): void
    {
        $this->registerInactiveDemoModule();
        $role = $this->inTenant(fn () => $this->role('Mixed', ['core.company.view', 'demo.thing.view']));

        $this->patchJson("/api/v1/roles/{$role->id}", ['permissions' => ['core.branch.view']], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.permissions', ['core.branch.view', 'demo.thing.view']);
    }

    public function test_a_branch_manager_lists_roles_but_cannot_manage_them(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->getJson('/api/v1/roles', $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount($this->roles->count(), 'data');
        $this->createRole([], $manager)->assertForbidden();
        $this->postJson("/api/v1/roles/{$this->roles->get('cashier')->id}/copy", ['name' => 'X'], $this->headersFor($manager))->assertForbidden();

        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->getJson('/api/v1/roles', $this->headersFor($cashier))->assertForbidden();
    }

    public function test_the_permission_catalogue_is_grouped_by_module_and_resource(): void
    {
        $this->getJson('/api/v1/permissions', $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.core.label', 'Core')
            ->assertJsonPath('data.core.resources.company.label', 'Companies')
            ->assertJsonPath('data.core.resources.company.actions.0', ['action' => 'archive', 'name' => 'core.company.archive', 'label' => 'Archive'])
            ->assertJsonPath('data.core.resources.access_review.actions.1.name', 'core.access_review.view');

        $this->getJson('/api/v1/permissions', $this->headersFor() + ['Accept-Language' => 'fr'])
            ->assertJsonPath('data.core.resources.company.label', 'Sociétés');
    }

    public function test_requiring_two_factor_limits_tokens_of_holders_without_it(): void
    {
        $id = $this->createRole(['permissions' => ['core.company.view']])->assertCreated()->json('data.id');
        $user = $this->inTenant(function () use ($id) {
            $user = $this->colleague($this->owner);
            $this->assign($user, Role::findOrFail($id), Scope::tenant());

            return $user;
        });
        $token = $this->tokenFor($user);
        $this->getJson('/api/v1/companies', $this->bearer($token))->assertOk();

        $this->patchJson("/api/v1/roles/{$id}", ['requires_two_factor' => true], $this->headersFor())->assertOk();

        $this->getJson('/api/v1/companies', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_enrollment_required');
    }

    public function test_assigning_a_role_that_requires_two_factor_limits_the_users_tokens(): void
    {
        $id = $this->createRole(['permissions' => ['core.company.view'], 'requires_two_factor' => true])->assertCreated()->json('data.id');
        $token = $this->tokenFor($user = $this->userWith('cashier', Scope::location($this->locationA->id)));
        $this->getJson('/api/v1/locations', $this->bearer($token))->assertOk();

        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $id, 'scope_type' => 'tenant', 'scope_id' => $this->owner->tenant_id,
        ], $this->headersFor())->assertCreated();

        $this->getJson('/api/v1/locations', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_enrollment_required');
    }

    public function test_assignments_are_listed_created_and_removed_within_scope(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $user = $this->userWith('cashier', Scope::location($this->locationA->id));
        $cashier = $this->roles->get('cashier')->id;

        $id = $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $cashier, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id,
        ], $this->headersFor($manager))
            ->assertCreated()
            ->assertJsonPath('data.scope.name', 'Branch A')
            ->assertJsonPath('data.role.id', $cashier)
            ->assertJsonPath('data.granted_by.id', $manager->id)
            ->json('data.id');

        // Duplicate.
        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $cashier, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id,
        ], $this->headersFor($manager))->assertUnprocessable()->assertJsonValidationErrors(['role_id']);

        // Out of scope (branch B) is not found; unknown scope ids too.
        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $cashier, 'scope_type' => 'branch', 'scope_id' => $this->branchB->id,
        ], $this->headersFor($manager))->assertNotFound();
        $this->postJson("/api/v1/users/{$user->id}/assignments", [
            'role_id' => $cashier, 'scope_type' => 'company', 'scope_id' => '01999999-0000-7000-8000-000000000000',
        ], $this->headersFor())->assertNotFound();

        $this->getJson("/api/v1/users/{$user->id}/assignments", $this->headersFor($manager))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->deleteJson("/api/v1/assignments/{$id}", [], $this->headersFor($manager))->assertNoContent();
        $this->inTenant(function () use ($id) {
            $this->assertFalse(RoleAssignment::whereKey($id)->exists());
            $this->assertSame(1, AuditEntry::where('action', 'rbac.assignment.create')->where('auditable_id', $id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'rbac.assignment.delete')->where('auditable_id', $id)->count());
        });

        // An owner's assignment is out of a branch manager's scope.
        $ownerAssignment = $this->inTenant(fn () => RoleAssignment::where('user_id', $this->owner->id)->value('id'));
        $this->deleteJson("/api/v1/assignments/{$ownerAssignment}", [], $this->headersFor($manager))->assertNotFound();
    }

    public function test_a_branch_manager_cannot_add_or_remove_an_assignment_above_their_branch(): void
    {
        // RBAC-04: seeing the user (through their branch A role) is not
        // enough; the actor must manage the assignment's own scope.
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $companyAdmin = $this->userWith('admin', Scope::company($this->acme->id));
        [$companyAssignment, $branchAssignment] = $this->inTenant(fn () => [
            RoleAssignment::where('user_id', $companyAdmin->id)->value('id'),
            $this->assign($companyAdmin, $this->roles->get('cashier'), Scope::location($this->locationA->id))->id,
        ]);

        $this->getJson("/api/v1/users/{$companyAdmin->id}/assignments", $this->headersFor($manager))->assertOk();

        $this->deleteJson("/api/v1/assignments/{$companyAssignment}", [], $this->headersFor($manager))
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant');
        $this->postJson("/api/v1/users/{$companyAdmin->id}/assignments", [
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'company', 'scope_id' => $this->acme->id,
        ], $this->headersFor($manager))->assertForbidden()->assertJsonPath('code', 'cannot_grant');

        $this->inTenant(fn () => $this->assertTrue(RoleAssignment::whereKey($companyAssignment)->exists()));

        // Within the branch, the same manager may remove it.
        $this->deleteJson("/api/v1/assignments/{$branchAssignment}", [], $this->headersFor($manager))->assertNoContent();
    }

    public function test_another_tenants_roles_and_assignments_are_not_found(): void
    {
        $other = $this->otherTenant();
        [$roleId, $assignmentId] = $this->asTenant($other['user']->tenant_id, fn () => [
            Role::where('template_key', 'cashier')->value('id'),
            RoleAssignment::where('user_id', $other['user']->id)->value('id'),
        ]);

        $this->getJson("/api/v1/roles/{$roleId}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/roles/{$roleId}", ['name' => 'X'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/roles/{$roleId}/copy", ['name' => 'X'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/roles/{$roleId}/archive", [], $this->headersFor())->assertNotFound();
        $this->deleteJson("/api/v1/assignments/{$assignmentId}", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/users/{$other['user']->id}/assignments", [
            'role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'tenant', 'scope_id' => $this->owner->tenant_id,
        ], $this->headersFor())->assertNotFound();
    }
}
