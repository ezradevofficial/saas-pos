<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Identity\Services\Authenticate;
use App\Core\Rbac\Models\Role;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\UnencryptedToken;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// API-02, API-03 (Phase 8 review M5): one app serving many tenants from
// one address is never refused because of that address. Failed app-token
// authentications count only once a token fails to resolve, per token and
// address, never for a validly signed token that has only expired; the
// token endpoint is limited per client id with a looser ceiling per address.
class AppRateLimitsTest extends TestCase
{
    use BuildsMarketplace, BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    /** @return array{access: string, refresh: string} the app installed by a new tenant's Owner, and its first tokens */
    private function installInNewTenant(array $app): array
    {
        $other = $this->otherTenant();
        [$token, $admin] = $this->asTenant($other['user']->tenant_id, fn () => [
            app(Authenticate::class)->issueToken($other['user'], '127.0.0.1', 'tests'),
            Role::query()->where('template_key', 'admin')->value('id'),
        ]);
        $pkce = $this->pkce();
        $approved = $this->approve($app['client_id'], $pkce['challenge'], $this->bearer($token), ['role' => $admin, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $tokens = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $app)->assertOk();

        return ['access' => $tokens->json('access_token'), 'refresh' => $tokens->json('refresh_token')];
    }

    /** $jwt again, validly signed with the server's key, but expired an hour ago. */
    private function expired(string $jwt): string
    {
        $config = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText((string) config('passport.private_key')), InMemory::plainText((string) config('passport.public_key')));
        $token = $config->parser()->parse($jwt);
        $this->assertInstanceOf(UnencryptedToken::class, $token);
        $claims = $token->claims();
        $past = new DateTimeImmutable('-2 hours');

        return $config->builder()
            ->permittedFor(...$claims->get('aud'))
            ->identifiedBy($claims->get('jti'))
            ->issuedAt($past)
            ->canOnlyBeUsedAfter($past)
            ->expiresAt(new DateTimeImmutable('-1 hour'))
            ->relatedTo($claims->get('sub'))
            ->withClaim('scopes', $claims->get('scopes'))
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    private function as(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_an_app_serving_fifty_tenants_from_one_address_with_expired_tokens_keeps_working(): void
    {
        config(['api.rate_limits.failed_auth_per_token' => 3]);
        $app = $this->listedApp();
        $installs = [];

        // Fifty installs: fifty code exchanges from one address within the minute.
        foreach (range(1, 50) as $i) {
            $installs[] = $this->installInNewTenant($app);
        }

        // Expired but validly signed tokens are not guesses: never counted, however often they come.
        $expired = $this->expired($installs[0]['access']);
        foreach (range(1, 5) as $i) {
            $this->getJson('/api/v1/items', $this->as($expired))->assertUnauthorized();
        }
        foreach ($installs as $install) {
            $this->getJson('/api/v1/items', $this->as($this->expired($install['access'])))->assertUnauthorized();
        }

        // A forged token is limited on its own, from this address only.
        $forged = substr($installs[1]['access'], 0, -4).'AAAA';
        foreach (range(1, 3) as $i) {
            $this->getJson('/api/v1/items', $this->as($forged))->assertUnauthorized();
        }
        $this->getJson('/api/v1/items', $this->as($forged))->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        // Every install refreshes and keeps working from the same address.
        foreach ($installs as $install) {
            $fresh = $this->refresh($install['refresh'], $app)->assertOk();
            $this->getJson('/api/v1/items', $this->as($fresh->json('access_token')))->assertOk();
        }

        // The forged token's window is this address's alone.
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])->getJson('/api/v1/items', $this->as($forged))->assertUnauthorized();
    }

    public function test_the_token_endpoint_is_limited_per_client_with_a_looser_ceiling_per_address(): void
    {
        config(['marketplace.oauth.token_requests_per_minute' => 3, 'marketplace.oauth.token_requests_per_minute_per_ip' => 5]);
        $one = $this->listedApp();
        $two = $this->listedApp();
        $wrong = fn (array $app) => $this->post('/api/v1/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'], 'refresh_token' => 'not-a-token'], ['Accept' => 'application/json']);

        foreach (range(1, 3) as $i) {
            $wrong($one)->assertStatus(400);
        }
        $wrong($one)->assertStatus(429);
        // Another client from the same address has its own window, up to the address's ceiling.
        $wrong($two)->assertStatus(400);
        $wrong($two)->assertStatus(400);
        $wrong($two)->assertStatus(429);
        // The first client from another address is not held back by this one.
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40']);
        $wrong($one)->assertStatus(400);
    }
}
