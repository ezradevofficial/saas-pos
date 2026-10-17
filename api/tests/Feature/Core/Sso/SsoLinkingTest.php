<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\Invitation;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoIdentity;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023: who an assertion signs in. Only addresses of a
 * verified domain; an existing active user with that verified email, or
 * an open invitation (accepted with its assignments); JIT provisioning
 * only when switched on, with the default role re-checked; never a user
 * of another tenant; never platform staff or a deactivated user; one
 * subject per user and connection.
 */
class SsoLinkingTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpSso();
        $this->verifyDomain('acme.co.ke');
        $this->entraConnection();
    }

    private function member(array $attributes): User
    {
        return $this->inTenant(function () use ($attributes) {
            $user = $this->colleague($this->owner, ['email' => $attributes['email']]);
            $user->forceFill($attributes)->save();

            return $user;
        });
    }

    private function signsIn(string $email, array $claims = [], ?string $start = null): array
    {
        $done = $this->entraSignIn($email, $claims, $start);
        $error = $this->errorFrom($done['location']);

        return [$error, $error === null ? $this->completeSso($this->ticketFrom($done['location']), $done['binding']) : null];
    }

    public function test_an_address_outside_the_verified_domains_is_refused(): void
    {
        $this->inTenant(fn () => $this->owner->forceFill(['email' => 'owner@acme.co.ke'])->save());
        $pending = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme-group.com'], $this->headersFor())->assertCreated();

        // Discovery finds the tenant by acme.co.ke, but the IdP asserts an address of an unproven domain.
        $this->assertSame('domain_not_verified', $this->signsIn('owner@acme-group.com', start: 'owner@acme.co.ke')[0]);
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'owner@acme-group.com'])->assertNotFound();
        $this->assertNotNull($pending->json('data.id'));
    }

    public function test_an_unknown_address_is_refused_without_jit_and_an_unverified_email_is_not_linked(): void
    {
        $this->assertSame('no_account', $this->signsIn('newcomer@acme.co.ke')[0]);
        $this->inTenant(fn () => $this->assertSame(0, User::query()->where('email', 'newcomer@acme.co.ke')->count()));

        $unverified = $this->member(['email' => 'unverified@acme.co.ke', 'email_verified_at' => null]);
        $this->assertSame('no_account', $this->signsIn('unverified@acme.co.ke')[0]);
        $this->inTenant(fn () => $this->assertSame(0, SsoIdentity::query()->where('user_id', $unverified->id)->count()));
    }

    public function test_an_open_invitation_is_accepted_with_its_assignments(): void
    {
        $this->postJson('/api/v1/invitations', [
            'name' => 'Baraka', 'email' => 'baraka@acme.co.ke',
            'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]],
        ], $this->headersFor())->assertCreated();

        [$error, $response] = $this->signsIn('baraka@acme.co.ke');
        $this->assertNull($error);
        $response->assertOk()->assertJsonPath('user.email', 'baraka@acme.co.ke');

        $this->inTenant(function () {
            $user = User::query()->where('email', 'baraka@acme.co.ke')->sole();
            $this->assertSame('Amina Otieno', $user->name);
            $this->assertNotNull($user->email_verified_at);
            $this->assertSame([[$this->roles->get('cashier')->id, 'location', $this->locationA->id]],
                RoleAssignment::query()->where('user_id', $user->id)->get()->map(fn ($a) => [$a->role_id, $a->scope_type, $a->scope_id])->all());
            $this->assertNotNull(Invitation::query()->where('email', 'baraka@acme.co.ke')->sole()->accepted_at);
            // An unusable random password: the invitee never chose one.
            $this->assertFalse(Hash::check($this->password, $user->password));
        });
    }

    public function test_jit_provisioning_creates_the_user_with_the_default_role_only_when_on(): void
    {
        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => true], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'jit_role_required');
        // An Owner role is never a default.
        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => true, 'jit_role_id' => $this->roles->get('owner')->id], $this->headersFor())
            ->assertStatus(422)->assertJsonPath('code', 'jit_owner_role');

        $this->putJson('/api/v1/sso/settings', [
            'jit_provisioning' => true, 'jit_role_id' => $this->roles->get('cashier')->id, 'jit_scope_type' => 'branch', 'jit_scope_id' => $this->branchA->id,
        ], $this->headersFor())->assertOk()->assertJsonPath('data.settings.jit_provisioning', true);

        [$error, $response] = $this->signsIn('newcomer@acme.co.ke');
        $this->assertNull($error);
        $response->assertOk();

        $this->inTenant(function () {
            $user = User::query()->where('email', 'newcomer@acme.co.ke')->sole();
            $this->assertSame(User::STATUS_ACTIVE, $user->status);
            $this->assertSame([[$this->roles->get('cashier')->id, 'branch', $this->branchA->id, $this->owner->id]],
                RoleAssignment::query()->where('user_id', $user->id)->get()->map(fn ($a) => [$a->role_id, $a->scope_type, $a->scope_id, $a->created_by])->all());
            $this->assertSame(1, AuditEntry::query()->where('action', 'auth.sso.provision')->count());
        });

        // Off again: the next newcomer is refused.
        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => false], $this->headersFor())->assertOk();
        $this->assertSame('no_account', $this->signsIn('second@acme.co.ke')[0]);
    }

    public function test_jit_is_refused_when_whoever_set_the_default_can_no_longer_grant_it(): void
    {
        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => true, 'jit_role_id' => $this->roles->get('cashier')->id], $this->headersFor())->assertOk();
        $this->inTenant(fn () => $this->roles->get('cashier')->forceFill(['archived_at' => now()])->saveQuietly());

        $this->assertSame('jit_stale', $this->signsIn('newcomer@acme.co.ke')[0]);
    }

    public function test_a_user_who_cannot_grant_the_default_role_cannot_set_it(): void
    {
        // A custom role holding core.sso.manage but not what the admin role grants.
        $manager = $this->asTenant($this->owner->tenant_id, function () {
            $user = $this->colleague($this->owner, ['email' => 'sso-admin@acme.example']);
            $this->assign($user, $this->role('SSO admin', ['core.sso.manage', 'core.user.invite', 'core.role.assign']), Scope::tenant());

            return $user;
        });

        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => true, 'jit_role_id' => $this->roles->get('admin')->id], $this->headersFor($manager))
            ->assertForbidden()->assertJsonPath('code', 'cannot_grant');
    }

    public function test_never_across_tenants(): void
    {
        // Tenant B has a user with an acme.co.ke address (emails are unique across tenants).
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => $other['user']->forceFill(['email' => 'boss@acme.co.ke'])->save());
        $this->extraTenants[] = $other['user']->tenant_id;

        // Tenant A's IdP asserts that address: B's user is invisible here, so no link.
        $this->assertSame('no_account', $this->signsIn('boss@acme.co.ke')[0]);

        // With JIT on, the address is still never created in A (it belongs to B).
        $this->putJson('/api/v1/sso/settings', ['jit_provisioning' => true, 'jit_role_id' => $this->roles->get('cashier')->id], $this->headersFor())->assertOk();
        $this->assertSame('no_account', $this->signsIn('boss@acme.co.ke')[0]);

        $this->asTenant($other['user']->tenant_id, fn () => $this->assertSame(0, SsoIdentity::query()->count()));
        $this->inTenant(fn () => $this->assertSame(0, User::query()->where('email', 'boss@acme.co.ke')->count()));

        // Tenant B cannot claim acme.co.ke: A proved it first.
        $headersB = $this->bearer($this->tokenFor($other['user']));
        $claim = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.co.ke'], $headersB)->assertCreated()->json('data');
        $this->dns->publish($claim['record']['name'], $claim['record']['value']);
        $this->postJson("/api/v1/sso/domains/{$claim['id']}/check", [], $headersB)->assertOk()
            ->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure', 'claimed_elsewhere');
    }

    public function test_platform_staff_setup_accounts_and_deactivated_users_are_never_signed_in(): void
    {
        $this->member(['email' => 'setup@acme.co.ke', 'is_platform_staff' => true]);
        $this->assertSame('no_account', $this->signsIn('setup@acme.co.ke')[0]);

        $this->member(['email' => 'leaver@acme.co.ke', 'status' => User::STATUS_DEACTIVATED]);
        $this->assertSame('deactivated', $this->signsIn('leaver@acme.co.ke')[0]);
    }

    public function test_one_subject_per_user_and_connection(): void
    {
        $user = $this->member(['email' => 'amina@acme.co.ke']);
        $this->assertNull($this->signsIn('amina@acme.co.ke')[0]);

        // Another IdP account claiming the same address is refused.
        $this->assertSame('identity_conflict', $this->signsIn('amina@acme.co.ke', ['sub' => 'another-subject'])[0]);

        // The linked subject keeps working even after the IdP changes the email.
        $this->member(['email' => 'amina.otieno@acme.co.ke']);
        [$error, $response] = $this->signsIn('amina.otieno@acme.co.ke', ['sub' => 'entra-subject-'.md5('amina@acme.co.ke')]);
        $this->assertNull($error);
        $response->assertJsonPath('user.id', $user->id);
    }
}
