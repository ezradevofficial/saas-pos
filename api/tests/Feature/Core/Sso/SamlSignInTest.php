<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoIdentity;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Sso\FakeSamlIdp;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023: SAML 2.0 sign-in with responses signed in the test:
 * the happy path (response or assertion signed), a tampered signature, an
 * untrusted certificate, an unsigned response, replay, wrong audience,
 * recipient and InResponseTo, IdP-initiated responses, expiry, and MFA
 * from the AuthnContext.
 */
class SamlSignInTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase;

    private User $amina;

    private string $saml;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpSso();
        $this->verifyDomain('acme.co.ke');
        $this->saml = $this->samlConnection();
        $this->amina = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->inTenant(fn () => $this->amina->forceFill(['email' => 'amina@acme.co.ke'])->save());
    }

    private function acs(): string
    {
        return "https://api.example.test/api/v1/auth/sso/saml/{$this->saml}/acs";
    }

    private function audience(): string
    {
        return "https://api.example.test/api/v1/auth/sso/saml/{$this->saml}";
    }

    /**
     * Start, sign a response for the flow's AuthnRequest with $options, post it to the ACS.
     *
     * @return array{location: string, binding: string, state: string, request_id: string}
     */
    private function signInWith(array $options = []): array
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $started['response']->assertOk();
        $requestId = FakeSamlIdp::requestIdFrom($started['url']);
        $response = FakeSamlIdp::response([
            'acs' => $this->acs(), 'audience' => $this->audience(), 'in_response_to' => $requestId, 'email' => 'amina@acme.co.ke', ...$options,
        ]);

        return [
            'location' => $this->samlReturn($options['post_to'] ?? $this->saml, $started['query']['RelayState'], $response),
            'binding' => $started['binding'],
            'state' => $started['query']['RelayState'],
            'request_id' => $requestId,
            'response' => $response,
        ];
    }

    public function test_a_signed_response_signs_the_user_in(): void
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->assertStringStartsWith(FakeSamlIdp::SSO_URL.'?SAMLRequest=', $started['url']);
        $this->assertSame(43, strlen($started['query']['RelayState']));

        foreach (['response', 'assertion'] as $sign) {
            $done = $this->signInWith(['sign' => $sign]);
            $this->completeSso($this->ticketFrom($done['location']), $done['binding'])->assertOk()->assertJsonPath('user.id', $this->amina->id);
        }

        $this->inTenant(fn () => $this->assertSame('amina@acme.co.ke', SsoIdentity::query()->where('user_id', $this->amina->id)->sole()->subject));
    }

    public function test_an_unsigned_tampered_or_foreign_signed_response_is_refused(): void
    {
        $this->assertSame('signature_invalid', $this->errorFrom($this->signInWith(['sign' => 'none'])['location']));
        $this->assertSame('signature_invalid', $this->errorFrom($this->signInWith(['pair' => FakeSamlIdp::otherPair()])['location']));

        // Signed, then the subject changed to the Owner's address.
        $tampered = $this->signInWith(['tamper' => fn (string $xml) => str_replace('>amina@acme.co.ke<', '>owner@acme.co.ke<', $xml)]);
        $this->assertContains($this->errorFrom($tampered['location']), ['signature_invalid', 'saml_invalid']);

        $this->inTenant(fn () => $this->assertSame(0, SsoIdentity::query()->count()));
    }

    public function test_an_assertion_is_accepted_once(): void
    {
        $first = $this->signInWith(['assertion_id' => '_replayed-assertion']);
        $this->ticketFrom($first['location']);

        // The captured response posted again: its flow is spent.
        $this->assertSame('state_invalid', $this->errorFrom($this->samlReturn($this->saml, $first['state'], $first['response'])));

        // The same assertion id inside a new flow: the replay set refuses it.
        $this->assertSame('replayed', $this->errorFrom($this->signInWith(['assertion_id' => '_replayed-assertion'])['location']));
    }

    public function test_audience_recipient_and_in_response_to_must_be_ours(): void
    {
        $this->assertSame('audience_mismatch', $this->errorFrom($this->signInWith(['audience' => 'https://other-sp.example.com'])['location']));
        $this->assertContains($this->errorFrom($this->signInWith(['acs' => 'https://api.example.test/elsewhere'])['location']), ['saml_invalid']);
        $this->assertSame('in_response_to_mismatch', $this->errorFrom($this->signInWith(['in_response_to' => '_another-request'])['location']));
        // IdP-initiated: no InResponseTo at all.
        $this->assertSame('in_response_to_mismatch', $this->errorFrom($this->signInWith(['in_response_to' => null])['location']));
        $this->assertSame('issuer_mismatch', $this->errorFrom($this->signInWith(['issuer' => 'https://evil-idp.example.com'])['location']));

        // Posted to another connection's ACS than the flow's.
        $other = $this->samlConnection(body: ['name' => 'Second SAML']);
        $started = $this->startSso(['email' => 'amina@acme.co.ke', 'connection_id' => $this->saml]);
        $response = FakeSamlIdp::response(['acs' => $this->acs(), 'audience' => $this->audience(), 'in_response_to' => FakeSamlIdp::requestIdFrom($started['url']), 'email' => 'amina@acme.co.ke']);
        $this->assertSame('saml_invalid', $this->errorFrom($this->samlReturn($other, $started['query']['RelayState'], $response)));
    }

    public function test_expired_and_not_yet_valid_assertions_are_refused(): void
    {
        // Beyond the 180-second skew either way.
        $this->assertSame('expired', $this->errorFrom($this->signInWith(['not_on_or_after' => time() - 600])['location']));
        $this->assertSame('expired', $this->errorFrom($this->signInWith(['not_before' => time() + 600])['location']));
        // Within the skew: accepted.
        $this->ticketFrom($this->signInWith(['not_on_or_after' => time() - 60])['location']);
    }

    public function test_a_multi_factor_authn_context_skips_the_apps_second_factor(): void
    {
        $this->inTenant(fn () => $this->amina->forceFill([
            'two_factor_method' => TwoFactor::METHOD_TOTP, 'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now(),
        ])->save());

        $password = $this->signInWith();
        $this->completeSso($this->ticketFrom($password['location']), $password['binding'])->assertJsonPath('status', 'two_factor_required');

        $mfa = $this->signInWith(['attributes' => ['http://schemas.microsoft.com/claims/authnmethodsreferences' => 'http://schemas.microsoft.com/claims/multipleauthn']]);
        $this->completeSso($this->ticketFrom($mfa['location']), $mfa['binding'])->assertOk()->assertJsonStructure(['token']);

        $context = $this->signInWith(['context' => 'https://refeds.org/profile/mfa']);
        $this->completeSso($this->ticketFrom($context['location']), $context['binding'])->assertOk()->assertJsonStructure(['token']);

        $this->inTenant(fn () => $this->assertSame([false, true, true], AuditEntry::query()->where('action', 'auth.sso.sign_in')->orderBy('seq')->get()->map(fn ($e) => $e->after['idp_mfa'])->all()));
    }

    public function test_garbage_is_a_failed_sign_in_never_an_error_page(): void
    {
        $started = $this->startSso(['email' => 'amina@acme.co.ke']);
        $this->assertSame('saml_invalid', $this->errorFrom($this->samlReturn($this->saml, $started['query']['RelayState'], base64_encode('<not-saml/>'))));
        $this->assertSame('state_invalid', $this->errorFrom($this->samlReturn($this->saml, 'unknown', 'x')));
    }
}
