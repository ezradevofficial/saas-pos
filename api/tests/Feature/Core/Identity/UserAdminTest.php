<?php

namespace Tests\Feature\Core\Identity;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// AUTH-13 deactivation keeps history; RBAC-04 users are listed in scope.
class UserAdminTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private User $cashierA;

    private User $cashierB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->cashierA = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->cashierB = $this->userWith('cashier', Scope::location($this->locationB->id));
        $this->inTenant(fn () => $this->cashierA->forceFill(['name' => 'Ann'])->save());
    }

    public function test_the_owner_lists_every_user_with_roles_and_scope_names(): void
    {
        $this->getJson('/api/v1/users', $this->headersFor())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Ann')
            ->assertJsonPath('data.0.roles.0.role.name', 'Cashier')
            ->assertJsonPath('data.0.roles.0.scope.type', 'location')
            ->assertJsonPath('data.0.roles.0.scope.id', $this->locationA->id)
            ->assertJsonPath('data.0.roles.0.scope.name', 'Outlet A');
    }

    public function test_a_branch_manager_sees_only_users_within_their_branch(): void
    {
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $ids = collect($this->getJson('/api/v1/users', $this->headersFor($manager))->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$this->cashierA->id, $manager->id], $ids->all());
        $this->getJson("/api/v1/users/{$this->cashierA->id}", $this->headersFor($manager))->assertOk();
        $this->getJson("/api/v1/users/{$this->cashierB->id}", $this->headersFor($manager))->assertNotFound();
        $this->getJson("/api/v1/users/{$this->owner->id}", $this->headersFor($manager))->assertNotFound();
        $this->patchJson("/api/v1/users/{$this->cashierB->id}", ['name' => 'X'], $this->headersFor($manager))->assertNotFound();

        // core.user.edit but not core.user.deactivate.
        $this->patchJson("/api/v1/users/{$this->cashierA->id}", ['name' => 'Annie'], $this->headersFor($manager))->assertOk();
        $this->postJson("/api/v1/users/{$this->cashierA->id}/deactivate", [], $this->headersFor($manager))->assertForbidden();
    }

    public function test_name_and_locale_are_edited_and_audited(): void
    {
        $this->patchJson("/api/v1/users/{$this->cashierA->id}", ['name' => 'Ann B', 'locale' => 'fr', 'email' => 'new@example.com'], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.name', 'Ann B')
            ->assertJsonPath('data.locale', 'fr')
            ->assertJsonPath('data.email', $this->cashierA->email);

        $this->inTenant(fn () => $this->assertSame(
            1, AuditEntry::where('action', 'core.user.update')->where('auditable_id', $this->cashierA->id)->where('after->name', 'Ann B')->count(),
        ));
    }

    public function test_deactivation_revokes_tokens_and_keeps_history(): void
    {
        $token = $this->tokenFor($this->cashierA);
        $this->getJson('/api/v1/me', $this->bearer($token))->assertOk();
        $before = $this->inTenant(fn () => AuditEntry::where('user_id', $this->cashierA->id)->count());
        $this->assertGreaterThan(0, $before);

        $this->postJson("/api/v1/users/{$this->cashierA->id}/deactivate", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'deactivated');

        $this->getJson('/api/v1/me', $this->bearer($token))->assertUnauthorized();
        $this->signIn($this->cashierA->email)->assertForbidden();

        $this->inTenant(function () use ($before) {
            $this->assertSame($before, AuditEntry::where('user_id', $this->cashierA->id)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.user.deactivate')->where('auditable_id', $this->cashierA->id)->count());
            $this->assertSame(0, $this->cashierA->tokens()->count());
            $this->assertTrue(User::whereKey($this->cashierA->id)->exists());
        });

        $this->postJson("/api/v1/users/{$this->cashierA->id}/reactivate", [], $this->headersFor())
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->signIn($this->cashierA->email)->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.user.reactivate')->count()));
    }

    public function test_a_user_who_never_verified_a_contact_cannot_be_reactivated(): void
    {
        $this->inTenant(fn () => $this->cashierB->forceFill(['status' => 'deactivated', 'email_verified_at' => null])->save());

        $this->postJson("/api/v1/users/{$this->cashierB->id}/reactivate", [], $this->headersFor())
            ->assertUnprocessable()
            ->assertJsonPath('code', 'contact_unverified');
    }

    public function test_sign_out_everywhere_revokes_every_token(): void
    {
        $first = $this->tokenFor($this->cashierA);
        $second = $this->tokenFor($this->cashierA);

        $this->postJson("/api/v1/users/{$this->cashierA->id}/sign-out-everywhere", [], $this->headersFor())->assertNoContent();

        $this->getJson('/api/v1/me', $this->bearer($first))->assertUnauthorized();
        $this->getJson('/api/v1/me', $this->bearer($second))->assertUnauthorized();
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.user.sign_out_everywhere')->count()));
    }

    public function test_only_an_owner_deactivates_an_owner(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $second = $this->userWith('owner', Scope::tenant());

        $this->postJson("/api/v1/users/{$second->id}/deactivate", [], $this->headersFor($admin))->assertForbidden();
        $this->postJson("/api/v1/users/{$second->id}/deactivate", [], $this->headersFor())->assertOk();
    }

    public function test_another_tenants_users_are_not_found(): void
    {
        $other = $this->otherTenant()['user'];

        $this->getJson("/api/v1/users/{$other->id}", $this->headersFor())->assertNotFound();
        $this->patchJson("/api/v1/users/{$other->id}", ['name' => 'X'], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/users/{$other->id}/deactivate", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/users/{$other->id}/sign-out-everywhere", [], $this->headersFor())->assertNotFound();
        $this->getJson("/api/v1/users/{$other->id}/assignments", $this->headersFor())->assertNotFound();
        $this->getJson('/api/v1/users/not-a-uuid', $this->headersFor())->assertNotFound();
        $this->assertNotContains($other->id, collect($this->getJson('/api/v1/users', $this->headersFor())->json('data'))->pluck('id'));
    }

    public function test_a_cashier_cannot_list_users(): void
    {
        $this->getJson('/api/v1/users', $this->headersFor($this->cashierA))->assertForbidden();
    }
}
