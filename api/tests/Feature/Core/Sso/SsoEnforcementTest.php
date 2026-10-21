<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoConnection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\SentInvitations;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023, RBAC-10: enforced single sign-on refuses password
 * sign-in for users of the verified domains (after the password is
 * checked), keeps the Owner's break-glass password and 2FA, leaves other
 * addresses alone, and refuses sign-up for an enforcing tenant's domains.
 */
class SsoEnforcementTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase;

    private User $amina;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
        $this->setUpSso();
        $this->headersFor();
        $this->inTenant(fn () => $this->owner->forceFill(['email' => 'owner@acme.co.ke'])->save());
        $this->verifyDomain('acme.co.ke');
        $this->entraConnection();
        $this->amina = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->inTenant(fn () => $this->amina->forceFill(['email' => 'amina@acme.co.ke'])->save());
    }

    private function enforce(bool $on = true): void
    {
        $this->putJson('/api/v1/sso/settings', ['enforce' => $on], $this->headersFor())->assertOk()->assertJsonPath('data.settings.enforce', $on);
    }

    public function test_enforcing_refuses_password_sign_in_for_the_domains_users(): void
    {
        $this->signIn('amina@acme.co.ke')->assertOk();
        $this->enforce();

        $this->signIn('amina@acme.co.ke')->assertForbidden()->assertJsonPath('code', 'sso_required');
        // A wrong password says only that it is wrong, and counts toward the lockout.
        $this->signIn('amina@acme.co.ke', 'wrong-password-1')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->inTenant(fn () => $this->assertSame(1, $this->amina->fresh()->failed_sign_ins));
        $this->inTenant(fn () => $this->assertSame('sso_required', AuditEntry::query()->where('action', 'auth.sign_in_failed')->where('auditable_id', $this->amina->id)->orderBy('seq')->first()->after['reason']));

        // SSO still works for them.
        $done = $this->entraSignIn('amina@acme.co.ke');
        $this->completeSso($this->ticketFrom($done['location']), $done['binding'])->assertOk();

        // Another domain's user of the same tenant is not affected.
        $this->inTenant(fn () => $this->colleague($this->owner, ['email' => 'contractor@freelance.example']));
        $this->signIn('contractor@freelance.example')->assertOk();

        $this->enforce(false);
        $this->signIn('amina@acme.co.ke')->assertOk();
    }

    public function test_the_owner_keeps_a_break_glass_password_and_second_factor(): void
    {
        $this->enforce();
        $this->signIn('owner@acme.co.ke')->assertOk()->assertJsonStructure(['token']);

        // With 2FA, the Owner gets the usual challenge.
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $this->inTenant(fn () => $this->owner->forceFill(['two_factor_method' => TwoFactor::METHOD_TOTP, 'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save());
        $challenge = $this->signIn('owner@acme.co.ke')->assertOk()->assertJsonPath('status', 'two_factor_required')->json('challenge_id');
        $this->postJson('/api/v1/auth/two-factor/challenge', ['challenge_id' => $challenge, 'code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_enforcing_needs_an_enabled_connection_and_a_verified_domain(): void
    {
        $other = $this->otherTenant();
        $this->extraTenants[] = $other['user']->tenant_id;
        $headers = $this->bearer($this->tokenFor($other['user']));

        $this->putJson('/api/v1/sso/settings', ['enforce' => true], $headers)->assertStatus(422)->assertJsonPath('code', 'enforce_needs_setup');
        $this->entraConnection($headers);
        $this->putJson('/api/v1/sso/settings', ['enforce' => true], $headers)->assertStatus(422)->assertJsonPath('code', 'enforce_needs_setup');
        $this->verifyDomain('other-co.example', $headers);
        $this->putJson('/api/v1/sso/settings', ['enforce' => true], $headers)->assertOk();
    }

    public function test_the_last_enabled_connection_stays_while_enforced(): void
    {
        $this->enforce();
        $id = $this->inTenant(fn () => SsoConnection::query()->value('id'));

        $this->postJson("/api/v1/sso/connections/{$id}/disable", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'enforce_needs_connection');
        $this->postJson("/api/v1/sso/connections/{$id}/archive", [], $this->headersFor())->assertStatus(409);

        $second = $this->googleConnection();
        $this->postJson("/api/v1/sso/connections/{$id}/disable", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/sso/connections/{$second}/archive", [], $this->headersFor())->assertStatus(409);
    }

    public function test_an_invitee_of_an_enforced_domain_joins_with_sso_not_a_password(): void
    {
        $this->enforce();
        $this->postJson('/api/v1/invitations', [
            'name' => 'Baraka', 'email' => 'baraka@acme.co.ke',
            'assignments' => [['role_id' => $this->roles->get('cashier')->id, 'scope_type' => 'location', 'scope_id' => $this->locationA->id]],
        ], $this->headersFor())->assertCreated();
        $token = SentInvitations::lastToken();

        $this->postJson("/api/v1/auth/invitations/{$token}/accept", ['name' => 'Baraka', 'password' => 'violet-harbour-42'])
            ->assertForbidden()->assertJsonPath('code', 'sso_required');

        $done = $this->entraSignIn('baraka@acme.co.ke');
        $this->completeSso($this->ticketFrom($done['location']), $done['binding'])->assertOk()->assertJsonPath('user.email', 'baraka@acme.co.ke');
    }

    public function test_sign_up_is_refused_for_an_enforcing_tenants_domain(): void
    {
        $body = fn (string $email) => [
            'name' => 'Newcomer', 'email' => $email, 'password' => 'violet-harbour-42', 'country' => 'KE', 'locale' => 'en', 'business_name' => 'Side business',
        ];

        // Not enforced: a person may still start their own business with a work address.
        $this->postJson('/api/v1/auth/sign-up', $body('first@acme.co.ke'))->assertCreated();

        $this->enforce();
        $this->postJson('/api/v1/auth/sign-up', $body('second@ACME.co.ke'))->assertStatus(422)
            ->assertJsonValidationErrors(['email' => 'Your organisation uses single sign-on.']);
        $this->postJson('/api/v1/auth/sign-up', $body('someone@elsewhere.example'))->assertCreated();
    }
}
