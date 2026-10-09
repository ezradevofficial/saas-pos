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
            'core.tax.view', 'core.tax.edit', 'core.price_list.view', 'core.price_list.edit', 'core.price.view', 'core.price.edit',
            'core.party.view', 'core.party.create', 'core.party.edit', 'core.party.archive',
            'core.master_data_settings.edit',
            'core.item.view', 'core.item.create', 'core.item.edit', 'core.item.archive',
            'core.item_category.view', 'core.item_category.create', 'core.item_category.edit', 'core.item_category.archive',
            'core.uom.view', 'core.uom.edit',
            'core.payment_method.view', 'core.payment_method.create', 'core.payment_method.edit', 'core.payment_method.archive',
            'core.payment_method.configure',
            'core.dimension.view', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.archive',
            'core.workflow.view', 'core.workflow.edit', 'core.workflow.publish',
            'core.notification_template.view', 'core.notification_template.edit', 'core.notification_settings.edit',
            'core.notification_delivery.view',
            'core.automation.view', 'core.automation.edit',
            'core.approval.view_all', 'core.approval.reassign',
            'core.credit_limit.request', 'core.credit_limit.approve', 'core.credit_limit.set_directly',
            'core.payment.view', 'core.payment.match', 'core.fiscal.view', 'core.fiscal.edit', 'core.fiscal.configure',
            'core.numbering.view', 'core.numbering.edit',
            // LAY-06: versioned configuration.
            'core.config.view', 'core.config.edit', 'core.config.publish',
        ] as $name) {
            $this->assertContains($name, $names);
        }

        $permission = Permission::where('name', 'core.access_review.export')->sole();
        $this->assertSame(['core', 'access_review', 'export'], [$permission->module, $permission->resource, $permission->action]);
        // The POS module's catalogue is registered whether or not a tenant has it (RBAC-08 gates it per tenant).
        foreach (['pos.sale.view', 'pos.sale.create', 'pos.sale.print', 'pos.sale.void', 'pos.sale.refund', 'pos.sale.review', 'pos.shift.view', 'pos.till.sign_in', 'pos.shift.open',
            'pos.shift.close', 'pos.shift.manage', 'pos.cash.move', 'pos.price.override', 'pos.discount.give'] as $name) {
            $this->assertContains($name, $names);
        }
        // BR-02, BR-05: core.theme.view|edit|publish and core.domain.manage; LAY: core.layout.view|edit|publish;
        // LAY-05: pos.layout.view|edit|publish.
        $this->assertSame(98 + 17, count($names));
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
        // Core, plus the POS layout designer (LAY-05), which applies once the module is active (RBAC-08).
        $this->assertSame(Permission::where('module', 'core')->count() + 3, $admin->permissions()->count());
        $this->assertSame(['pos.layout.edit', 'pos.layout.publish', 'pos.layout.view'], $admin->permissions()->where('module', 'pos')->orderBy('name')->pluck('name')->all());
        $this->assertFalse($admin->is_owner);

        $auditor = Role::where('template_key', 'read_only_auditor')->sole();
        $auditorPermissions = $auditor->permissions()->pluck('name')->all();
        $this->assertContains('core.company.view', $auditorPermissions);
        $this->assertContains('core.audit.export', $auditorPermissions);
        $this->assertContains('core.access_review.export', $auditorPermissions);
        $this->assertNotContains('core.company.edit', $auditorPermissions);
        // NOT-03, NOT-06: the auditor reads templates and the delivery log; only Owner and Admin change them.
        $this->assertContains('core.notification_delivery.view', $auditorPermissions);
        $this->assertNotContains('core.notification_template.edit', $auditorPermissions);
        $this->assertNotContains('core.notification_settings.edit', $auditorPermissions);
        $this->assertContains('core.notification_settings.edit', $admin->permissions()->pluck('name')->all());

        $user = User::sole();
        $assignment = RoleAssignment::sole();
        $this->assertSame([$user->id, $owner->id, 'tenant', $tenantId], [
            $assignment->user_id, $assignment->role_id, $assignment->scope_type, $assignment->scope_id,
        ]);

        $this->assertSame(13, AuditEntry::where('action', 'rbac.role.create')->count());
        $this->assertSame(1, AuditEntry::where('action', 'rbac.assignment.create')->count());
    }

    public function test_templates_grant_party_permissions_by_job(): void
    {
        // MD-01, TEN-08: tills create customers; buyers and accountants edit
        // parties; the auditor only reads; only Owner and Admin archive or
        // switch sharing modes.
        $this->enter($this->signUp()->json('challenge_id'));
        $party = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where(fn ($q) => $q->where('name', 'like', 'core.party.%')->orWhere('name', 'core.master_data_settings.edit'))
            ->orderBy('name')->pluck('name')->all();

        $all = ['core.master_data_settings.edit', 'core.party.archive', 'core.party.create', 'core.party.edit', 'core.party.view'];
        $this->assertSame($all, $party('owner'));
        $this->assertSame($all, $party('admin'));

        foreach (['branch_manager', 'cashier', 'waiter'] as $key) {
            $this->assertSame(['core.party.create', 'core.party.view'], $party($key), $key);
        }

        foreach (['accountant', 'procurement_officer'] as $key) {
            $this->assertSame(['core.party.create', 'core.party.edit', 'core.party.view'], $party($key), $key);
        }

        $this->assertSame(['core.party.view'], $party('read_only_auditor'));
        $this->assertSame([], $party('storekeeper'));
    }

    public function test_templates_grant_price_permissions_by_job(): void
    {
        // MD-03 follow-up: tills and managers read prices (the POS sells with
        // them), accountants and the auditor read them; only Owner and Admin
        // change them.
        $this->enter($this->signUp()->json('challenge_id'));
        $prices = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where('name', 'like', 'core.price.%')->pluck('name')->sort()->values()->all();

        $this->assertSame(['core.price.edit', 'core.price.view'], $prices('owner'));
        $this->assertSame(['core.price.edit', 'core.price.view'], $prices('admin'));

        foreach (['branch_manager', 'cashier', 'waiter', 'accountant', 'read_only_auditor'] as $key) {
            $this->assertSame(['core.price.view'], $prices($key), $key);
        }

        foreach (['storekeeper', 'procurement_officer', 'hr_officer'] as $key) {
            $this->assertSame([], $prices($key), $key);
        }
    }

    public function test_templates_grant_item_permissions_by_job(): void
    {
        // MD-02: tills read the catalogue; managers, storekeepers and buyers
        // keep items; storekeepers also keep categories; only Owner and Admin
        // archive or change units; the auditor only reads.
        $this->enter($this->signUp()->json('challenge_id'));
        $items = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where(fn ($q) => $q->where('name', 'like', 'core.item.%')->orWhere('name', 'like', 'core.item_category.%')->orWhere('name', 'like', 'core.uom.%'))
            ->pluck('name')->sort()->values()->all();

        $all = [
            'core.item.archive', 'core.item.create', 'core.item.edit', 'core.item.view',
            'core.item_category.archive', 'core.item_category.create', 'core.item_category.edit', 'core.item_category.view',
            'core.uom.edit', 'core.uom.view',
        ];
        $this->assertSame($all, $items('owner'));
        $this->assertSame($all, $items('admin'));

        foreach (['cashier', 'waiter'] as $key) {
            $this->assertSame(['core.item.view', 'core.item_category.view', 'core.uom.view'], $items($key), $key);
        }

        foreach (['branch_manager', 'procurement_officer'] as $key) {
            $this->assertSame(['core.item.create', 'core.item.edit', 'core.item.view', 'core.item_category.view', 'core.uom.view'], $items($key), $key);
        }

        $this->assertSame([
            'core.item.create', 'core.item.edit', 'core.item.view',
            'core.item_category.create', 'core.item_category.edit', 'core.item_category.view', 'core.uom.view',
        ], $items('storekeeper'));
        $this->assertSame(['core.item.view', 'core.item_category.view', 'core.uom.view'], $items('read_only_auditor'));
        $this->assertSame([], $items('hr_officer'));
    }

    public function test_templates_grant_payment_method_and_dimension_permissions_by_job(): void
    {
        // MD-04, MD-05: tills, managers and the accountant read payment
        // methods (only Owner and Admin manage and configure them: provider
        // credentials are a fraud path); HR and buyers read dimensions; the
        // accountant keeps dimensions; the auditor reads.
        $this->enter($this->signUp()->json('challenge_id'));
        $granted = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where(fn ($q) => $q->where('name', 'like', 'core.payment_method.%')->orWhere('name', 'like', 'core.dimension.%'))
            ->pluck('name')->sort()->values()->all();

        $all = [
            'core.dimension.archive', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.view',
            'core.payment_method.archive', 'core.payment_method.configure', 'core.payment_method.create', 'core.payment_method.edit', 'core.payment_method.view',
        ];

        foreach (['owner', 'admin'] as $key) {
            $this->assertSame($all, $granted($key), $key);
        }

        $this->assertSame([
            'core.dimension.archive', 'core.dimension.create', 'core.dimension.edit', 'core.dimension.view', 'core.payment_method.view',
        ], $granted('accountant'));

        foreach (['branch_manager', 'cashier'] as $key) {
            $this->assertSame(['core.payment_method.view'], $granted($key), $key);
        }

        foreach (['hr_officer', 'procurement_officer'] as $key) {
            $this->assertSame(['core.dimension.view'], $granted($key), $key);
        }

        $this->assertSame(['core.dimension.view', 'core.payment_method.view'], $granted('read_only_auditor'));
        $this->assertSame([], $granted('storekeeper'));
    }

    public function test_templates_grant_workflow_permissions_to_owner_and_admin(): void
    {
        // WF-02, spec 6.4: only Owner and Admin edit and publish flows; the
        // auditor reads them. Documents move through stage roles (WF-08).
        $this->enter($this->signUp()->json('challenge_id'));
        $granted = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where('name', 'like', 'core.workflow.%')->pluck('name')->sort()->values()->all();

        foreach (['owner', 'admin'] as $key) {
            $this->assertSame(['core.workflow.edit', 'core.workflow.publish', 'core.workflow.view'], $granted($key), $key);
        }

        $this->assertSame(['core.workflow.view'], $granted('read_only_auditor'));

        foreach (['branch_manager', 'cashier', 'accountant', 'approver', 'procurement_officer'] as $key) {
            $this->assertSame([], $granted($key), $key);
        }
    }

    public function test_templates_grant_automation_permissions_to_owner_and_admin(): void
    {
        // AUTO-01..AUTO-07: Owner and Admin build automation rules; the
        // auditor reads them and their run log.
        $this->enter($this->signUp()->json('challenge_id'));
        $granted = fn (string $key) => Role::where('template_key', $key)->sole()->permissions()
            ->where('name', 'like', 'core.automation.%')->pluck('name')->sort()->values()->all();

        foreach (['owner', 'admin'] as $key) {
            $this->assertSame(['core.automation.edit', 'core.automation.view'], $granted($key), $key);
        }

        $this->assertSame(['core.automation.view'], $granted('read_only_auditor'));

        foreach (['branch_manager', 'cashier', 'accountant', 'approver', 'procurement_officer'] as $key) {
            $this->assertSame([], $granted($key), $key);
        }
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
        $this->assertCount(98, $permissions);
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
