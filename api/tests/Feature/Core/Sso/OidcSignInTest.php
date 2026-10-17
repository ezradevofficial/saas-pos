<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\PersonalAccessToken;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoIdentity;
use App\Core\Sso\Oidc\OidcClient;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023: OIDC sign-in with Entra ID and Google Workspace
 * against a fake IdP (keys generated in the test): state, nonce and PKCE,
 * the ID token's issuer, audience, signature and expiry, the browser
 * binding and single-use ticket, lockout, and the IdP-asserted MFA
 * decision (AUTH-03).
 */
class OidcSignInTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase;

    private User $amina;

    private string $entra;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpSso();
        $this->verifyDomain('acme.co.ke');
        $this->entra = $this->entraConnection();
        $this->amina = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->inTenant(fn () => $this->amina->forceFill(['email' => 'amina@acme.co.ke'])->save());
    }

    public function test_an_entra_sign_in_links_the_existing_user_and_issues_a_normal_session(): void
    {
        $started = $this->startSso(['email' => 'Amina@Acme.co.ke', 'origin' => 'https://evil.example.com']);
        $started['response']->assertOk();

        // The authorisation request: our endpoint from discovery, state, nonce and an S256 PKCE challenge.
        $this->assertStringStartsWith('https://login.microsoftonline.com/'.FakeOidcIdp::DIRECTORY.'/oauth2/v2.0/authorize?', $started['url']);
        $query = $started['query'];
        $this->assertSame([FakeOidcIdp::CLIENT_ID, 'code', 'S256', 'openid profile email'], [$query['client_id'], $query['response_type'], $query['code_challenge_method'], $query['scope']]);
        $this->assertSame('https://api.example.test/api/v1/auth/sso/oidc/callback', $query['redirect_uri']);
        $this->assertSame(43, strlen($query['state']));
        $this->assertSame(43, strlen($query['nonce']));
        $this->assertSame(43, strlen($started['binding']));

        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $query['nonce']));
        $location = $this->oidcReturn($query['state']);

        // The browser goes back to the web app (never an origin it named elsewhere), the ticket in the fragment.
        $this->assertStringStartsWith('https://app.example.test/sso/complete#ticket=', $location);

        // The code went back with the PKCE verifier matching the challenge, and the client secret.
        $exchange = $this->idp->tokenRequests[0];
        $this->assertSame(['authorization_code', 'auth-code', 'entra-secret-value'], [$exchange['grant_type'], $exchange['code'], $exchange['client_secret']]);
        $this->assertSame($query['code_challenge'], OidcClient::challenge($exchange['code_verifier']));

        $response = $this->completeSso($this->ticketFrom($location), $started['binding'])->assertOk()
            ->assertJsonPath('user.id', $this->amina->id)
            ->assertJsonPath('two_factor_enrollment_required', false);

        // A normal session: in the session list, audited as a sign-in and as SSO.
        $this->getJson('/api/v1/me', $this->bearer($response->json('token')))->assertOk()->assertJsonPath('data.email', 'amina@acme.co.ke');
        $actions = $this->inTenant(fn () => AuditEntry::query()->where('user_id', $this->amina->id)->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['auth.sso.link', 'auth.sso.sign_in', 'auth.sign_in'], array_values(array_intersect($actions, ['auth.sso.link', 'auth.sso.sign_in', 'auth.sign_in'])));
        $this->inTenant(fn () => $this->assertSame(1, SsoIdentity::query()->where('user_id', $this->amina->id)->count()));
        $this->inTenant(fn () => $this->assertSame(1, PersonalAccessToken::query()->where('tokenable_id', $this->amina->id)->count()));

        // The next sign-in finds the link by subject; no new link.
        $again = $this->entraSignIn('amina@acme.co.ke');
        $this->completeSso($this->ticketFrom($again['location']), $again['binding'])->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, SsoIdentity::query()->where('user_id', $this->amina->id)->count()));
    }

    public function test_the_ticket_needs_its_binding_and_is_spent_once(): void
    {
        $first = $this->entraSignIn('amina@acme.co.ke');
        $ticket = $this->ticketFrom($first['location']);

        // Another browser (another binding) cannot use it: login CSRF.
        $other = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->completeSso($ticket, $other['binding'])->assertStatus(422)->assertJsonPath('code', 'sso_ticket_invalid');
        // Spent by the failed attempt, it cannot be retried either.
        $this->completeSso($ticket, $first['binding'])->assertStatus(422)->assertJsonPath('code', 'sso_ticket_invalid');

        $second = $this->entraSignIn('amina@acme.co.ke');
        $ticket = $this->ticketFrom($second['location']);
        $this->completeSso($ticket, $second['binding'])->assertOk();
        $this->completeSso($ticket, $second['binding'])->assertStatus(422);
    }

    public function test_the_state_is_spent_once_and_an_unknown_state_fails(): void
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce']));

        $this->assertSame('state_invalid', $this->errorFrom($this->oidcReturn(str_repeat('x', 43))));
        $this->ticketFrom($this->oidcReturn($started['query']['state']));
        // Replaying the IdP's redirect: the flow is gone.
        $this->assertSame('state_invalid', $this->errorFrom($this->oidcReturn($started['query']['state'])));
        $this->assertCount(1, $this->idp->tokenRequests);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function tamperedTokens(): array
    {
        return [
            'wrong issuer' => [['iss' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000000/v2.0'], 'issuer_mismatch'],
            'common issuer' => [['iss' => 'https://login.microsoftonline.com/common/v2.0'], 'issuer_mismatch'],
            'wrong audience' => [['aud' => 'another-client'], 'audience_mismatch'],
            'several audiences, azp elsewhere' => [['aud' => [FakeOidcIdp::CLIENT_ID, 'other'], 'azp' => 'other'], 'audience_mismatch'],
            'wrong nonce' => [['nonce' => 'not-the-flows-nonce'], 'nonce_mismatch'],
            'no nonce' => [['nonce' => null], 'nonce_mismatch'],
            'expired' => [['exp' => 1_700_000_000, 'iat' => 1_699_996_400, 'nbf' => 1_699_996_400], 'expired'],
            'not yet valid' => [['nbf' => 4_102_444_800], 'token_invalid'],
            'no email' => [['email' => null, 'preferred_username' => null], 'email_missing'],
        ];
    }

    #[DataProvider('tamperedTokens')]
    public function test_a_tampered_id_token_is_refused(array $overrides, string $reason): void
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce'], $overrides));

        $this->assertSame($reason, $this->errorFrom($this->oidcReturn($started['query']['state'])));
        $this->inTenant(fn () => $this->assertSame(0, SsoIdentity::query()->count()));
        $this->inTenant(fn () => $this->assertSame($reason, AuditEntry::query()->where('action', 'auth.sso.sign_in_failed')->latest('seq')->value('after')['reason']));
    }

    public function test_a_bad_signature_is_refused(): void
    {
        $cases = [
            // Signed by a key the IdP never published, under the IdP's kid.
            'other key' => fn (array $claims) => FakeOidcIdp::sign($claims, FakeOidcIdp::otherKey()),
            // A valid token whose payload was changed after signing.
            'edited payload' => function (array $claims) {
                [$h, , $s] = explode('.', FakeOidcIdp::sign($claims));

                return $h.'.'.rtrim(strtr(base64_encode(json_encode([...$claims, 'email' => 'owner@acme.co.ke'])), '+/', '-_'), '=').'.'.$s;
            },
            // Algorithm confusion: HS256 with the public modulus as the secret, or no signature at all.
            'hs256' => fn (array $claims) => FakeOidcIdp::sign($claims, ['private' => FakeOidcIdp::key()['n']], alg: 'HS256'),
            'none' => fn (array $claims) => rtrim(strtr(base64_encode('{"alg":"none","kid":"'.FakeOidcIdp::KID.'"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.',
            'unknown kid' => fn (array $claims) => FakeOidcIdp::sign($claims, FakeOidcIdp::otherKey(), 'rotated-key'),
        ];

        foreach ($cases as $label => $sign) {
            $started = $this->startSso(['email' => 'amina@acme.co.ke']);
            $this->idp->idToken = $sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce']));
            $this->assertSame('signature_invalid', $this->errorFrom($this->oidcReturn($started['query']['state'])), $label);
        }

        $this->inTenant(fn () => $this->assertSame(0, SsoIdentity::query()->count()));
    }

    public function test_an_unknown_kid_fetches_the_keys_again_once(): void
    {
        $other = FakeOidcIdp::otherKey();
        $first = $this->entraSignIn('amina@acme.co.ke');
        $this->ticketFrom($first['location']);
        $fetches = $this->idp->jwksFetches;

        // The IdP rotates: the new key is published after our cache was filled.
        $this->idp->extraKeys = [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'rotated', 'n' => $other['n'], 'e' => $other['e']]];
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce']), $other, 'rotated');
        $this->ticketFrom($this->oidcReturn($started['query']['state']));
        $this->assertSame($fetches + 1, $this->idp->jwksFetches);
    }

    public function test_an_idp_error_or_a_failed_exchange_fails_the_sign_in(): void
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->assertSame('idp_error', $this->errorFrom($this->oidcReturn($started['query']['state'], null, 'access_denied')));

        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->idp->idToken = null;
        $this->assertSame('token_exchange_failed', $this->errorFrom($this->oidcReturn($started['query']['state'])));
    }

    public function test_google_requires_a_verified_workspace_email(): void
    {
        $this->googleConnection(body: ['hosted_domain' => 'acme.co.ke']);
        $start = fn () => $this->startSso(['email' => 'amina@acme.co.ke']);

        // Two connections: the user chooses.
        $choices = $start()['response']->assertOk()->json('data.connections');
        $this->assertSame(['Google', 'Microsoft'], array_column($choices, 'name'));
        $google = collect($choices)->firstWhere('provider', 'google')['id'];

        foreach ([
            [['email_verified' => false], 'email_unverified'],
            [['hd' => null], 'hosted_domain_mismatch'],
            [['hd' => 'other.co.ke'], 'hosted_domain_mismatch'],
            [['iss' => 'https://accounts.example.com'], 'issuer_mismatch'],
            [[], null],
        ] as [$overrides, $reason]) {
            $started = $this->startSso(['email' => 'amina@acme.co.ke', 'connection_id' => $google]);
            $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $started['url']);
            $this->assertSame('acme.co.ke', $started['query']['hd']);
            $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::googleClaims('amina@acme.co.ke', $started['query']['nonce'], $overrides));
            $location = $this->oidcReturn($started['query']['state']);

            $reason === null
                ? $this->completeSso($this->ticketFrom($location), $started['binding'])->assertOk()->assertJsonPath('user.id', $this->amina->id)
                : $this->assertSame($reason, $this->errorFrom($location));
        }
    }

    public function test_idp_mfa_skips_the_apps_second_factor_and_its_absence_keeps_it(): void
    {
        // Amina has TOTP set up.
        $this->inTenant(fn () => $this->amina->forceFill([
            'two_factor_method' => TwoFactor::METHOD_TOTP, 'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now(),
        ])->save());

        // No MFA asserted: the app's challenge, as for a password sign-in.
        $plain = $this->entraSignIn('amina@acme.co.ke', ['amr' => ['pwd']]);
        $this->completeSso($this->ticketFrom($plain['location']), $plain['binding'])->assertOk()
            ->assertJsonPath('status', 'two_factor_required')->assertJsonMissingPath('token');

        // MFA asserted (amr mfa): straight in.
        $mfa = $this->entraSignIn('amina@acme.co.ke', ['amr' => ['pwd', 'mfa']]);
        $this->completeSso($this->ticketFrom($mfa['location']), $mfa['binding'])->assertOk()->assertJsonStructure(['token']);
        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'auth.sso.sign_in')->latest('seq')->value('after')['idp_mfa']));
    }

    public function test_idp_mfa_satisfies_a_role_that_requires_two_factor(): void
    {
        // System roles refuse edits through the model; the test sets the flag directly.
        $this->inTenant(fn () => Role::query()->whereKey($this->roles->get('cashier')->id)->toBase()->update(['requires_two_factor' => true]));

        $plain = $this->entraSignIn('amina@acme.co.ke');
        $token = $this->completeSso($this->ticketFrom($plain['location']), $plain['binding'])->assertOk()
            ->assertJsonPath('two_factor_enrollment_required', true)->json('token');
        $this->getJson('/api/v1/auth/sessions', $this->bearer($token))->assertForbidden();

        $mfa = $this->entraSignIn('amina@acme.co.ke', ['amr' => ['mfa']]);
        $token = $this->completeSso($this->ticketFrom($mfa['location']), $mfa['binding'])->assertOk()
            ->assertJsonPath('two_factor_enrollment_required', false)->json('token');
        $this->getJson('/api/v1/auth/sessions', $this->bearer($token))->assertOk();
    }

    public function test_lockout_and_deactivation_apply(): void
    {
        $this->inTenant(fn () => $this->amina->forceFill(['locked_until' => now()->addMinutes(10)])->save());
        $locked = $this->entraSignIn('amina@acme.co.ke');
        $this->completeSso($this->ticketFrom($locked['location']), $locked['binding'])->assertStatus(423)->assertJsonPath('code', 'locked');

        $this->inTenant(fn () => $this->amina->forceFill(['locked_until' => null, 'status' => User::STATUS_DEACTIVATED])->save());
        $this->assertSame('deactivated', $this->errorFrom($this->entraSignIn('amina@acme.co.ke')['location']));
    }

    public function test_a_disabled_connection_or_unknown_email_cannot_start(): void
    {
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'someone@elsewhere.co.ke'])->assertNotFound()->assertJsonPath('code', 'sso_not_found');
        $this->postJson('/api/v1/auth/sso/start', ['host' => 'unknown.example.org'])->assertNotFound();

        $this->startSso(['email' => 'amina@acme.co.ke'])['response']->assertOk();
        $this->postJson("/api/v1/sso/connections/{$this->entra}/disable", [], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'amina@acme.co.ke'])->assertNotFound();
    }

    public function test_the_tenants_host_starts_without_an_email(): void
    {
        config(['branding.base_domain' => 'example.app']);
        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['slug' => 'acme']));

        $started = $this->startSso(['host' => 'acme.example.app', 'origin' => 'https://acme.example.app']);
        $started['response']->assertOk();
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce']));

        // Back to the tenant's own host, which it started from.
        $location = $this->oidcReturn($started['query']['state']);
        $this->assertStringStartsWith('https://acme.example.app/sso/complete#ticket=', $location);
        $this->completeSso($this->ticketFrom($location), $started['binding'])->assertOk();

        // Another tenant's host is never a return origin for this tenant's flow.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => Tenant::query()->whereKey($other['user']->tenant_id)->update(['slug' => 'other']));
        $started = $this->startSso(['host' => 'acme.example.app', 'origin' => 'https://other.example.app']);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('amina@acme.co.ke', $started['query']['nonce']));
        $this->assertStringStartsWith('https://app.example.test/sso/complete#', $this->oidcReturn($started['query']['state']));
    }
}
