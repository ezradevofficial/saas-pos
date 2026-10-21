<?php

namespace Tests\Concerns;

use App\Core\Branding\Domains\DnsResolver;
use App\Core\Sso\Models\SsoConnection;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Support\Branding\FakeDnsResolver;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;

/**
 * AUTH-11: an organisation (BuildsOrganisation) with SSO helpers: verified
 * email domains (a fake DNS resolver), connections enabled as if tested,
 * the fake OIDC IdP, and the browser's side of a sign-in.
 */
trait BuildsSso
{
    use BuildsOrganisation;

    protected FakeDnsResolver $dns;

    protected FakeOidcIdp $idp;

    protected function setUpSso(): void
    {
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->idp = (new FakeOidcIdp)->fake();
        $this->setUpOrganisation();
        config(['app.frontend_url' => 'https://app.example.test', 'sso.callback_base' => 'https://api.example.test']);
    }

    /** Claim $domain and publish its TXT record; answers the domain as the API shows it. */
    protected function verifyDomain(string $domain, ?array $headers = null): array
    {
        $headers ??= $this->headersFor();
        $row = $this->postJson('/api/v1/sso/domains', ['domain' => $domain], $headers)->assertCreated()->json('data');
        $this->dns->publish($row['record']['name'], $row['record']['value']);

        return $this->postJson("/api/v1/sso/domains/{$row['id']}/check", [], $headers)->assertOk()->json('data');
    }

    /** An Entra connection, enabled as if its test had passed. */
    protected function entraConnection(?array $headers = null, array $body = []): string
    {
        return $this->enabledConnection([
            'provider' => 'entra', 'name' => 'Microsoft', 'client_id' => FakeOidcIdp::CLIENT_ID,
            'client_secret' => 'entra-secret-value', 'directory_id' => FakeOidcIdp::DIRECTORY, ...$body,
        ], $headers);
    }

    protected function googleConnection(?array $headers = null, array $body = []): string
    {
        return $this->enabledConnection([
            'provider' => 'google', 'name' => 'Google', 'client_id' => FakeOidcIdp::GOOGLE_CLIENT_ID,
            'client_secret' => 'google-secret-value', ...$body,
        ], $headers);
    }

    protected function samlConnection(?array $headers = null, array $body = []): string
    {
        return $this->enabledConnection([
            'provider' => 'saml', 'name' => 'Entra SAML', 'idp_entity_id' => FakeSamlIdp::ENTITY_ID,
            'idp_sso_url' => FakeSamlIdp::SSO_URL, 'idp_certificates' => [FakeSamlIdp::pair()['cert']], ...$body,
        ], $headers);
    }

    private function enabledConnection(array $body, ?array $headers): string
    {
        $headers ??= $this->headersFor();
        $id = $this->postJson('/api/v1/sso/connections', $body, $headers)->assertCreated()->json('data.id');
        $this->markTested($id);
        $this->postJson("/api/v1/sso/connections/{$id}/enable", [], $headers)->assertOk();

        return $id;
    }

    /** As if a test sign-in had passed (the test flow itself is tested on its own). */
    protected function markTested(string $connectionId): void
    {
        $this->forConnection($connectionId, fn (SsoConnection $c) => $c->forceFill(['tested_at' => CarbonImmutable::now(), 'last_test' => ['ok' => true]])->save());
    }

    /** Run $fn with the connection, in whichever of the test's tenants holds it. */
    protected function forConnection(string $connectionId, callable $fn): mixed
    {
        foreach ($this->ssoTenants() as $tenantId) {
            $found = $this->asTenant($tenantId, function () use ($connectionId, $fn) {
                $connection = SsoConnection::query()->find($connectionId);

                return $connection === null ? null : [$fn($connection)];
            });

            if ($found !== null) {
                return $found[0];
            }
        }

        $this->fail("No tenant holds connection {$connectionId}.");
    }

    /** @return list<string> the tenants a test made, the organisation's first */
    protected function ssoTenants(): array
    {
        return array_values(array_unique([$this->owner->tenant_id, ...($this->extraTenants ?? [])]));
    }

    /** @var list<string> */
    protected array $extraTenants = [];

    /**
     * Start a sign-in as the browser would.
     *
     * @return array{response: TestResponse, url: string, binding: string, query: array<string, string>}
     */
    protected function startSso(array $body): array
    {
        $response = $this->postJson('/api/v1/auth/sso/start', $body);
        $url = (string) $response->json('data.redirect_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['response' => $response, 'url' => $url, 'binding' => (string) $response->json('data.binding'), 'query' => $query];
    }

    /** The IdP sends the browser back with $state; answers where the API redirected it. */
    protected function oidcReturn(string $state, ?string $code = 'auth-code', ?string $error = null): string
    {
        $response = $this->get('/api/v1/auth/sso/oidc/callback?'.http_build_query(array_filter(['state' => $state, 'code' => $code, 'error' => $error])));
        $response->assertStatus(303);

        return (string) $response->headers->get('Location');
    }

    protected function samlReturn(string $connectionId, string $relayState, string $samlResponse): string
    {
        $response = $this->post("/api/v1/auth/sso/saml/{$connectionId}/acs", ['SAMLResponse' => $samlResponse, 'RelayState' => $relayState]);
        $response->assertStatus(303);

        return (string) $response->headers->get('Location');
    }

    /** The ticket in a redirect's fragment, failing with the error code if there is none. */
    protected function ticketFrom(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $fragment);
        $this->assertArrayHasKey('ticket', $fragment, "No ticket in {$location}");

        return $fragment['ticket'];
    }

    protected function errorFrom(string $location): ?string
    {
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $fragment);

        return $fragment['error'] ?? null;
    }

    protected function completeSso(string $ticket, string $binding): TestResponse
    {
        return $this->postJson('/api/v1/auth/sso/complete', ['ticket' => $ticket, 'binding' => $binding, 'device_name' => 'Browser']);
    }

    /** A full Entra sign-in for $email: start by email, the IdP signs the claims, back with the ticket. */
    protected function entraSignIn(string $email, array $overrides = [], ?string $startEmail = null): array
    {
        $started = $this->startSso(['email' => $startEmail ?? $email]);
        $started['response']->assertOk();
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims($email, $started['query']['nonce'], $overrides));
        $location = $this->oidcReturn($started['query']['state']);

        return [...$started, 'location' => $location];
    }
}
