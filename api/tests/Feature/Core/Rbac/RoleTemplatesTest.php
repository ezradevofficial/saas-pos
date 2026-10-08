<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Rbac\Models\Permission;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\OwnerGuard;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-01 catalogue, RBAC-02 tenant roles, RBAC-03 system templates,
// RBAC-09 permissions for the UI, RBAC-12 audit of role changes.
class RoleTemplatesTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private const TEMPLATE_KEYS = [
        'owner', 'admin', 'branch_manager', 'cashier', 'waiter', 'storekeeper', 'accountant',
        'hr_officer', 'payroll_officer', 'procurement_officer', 'approver', 'employee_self_service',
        'read_only_auditor',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function signUp(string $locale = 'en'): TestResponse
    {
        return $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amina Otieno',
            'email' => 'amina@example.com',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => $locale,
            'business_name' => 'Amina Stores',
        ])->assertCreated();
    }

    private function enter(string $challengeId): string
    {
        $tenantId = VerificationChallenge::findOrFail($challengeId)->tenant_id;
        app(TenantContext::class)->set($tenantId);

        return $tenantId;
    }

    public function test_the_core_catalogue_is_synced(): void
    {
        $names = Permission::pluck('name')->all();

        foreach (['company', 'branch', 'location', 'device'] as $resource) {
            foreach (['view', 'create', 'edit', 'archive'] as $action) {
                $this->assertContains("core.{$resource}.{$action}", $names);
            }
        }

        foreach ([
            'core.device.pair', 'core.user.view', 'core.user.invite', 'core.user.edit', 'core.user.deactivate',
            'core.role.view', 'core.role.create', 'core.role.edit', 'core.role.archive', 'core.role.assign',
            'core.audit.view', 'core.audit.export', 'core.settings.edit', 'core.access_review.view',
            'core.access_review.export', 'core.currency.view', 'core.currency.edit',
        ] as $name) {
            $this->assertContains($name, $names);
        }

        $permission = Permission::where('name', 'core.access_review.export')->sole();
        $this->assertSame(['core', 'access_review', 'export'], [$permission->module, $permission->resource, $permission->action]);
        $this->assertSame(33, count($names));
    }

    public function test_sign_up_provisions_thirteen_system_roles_and_an_owner_assignment(): void
    {
        $tenantId = $this->enter($this->signUp()->json('challenge_id'));

        $roles = Role::orderBy('template_key')->get();
        $this->assertCount(13, $roles);
        $this->assertEqualsCanonicalizing(self::TEMPLATE_KEYS, $roles->pluck('template_key')->all());
        $this->assertTrue($roles->every(fn (Role $r) => $r->is_system && $r->tenant_id === $tenantId));

        $owner = Role::where('template_key', 'owner')->sole();
        $this->assertSame('Owner', $owner->name);
        $this->assertTrue($owner->is_owner);
        $this->assertSame(Permission::count(), $owner->permissions()->count());

        $admin = Role::where('template_key', 'admin')->sole();
        $this->assertSame(Permission::where('module', 'core')->count(), $admin->permissions()->count());
        $this->assertFalse($admin->is_owner);

        $auditor = Role::where('template_key', 'read_only_auditor')->sole();
        $auditorPermissions = $auditor->permissions()->pluck('name')->all();
        $this->assertContains('core.company.view', $auditorPermissions);
        $this->assertContains('core.audit.export', $auditorPermissions);
        $this->assertContains('core.access_review.export', $auditorPermissions);
        $this->assertNotContains('core.company.edit', $auditorPermissions);

        $user = User::sole();
        $assignment = RoleAssignment::sole();
        $this->assertSame([$user->id, $owner->id, 'tenant', $tenantId], [
            $assignment->user_id, $assignment->role_id, $assignment->scope_type, $assignment->scope_id,
        ]);

        $this->assertSame(13, AuditEntry::where('action', 'rbac.role.create')->count());
        $this->assertSame(1, AuditEntry::where('action', 'rbac.assignment.create')->count());
    }

    public function test_system_role_names_use_the_tenant_locale(): void
    {
        $this->enter($this->signUp('fr')->json('challenge_id'));

        $this->assertSame(__('rbac.templates.owner', [], 'fr'), Role::where('template_key', 'owner')->sole()->name);
        $this->assertSame(__('rbac.templates.cashier', [], 'fr'), Role::where('template_key', 'cashier')->sole()->name);
        $this->assertNotSame('Cashier', Role::where('template_key', 'cashier')->sole()->name);
    }

    public function test_the_verified_owner_can_do_everything_and_me_permissions_lists_it(): void
    {
        $challengeId = $this->signUp()->json('challenge_id');
        $token = $this->postJson('/api/v1/auth/verify', ['challenge_id' => $challengeId, 'code' => $this->lastCode()])
            ->assertOk()->json('token');

        $tenantId = $this->enter($challengeId);
        $this->assertTrue(app(ScopeResolver::class)->can(User::sole(), 'core.settings.edit'));
        app(TenantContext::class)->set(null);

        $response = $this->getJson('/api/v1/me/permissions', $this->bearer($token))
            ->assertOk()
            ->assertJsonStructure(['permissions' => [['name', 'scopes' => [['type', 'id']]]], 'modules']);

        $this->assertSame(['core'], $response->json('modules'));
        $permissions = collect($response->json('permissions'))->keyBy('name');
        $this->assertCount(33, $permissions);
        $this->assertSame([['type' => 'tenant', 'id' => $tenantId]], $permissions['core.company.view']['scopes']);
    }

    public function test_me_permissions_lists_each_scope_a_permission_is_held_at(): void
    {
        $user = $this->createUser();
        [$branchA, $branchB] = $this->asTenant($user->tenant_id, function () use ($user) {
            $company = $this->company();
            $a = $this->branch($company, 'A');
            $b = $this->branch($company, 'B');
            $role = $this->role('Manager', ['core.location.view']);
            $this->assign($user, $role, Scope::branch($a->id));
            $this->assign($user, $role, Scope::branch($b->id));
            $this->assign($user, $this->role('Clerk', ['core.location.view', 'core.device.view']), Scope::branch($a->id));

            return [$a, $b];
        });

        $permissions = collect($this->getJson('/api/v1/me/permissions', $this->bearer($this->tokenFor($user)))
            ->assertOk()->json('permissions'))->keyBy('name');

        $this->assertSame(['core.device.view', 'core.location.view'], $permissions->keys()->sort()->values()->all());
        $this->assertEqualsCanonicalizing(
            [['type' => 'branch', 'id' => $branchA->id], ['type' => 'branch', 'id' => $branchB->id]],
            $permissions['core.location.view']['scopes'],
        );
        $this->assertSame([['type' => 'branch', 'id' => $branchA->id]], $permissions['core.device.view']['scopes']);
    }

    public function test_a_system_role_cannot_be_edited(): void
    {
        $this->enter($this->signUp()->json('challenge_id'));
        $cashier = Role::where('template_key', 'cashier')->sole();

        try {
            $cashier->assertEditable();
            $this->fail('A system role must not be editable.');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('system_role', $e->errorCode);
            $this->assertSame(__('rbac.errors.system_role'), $e->getMessage());
        }

        // The model refuses too, whatever the caller.
        $this->expectException(ApiException::class);
        $cashier->update(['name' => 'Till operator']);
    }

    public function test_copying_a_system_role_creates_an_editable_role_with_the_same_permissions(): void
    {
        $this->enter($this->signUp()->json('challenge_id'));
        $auditor = Role::where('template_key', 'read_only_auditor')->sole();

        $copy = app(RoleTemplates::class)->copy($auditor, 'External auditor');

        $this->assertFalse($copy->is_system);
        $this->assertFalse($copy->is_owner);
        $this->assertNull($copy->template_key);
        $this->assertSame('External auditor', $copy->name);
        $this->assertEqualsCanonicalizing(
            $auditor->permissions()->pluck('name')->all(),
            $copy->permissions()->pluck('name')->all(),
        );

        $copy->assertEditable();
        $copy->update(['description' => 'Year-end audit']);
        $copy->revokePermissionTo('core.audit.export');
        $copy->archive();

        $this->assertTrue($auditor->fresh()->permissions()->where('name', 'core.audit.export')->exists());
        $this->assertSame(1, AuditEntry::where('action', 'rbac.role.update')->where('auditable_id', $copy->id)->count());
        $this->assertSame(1, AuditEntry::where('action', 'rbac.role.archive')->where('auditable_id', $copy->id)->count());
    }

    public function test_removing_an_assignment_is_audited(): void
    {
        $user = $this->createUser();

        $this->asTenant($user->tenant_id, function () use ($user) {
            $assignment = $this->assign($user, $this->role('Clerk', ['core.location.view']), Scope::tenant());
            $assignment->delete();

            $entry = AuditEntry::where('action', 'rbac.assignment.delete')->sole();
            $this->assertSame($assignment->id, $entry->auditable_id);
            $this->assertSame($assignment->role_id, $entry->before['role_id']);
        });
    }

    public function test_the_owner_guard_refuses_to_lose_the_last_active_owner(): void
    {
        $owner = $this->createUser();

        $this->asTenant($owner->tenant_id, function () use ($owner) {
            $role = $this->role('Owner');
            $role->forceFill(['is_owner' => true])->save();
            $this->assign($owner, $role, Scope::tenant());

            $guard = app(OwnerGuard::class);

            try {
                $guard->assertNotLastOwner($owner);
                $this->fail('The last Owner must be kept.');
            } catch (ApiException $e) {
                $this->assertSame([422, 'last_owner'], [$e->getStatusCode(), $e->errorCode]);
            }

            // An Owner role held at a branch only does not make an Owner.
            $branchOwner = $this->colleague($owner);
            $this->assign($branchOwner, $role, Scope::branch($this->branch($this->company(), 'A')->id));
            $this->assertFalse($guard->isOwner($branchOwner));

            $second = $this->colleague($owner);
            $this->assign($second, $role, Scope::tenant());
            $guard->assertNotLastOwner($owner);
            $guard->assertNotLastOwner($branchOwner);

            $second->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
            $this->expectException(ApiException::class);
            $guard->assertNotLastOwner($owner);
        });
    }
}
