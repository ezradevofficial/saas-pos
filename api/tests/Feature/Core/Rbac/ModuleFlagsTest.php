<?php

namespace Tests\Feature\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Identity\Models\VerificationChallenge;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsRbac;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// RBAC-08: a module's permissions and routes apply only while the tenant
// has the module active.
class ModuleFlagsTest extends TestCase
{
    use BuildsRbac, RefreshTenantDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        app(ModuleRegistry::class)->register('demo');
        app(PermissionRegistry::class)->register('demo', ['thing' => ['view']]);
        $this->syncPermissionCatalogue();

        Route::middleware(['api', 'auth:sanctum', 'tenant', 'module:demo'])
            ->get('/api/v1/demo/things', fn () => ['ok' => true]);

        $this->user = $this->createUser();
        $this->asTenant($this->user->tenant_id, fn () => $this->assign(
            $this->user,
            $this->role('Demo user', ['demo.thing.view', 'core.company.view']),
            Scope::tenant(),
        ));
    }

    private function can(string $permission): bool
    {
        return $this->asTenant($this->user->tenant_id, fn () => app(ScopeResolver::class)->can($this->user, $permission));
    }

    public function test_core_is_always_active_and_unknown_modules_never_are(): void
    {
        $this->asTenant($this->user->tenant_id, function () {
            $this->assertTrue(app(ModuleRegistry::class)->isActive('core'));
            $this->assertFalse(app(ModuleRegistry::class)->isActive('nope'));
            $this->assertFalse(app(ModuleRegistry::class)->isActive('demo'));
        });
    }

    public function test_an_inactive_module_grants_nothing_and_its_routes_are_refused(): void
    {
        $this->assertFalse($this->can('demo.thing.view'));
        $this->assertTrue($this->can('core.company.view'));

        $token = $this->tokenFor($this->user);

        $this->getJson('/api/v1/demo/things', $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'module_inactive')
            ->assertJsonPath('message', __('rbac.errors.module_inactive'));

        $response = $this->getJson('/api/v1/me/permissions', $this->bearer($token))->assertOk();
        $this->assertSame(['core'], $response->json('modules'));
        $this->assertSame(['core.company.view'], collect($response->json('permissions'))->pluck('name')->all());
    }

    public function test_an_active_module_grants_its_permissions_and_routes(): void
    {
        $this->asTenant($this->user->tenant_id, fn () => app(ModuleRegistry::class)->activate('demo'));

        $this->assertTrue($this->can('demo.thing.view'));

        $token = $this->tokenFor($this->user);
        $this->getJson('/api/v1/demo/things', $this->bearer($token))->assertOk()->assertJsonPath('ok', true);

        $response = $this->getJson('/api/v1/me/permissions', $this->bearer($token))->assertOk();
        $this->assertEqualsCanonicalizing(['core', 'demo'], $response->json('modules'));
        $this->assertContains('demo.thing.view', collect($response->json('permissions'))->pluck('name')->all());
    }

    public function test_a_deactivated_module_is_refused_again(): void
    {
        $this->asTenant($this->user->tenant_id, function () {
            app(ModuleRegistry::class)->activate('demo');
            app(ModuleRegistry::class)->deactivate('demo');

            $module = TenantModule::where('module', 'demo')->sole();
            $this->assertSame('inactive', $module->status);
            $this->assertNotNull($module->activated_at);
            $this->assertNotNull($module->deactivated_at);
        });

        $this->assertFalse($this->can('demo.thing.view'));
    }

    public function test_module_activation_is_per_tenant(): void
    {
        $this->asTenant($this->user->tenant_id, fn () => app(ModuleRegistry::class)->activate('demo'));

        $other = $this->createUser();
        $this->asTenant($other->tenant_id, function () use ($other) {
            $this->assign($other, $this->role('Demo user', ['demo.thing.view']), Scope::tenant());

            $this->assertFalse(app(ModuleRegistry::class)->isActive('demo'));
            $this->assertFalse(app(ScopeResolver::class)->can($other, 'demo.thing.view'));
        });
    }

    public function test_activating_a_module_extends_the_system_roles(): void
    {
        $challengeId = $this->postJson('/api/v1/auth/sign-up', [
            'name' => 'Amina Otieno',
            'email' => 'amina@example.com',
            'password' => $this->password,
            'country' => 'KE',
            'locale' => 'en',
            'business_name' => 'Amina Stores',
        ])->assertCreated()->json('challenge_id');
        app(TenantContext::class)->set(VerificationChallenge::findOrFail($challengeId)->tenant_id);

        // A module registered and synced after the tenant's roles were seeded.
        app(ModuleRegistry::class)->register('extra');
        app(PermissionRegistry::class)->register('extra', ['widget' => ['view', 'edit']]);
        $this->syncPermissionCatalogue();

        $owner = Role::where('template_key', 'owner')->sole();
        $auditor = Role::where('template_key', 'read_only_auditor')->sole();
        $this->assertFalse($owner->permissions()->where('name', 'extra.widget.view')->exists());

        app(ModuleRegistry::class)->activate('extra');

        // syncPermissions on is_system roles passes the system-role guard.
        $this->assertTrue($owner->permissions()->where('name', 'extra.widget.edit')->exists());
        $this->assertTrue($auditor->permissions()->where('name', 'extra.widget.view')->exists());
        $this->assertFalse($auditor->permissions()->where('name', 'extra.widget.edit')->exists());
        $this->assertFalse(Role::where('template_key', 'cashier')->sole()->permissions()->where('name', 'extra.widget.view')->exists());
        $this->assertTrue($owner->fresh()->is_system);
    }
}
