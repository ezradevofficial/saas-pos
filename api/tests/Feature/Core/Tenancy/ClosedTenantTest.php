<?php

namespace Tests\Feature\Core\Tenancy;

use App\Core\Tenancy\Models\Tenant;
use Tests\Concerns\BuildsApiKeys;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsTill;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// TEN-06, API-05 (Phase 8 review H2): a closed tenant is closed for
// everyone. Sign-in, token refresh and every authenticated request answer
// 403 `tenant_closed` (the token endpoint: `invalid_grant` with that code),
// for sessions, API keys, app tokens and tills, whatever the subscription
// (this tenant has none).
class ClosedTenantTest extends TestCase
{
    use BuildsApiKeys, BuildsMarketplace, BuildsTill, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function close(): void
    {
        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['status' => 'closed']));
    }

    public function test_sessions_api_keys_and_tills_of_a_closed_tenant_are_refused(): void
    {
        $this->activateTill($this->owner->tenant_id);
        $till = $this->pairTill($this->locationA);
        $key = $this->apiKey();
        $session = $this->headersFor();
        $unpaired = $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Second till'], $session)->assertCreated()->json('data.id');
        $code = $this->postJson("/api/v1/devices/{$unpaired}/pairing-code", [], $session)->assertOk()->json('code');

        $this->getJson('/api/v1/me', $session)->assertOk();
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertOk();
        $this->getJson('/api/v1/sync/device-secret/challenge', $this->deviceHeaders($till))->assertOk();

        $this->close();

        $this->getJson('/api/v1/me', $session)->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        // No subscription: EnforceSubscription alone used to let these writes through.
        $this->postJson('/api/v1/companies', ['name' => 'Late', 'country' => 'KE', 'base_currency' => 'KES'], $session)->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        $this->getJson('/api/v1/companies', $this->keyHeaders($key['secret']))->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        $this->getJson('/api/v1/sync/device-secret/challenge', $this->deviceHeaders($till))->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        $this->postJson('/api/v1/devices/pair', ['code' => $code, 'device_name' => 'Second till'])->assertForbidden()->assertJsonPath('code', 'tenant_closed');

        // Signing in is refused with the reason, but only once the password is right.
        $this->signIn($this->owner->email)->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        $this->assertNotSame('tenant_closed', $this->signIn($this->owner->email, 'wrong-password-123')->json('code'));
        $this->assertStringContainsString('fermé', $this->signIn($this->owner->email, null, ['Accept-Language' => 'fr'])->json('message'));
    }

    public function test_app_tokens_and_their_refresh_are_refused_for_a_closed_tenant(): void
    {
        $app = $this->listedApp();
        $pkce = $this->pkce();
        $approved = $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $tokens = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $app)->assertOk();
        $headers = ['Authorization' => 'Bearer '.$tokens->json('access_token'), 'Accept' => 'application/json'];
        $this->getJson('/api/v1/items', $headers)->assertOk();

        $this->close();

        $this->getJson('/api/v1/items', $headers)->assertForbidden()->assertJsonPath('code', 'tenant_closed');
        $this->refresh($tokens->json('refresh_token'), $app)->assertStatus(400)->assertJsonPath('error', 'invalid_grant')->assertJsonPath('code', 'tenant_closed');
    }
}
