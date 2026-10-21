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
use Tests\Support\Sso\FakeOidcIdp;
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
        $this->entra = $this->entraConnection();
    }

    private string $entra;

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

    public function test_an_owner_is_never_linked_by_email_but_links_their_own_account_while_signed_in(): void
    {
        $this->inTenant(fn () => $this->owner->forceFill(['email' => 'owner@acme.co.ke'])->save());
        $this->assertSame('owner_link_required', $this->signsIn('owner@acme.co.ke')[0]);
        $this->inTenant(fn () => $this->assertSame(0, SsoIdentity::query()->count()));

        // Signed in with the password, the Owner starts the link from Settings, Security.
        $mine = $this->getJson('/api/v1/me/sso', $this->headersFor())->assertOk();
        $connection = $mine->json('data.connections.0.id');
        $this->assertSame([], $mine->json('data.identities'));
        $redirect = $this->postJson('/api/v1/me/sso/link', ['connection_id' => $connection, 'origin' => 'https://app.example.test'], $this->headersFor())
            ->assertOk()->json('data.redirect_url');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        // Someone else's IdP account cannot be linked to the Owner.
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $query['nonce']));
        $this->assertSame('https://app.example.test/settings/security#sso_link=failed&reason=link_email_mismatch', $this->oidcReturn($query['state']));

        $redirect = $this->postJson('/api/v1/me/sso/link', ['connection_id' => $connection, 'origin' => 'https://app.example.test'], $this->headersFor())->json('data.redirect_url');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('owner@acme.co.ke', $query['nonce']));
        $this->assertSame('https://app.example.test/settings/security#sso_link=ok', $this->oidcReturn($query['state']));

        $this->getJson('/api/v1/me/sso', $this->headersFor())->assertJsonPath('data.identities.0.email', 'owner@acme.co.ke');
        $link = $this->inTenant(fn () => AuditEntry::query()->where('action', 'auth.sso.link')->sole());
        $this->assertSame(['self', $this->owner->id], [$link->after['via'], $link->user_id]);

        // From now on the Owner signs in with SSO through the link.
        [$error, $response] = $this->signsIn('owner@acme.co.ke');
        $this->assertNull($error);
        $response->assertJsonPath('user.id', $this->owner->id);
    }

    public function test_an_invitation_to_an_owner_role_is_never_accepted_by_sso(): void
    {
        $this->postJson('/api/v1/invitations', [
            'name' => 'Co-owner', 'email' => 'coowner@acme.co.ke',
            'assignments' => [['role_id' => $this->roles->get('owner')->id, 'scope_type' => 'tenant']],
        ], $this->headersFor())->assertCreated();

        $this->assertSame('owner_link_required', $this->signsIn('coowner@acme.co.ke')[0]);
        $this->inTenant(fn () => $this->assertSame(0, User::query()->where('email', 'coowner@acme.co.ke')->count()));
    }

    public function test_repointing_a_connection_archives_its_links_and_disables_it_until_tested(): void
    {
        $amina = $this->member(['email' => 'amina@acme.co.ke']);
        $this->assertNull($this->signsIn('amina@acme.co.ke')[0]);

        // A new name or secret is the same IdP: links and the connection stay.
        $this->patchJson("/api/v1/sso/connections/{$this->entra}", ['name' => 'Entra', 'client_secret' => 'rotated'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.enabled', true);
        $this->inTenant(fn () => $this->assertSame(1, SsoIdentity::query()->whereNull('archived_at')->count()));

        // Another directory is another IdP: its subjects mean other people.
        $this->patchJson("/api/v1/sso/connections/{$this->entra}", ['directory_id' => '11111111-2222-4333-8444-555555555555'], $this->headersFor())
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.tested_at', null);
        $this->inTenant(function () use ($amina) {
            $this->assertSame(0, SsoIdentity::query()->where('user_id', $amina->id)->whereNull('archived_at')->count());
            $this->assertSame(1, SsoIdentity::query()->where('user_id', $amina->id)->whereNotNull('archived_at')->count(), 'kept as history');
            $archive = AuditEntry::query()->where('action', 'core.sso.identities_archive')->sole();
            $this->assertSame([1, ['directory_id']], [$archive->after['archived'], $archive->after['changed']]);
            $this->assertSame(1, AuditEntry::query()->where('action', 'core.sso.connection_disable')->where('auditable_id', $this->entra)->count());
        });
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'amina@acme.co.ke'])->assertNotFound();
    }

    public function test_repointing_the_last_connection_is_refused_while_sso_is_enforced(): void
    {
        $this->putJson('/api/v1/sso/settings', ['enforce' => true], $this->headersFor())->assertOk();
        $this->patchJson("/api/v1/sso/connections/{$this->entra}", ['client_id' => '11111111-2222-4333-8444-555555555555'], $this->headersFor())
            ->assertStatus(409)->assertJsonPath('code', 'enforce_needs_connection');
    }

    public function test_links_are_listed_and_unlinked_by_their_user_or_an_sso_manager(): void
    {
        $amina = $this->member(['email' => 'amina@acme.co.ke']);
        $this->assertNull($this->signsIn('amina@acme.co.ke')[0]);
        $this->inTenant(fn () => $amina->forceFill(['password' => $this->password])->save());

        $links = $this->getJson("/api/v1/users/{$amina->id}/sso-identities", $this->headersFor())->assertOk()->json('data');
        $this->assertSame([['Microsoft', 'amina@acme.co.ke']], array_map(fn ($l) => [$l['connection_name'], $l['email']], $links));

        // Not an SSO manager: refused; the user's own list works.
        $this->getJson("/api/v1/users/{$amina->id}/sso-identities", $this->headersFor($amina))->assertForbidden();
        $this->getJson('/api/v1/me/sso', $this->headersFor($amina))->assertOk()->assertJsonPath('data.identities.0.id', $links[0]['id']);

        // A link is not found under another user's id.
        $this->postJson("/api/v1/users/{$this->owner->id}/sso-identities/{$links[0]['id']}/archive", [], $this->headersFor())->assertNotFound();
        $this->postJson("/api/v1/users/{$amina->id}/sso-identities/{$links[0]['id']}/archive", [], $this->headersFor())->assertOk();
        $this->getJson("/api/v1/users/{$amina->id}/sso-identities", $this->headersFor())->assertJsonPath('data', []);
        $this->inTenant(fn () => $this->assertSame('manager', AuditEntry::query()->where('action', 'auth.sso.unlink')->sole()->after['by']));

        // Signing in again links afresh; then Amina unlinks it herself.
        $this->assertNull($this->signsIn('amina@acme.co.ke')[0]);
        $own = $this->getJson('/api/v1/me/sso', $this->headersFor($amina))->json('data.identities.0.id');
        $this->postJson("/api/v1/me/sso/identities/{$own}/archive", [], $this->headersFor($amina))->assertOk();
        $this->getJson('/api/v1/me/sso', $this->headersFor($amina))->assertJsonPath('data.identities', []);
    }
}
