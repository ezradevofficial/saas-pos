<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Api\Models\ApiKey;
use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\OAuth\OAuthAccessToken;
use App\Core\Marketplace\OAuth\OAuthRefreshToken;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MKT-02, API-02 (OAuth), ADR 024: the authorization code flow with PKCE,
// consent as a role at a scope (never more than the installer can grant),
// the install's principal acting through the public API like an API key,
// rotating refresh tokens, and uninstalling.
class OAuthInstallTest extends TestCase
{
    use BuildsMarketplace, BuildsOrganisation, RefreshTenantDatabase;

    private array $listed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        $this->listed = $this->listedApp();
    }

    /** @return array{installation: string, access: string, refresh: string} */
    private function installAndExchange(?string $role = null, array $scope = ['scope_type' => 'tenant', 'scope_id' => null]): array
    {
        $pkce = $this->pkce();
        $approved = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $role ?? $this->roles->get('admin')->id, ...$scope])->assertOk();
        $tokens = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $this->listed)->assertOk();

        return ['installation' => $approved->json('data.installation_id'), 'access' => $tokens->json('access_token'), 'refresh' => $tokens->json('refresh_token')];
    }

    public function test_the_consent_screen_lists_what_the_app_asks_for_in_plain_words(): void
    {
        $pkce = $this->pkce();
        $response = $this->getJson('/api/v1/oauth/authorize?'.http_build_query($this->authorizeQuery($this->listed['client_id'], $pkce['challenge'])), $this->headersFor())->assertOk();

        $response->assertJsonPath('data.app.name', 'Stock sync')
            ->assertJsonPath('data.app.installation', null)
            ->assertJsonPath('data.can_install', true);
        $labels = array_column($response->json('data.app.scopes'), 'label', 'scope');
        $this->assertSame(['core.item.read' => 'See items', 'core.party.write' => 'See and change customers, suppliers and contacts'], $labels);

        $french = $this->getJson('/api/v1/oauth/authorize?'.http_build_query($this->authorizeQuery($this->listed['client_id'], $pkce['challenge'])), [...$this->headersFor(), 'Accept-Language' => 'fr'])->assertOk();
        $this->assertStringStartsWith('Voir', $french->json('data.app.scopes.0.label'));
    }

    public function test_approving_installs_the_app_as_a_principal_and_redirects_with_a_code(): void
    {
        $pkce = $this->pkce();
        $response = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id])->assertOk();
        $redirect = $response->json('data.redirect_to');

        $this->assertStringStartsWith(self::REDIRECT.'?code=', $redirect);
        $this->assertStringContainsString('state=state-123', $redirect);
        $this->assertNotSame('', self::codeFrom($redirect));

        $this->inTenant(function () use ($response) {
            $installation = AppInstallation::findOrFail($response->json('data.installation_id'));
            $principal = User::findOrFail($installation->principal_id);
            $key = ApiKey::findOrFail($installation->api_key_id);

            $this->assertTrue($principal->isServicePrincipal());
            $this->assertSame(ApiKey::KIND_APP, $key->kind);
            $this->assertSame(['core.item.read', 'core.party.write'], $key->scopeList());
            $assignment = RoleAssignment::where('user_id', $principal->id)->sole();
            $this->assertSame(['branch', $this->branchA->id], [$assignment->scope_type, $assignment->scope_id]);
            $this->assertSame(1, AuditEntry::where('action', 'core.app.install')->where('auditable_id', $installation->id)->count());
            // Codes are kept as hashes only.
            $this->assertSame(64, strlen(DB::table('oauth_auth_codes')->value('id')));
        });

        // Settings, API keys never shows an install's key.
        $this->getJson('/api/v1/api-keys', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_token_acts_on_public_routes_within_its_scopes_and_role(): void
    {
        $tokens = $this->installAndExchange();
        $headers = ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'];

        $this->getJson('/api/v1/items', $headers)->assertOk()->assertHeader('RateLimit-Limit');
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Amani', 'roles' => ['customer']], $headers)->assertCreated();
        // Read scope only for items.
        $this->postJson('/api/v1/items', ['name' => 'Tea'], $headers)->assertForbidden()->assertJsonPath('code', 'api_scope_missing');
        // Not a public route: refused before the controller runs.
        $this->getJson('/api/v1/me', $headers)->assertForbidden()->assertJsonPath('code', 'api_key_route_refused');
        $this->getJson('/api/v1/users', $headers)->assertForbidden();

        $this->inTenant(function () use ($tokens) {
            $installation = AppInstallation::findOrFail($tokens['installation']);
            $entry = AuditEntry::where('action', 'core.party.create')->latest('seq')->first();
            $this->assertSame($installation->principal_id, $entry->user_id);
            $this->assertSame($installation->id, $entry->after['metadata']['app_installation_id']);
        });
    }

    public function test_a_token_asked_for_fewer_scopes_acts_with_those_only(): void
    {
        $pkce = $this->pkce();
        $approved = $this->postJson('/api/v1/oauth/authorize', [
            ...$this->authorizeQuery($this->listed['client_id'], $pkce['challenge'], ['scope' => 'core.item.read']),
            'decision' => 'approve', 'role_id' => $this->roles->get('admin')->id, 'scope_type' => 'tenant',
        ], $this->headersFor())->assertOk();
        $tokens = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $this->listed)->assertOk();
        $headers = ['Authorization' => 'Bearer '.$tokens->json('access_token'), 'Accept' => 'application/json'];

        $this->getJson('/api/v1/items', $headers)->assertOk();
        $this->postJson('/api/v1/parties', ['kind' => 'person', 'name' => 'Amani', 'roles' => ['customer']], $headers)->assertForbidden()->assertJsonPath('code', 'api_scope_missing');
    }

    public function test_the_token_response_names_the_install_and_tokens_are_stored_hashed(): void
    {
        $pkce = $this->pkce();
        $approved = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $response = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $this->listed)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $response->assertJsonPath('installation_id', $approved->json('data.installation_id'))->assertJsonPath('token_type', 'Bearer');
        $this->inTenant(function () use ($response) {
            $this->assertSame(1, OAuthAccessToken::count());
            $this->assertStringNotContainsString((string) OAuthAccessToken::value('id'), $response->json('access_token'));
        });

        // The code is used once.
        $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $this->listed)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_pkce_is_required_and_checked(): void
    {
        $pkce = $this->pkce();
        $query = $this->authorizeQuery($this->listed['client_id'], $pkce['challenge']);

        $this->getJson('/api/v1/oauth/authorize?'.http_build_query(array_diff_key($query, ['code_challenge' => 1])), $this->headersFor())->assertUnprocessable();
        $this->getJson('/api/v1/oauth/authorize?'.http_build_query([...$query, 'code_challenge_method' => 'plain']), $this->headersFor())->assertUnprocessable();

        $approved = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $this->exchange(self::codeFrom($approved->json('data.redirect_to')), 'a-wrong-verifier-'.str_repeat('x', 40), $this->listed)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_redirect_uri_that_is_not_registered_is_refused_without_a_redirect(): void
    {
        $pkce = $this->pkce();
        $query = $this->authorizeQuery($this->listed['client_id'], $pkce['challenge'], ['redirect_uri' => 'https://evil.example.com/steal']);

        $this->getJson('/api/v1/oauth/authorize?'.http_build_query($query), $this->headersFor())->assertStatus(400)->assertJsonPath('code', 'oauth_invalid_client');
        $this->postJson('/api/v1/oauth/authorize', [...$query, 'decision' => 'deny'], $this->headersFor())->assertStatus(400)->assertJsonMissingPath('data.redirect_to');
        $this->inTenant(fn () => $this->assertSame(0, AppInstallation::count()));
    }

    public function test_denying_sends_the_app_back_with_access_denied(): void
    {
        $pkce = $this->pkce();
        $response = $this->postJson('/api/v1/oauth/authorize', [...$this->authorizeQuery($this->listed['client_id'], $pkce['challenge']), 'decision' => 'deny'], $this->headersFor())->assertOk();

        $this->assertStringContainsString('error=access_denied', $response->json('data.redirect_to'));
        $this->assertStringStartsWith(self::REDIRECT, $response->json('data.redirect_to'));
        $this->inTenant(fn () => $this->assertSame(0, AppInstallation::count()));
    }

    public function test_the_app_never_gets_more_than_the_installer_can_grant(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $pkce = $this->pkce();
        // Admins lack the full export: a role holding it cannot be given by them.
        $exporter = $this->inTenant(fn () => $this->role('Exporter', ['core.tenant.export', 'core.item.view']));

        $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor($admin), ['role' => $exporter->id, 'scope_type' => 'tenant', 'scope_id' => null])
            ->assertForbidden()->assertJsonPath('code', 'cannot_grant');
        // Never an Owner role.
        $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('owner')->id, 'scope_type' => 'tenant', 'scope_id' => null])
            ->assertUnprocessable()->assertJsonPath('code', 'app_owner_role');
        $this->inTenant(fn () => $this->assertSame(0, AppInstallation::count()));
    }

    public function test_people_without_the_permission_are_refused(): void
    {
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $pkce = $this->pkce();

        $this->getJson('/api/v1/oauth/authorize?'.http_build_query($this->authorizeQuery($this->listed['client_id'], $pkce['challenge'])), $this->headersFor($cashier))->assertForbidden();
        $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor($cashier), ['role' => $this->roles->get('cashier')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertForbidden();
        $this->getJson('/api/v1/apps/installed', $this->headersFor($cashier))->assertForbidden();
        $this->getJson('/api/v1/marketplace/apps', $this->headersFor($cashier))->assertForbidden();
    }

    public function test_another_tenants_role_scope_and_install_are_not_found(): void
    {
        $other = $this->otherTenant();
        $otherRole = $this->asTenant($other['user']->tenant_id, fn () => Role::where('name', 'Admin')->value('id'));
        $pkce = $this->pkce();

        $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $otherRole, 'scope_type' => 'tenant', 'scope_id' => null])->assertNotFound();
        $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'company', 'scope_id' => $other['company']->id])->assertNotFound();

        $tokens = $this->installAndExchange();
        $this->getJson("/api/v1/apps/installed/{$tokens['installation']}", $this->headersFor($other['user']))->assertNotFound();
        $this->postJson("/api/v1/apps/installed/{$tokens['installation']}/uninstall", [], $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/apps/installed', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
        // The token reads only its own tenant.
        $this->getJson('/api/v1/companies', ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'api_scope_missing');
        $this->getJson('/api/v1/parties', ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'])->assertOk()
            ->assertJsonMissing(['id' => $other['company']->id])->assertJsonCount(0, 'data');
    }

    public function test_refresh_tokens_rotate_and_a_reused_one_revokes_the_install_tokens(): void
    {
        $tokens = $this->installAndExchange();
        $rotated = $this->refresh($tokens['refresh'], $this->listed)->assertOk();
        $this->assertNotSame($tokens['refresh'], $rotated->json('refresh_token'));

        // The old access token went with its refresh token.
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'])->assertUnauthorized();
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer '.$rotated->json('access_token'), 'Accept' => 'application/json'])->assertOk();

        // Presenting the used refresh token again: refused, and every token of the install revoked.
        $this->refresh($tokens['refresh'], $this->listed)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer '.$rotated->json('access_token'), 'Accept' => 'application/json'])->assertUnauthorized();
        $this->refresh($rotated->json('refresh_token'), $this->listed)->assertStatus(400);
    }

    public function test_uninstalling_revokes_tokens_and_the_principal(): void
    {
        $tokens = $this->installAndExchange();
        $headers = ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'];
        $this->getJson('/api/v1/items', $headers)->assertOk();

        $this->postJson("/api/v1/apps/installed/{$tokens['installation']}/uninstall", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'uninstalled');

        $this->getJson('/api/v1/items', $headers)->assertUnauthorized();
        $this->refresh($tokens['refresh'], $this->listed)->assertStatus(400);
        $this->inTenant(function () use ($tokens) {
            $installation = AppInstallation::findOrFail($tokens['installation']);
            $this->assertNotNull(ApiKey::findOrFail($installation->api_key_id)->revoked_at);
            $this->assertSame(0, RoleAssignment::where('user_id', $installation->principal_id)->count());
            $this->assertSame(0, OAuthAccessToken::where('revoked', false)->count());
            $this->assertSame(0, OAuthRefreshToken::where('revoked', false)->count());
            $this->assertSame(1, AuditEntry::where('action', 'core.app.uninstall')->count());
        });
        $this->postJson("/api/v1/apps/installed/{$tokens['installation']}/uninstall", [], $this->headersFor())->assertStatus(409);
    }

    public function test_a_suspended_tenant_or_app_stops_its_tokens(): void
    {
        $tokens = $this->installAndExchange();
        $headers = ['Authorization' => 'Bearer '.$tokens['access'], 'Accept' => 'application/json'];

        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_apps')->where('id', $this->listed['app_id'])->update(['status' => 'suspended']);
        $this->getJson('/api/v1/items', $headers)->assertUnauthorized();
        $this->refresh($tokens['refresh'], $this->listed)->assertStatus(401);
        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_apps')->where('id', $this->listed['app_id'])->update(['status' => 'active']);
        $this->getJson('/api/v1/items', $headers)->assertOk();

        $this->inTenant(fn () => Tenant::query()->whereKey($this->owner->tenant_id)->update(['status' => 'suspended']));
        $this->getJson('/api/v1/items', $headers)->assertUnauthorized();
    }

    public function test_an_app_token_is_not_a_person_token_and_a_person_token_is_not_an_app_token(): void
    {
        $this->getJson('/api/v1/items', ['Authorization' => 'Bearer eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ4In0.c2lnbmF0dXJl', 'Accept' => 'application/json'])->assertUnauthorized();
        $this->inTenant(fn () => $this->assertSame(0, OAuthAccessToken::count()));
    }
}
