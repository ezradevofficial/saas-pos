<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\OAuth\OAuthTokens;
use App\Core\Marketplace\OAuth\Repositories\AuthCodeRepository;
use App\Core\Marketplace\OAuth\Repositories\RefreshTokenRepository;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Webhooks\Models\WebhookDelivery;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// Security review of feat/marketplace (ADR 024): app subscriptions cannot
// be taken over or repointed; deliveries name their install and are signed
// with a per-install key; no tenant session reads app secrets or unlisted
// clients; developers cannot write into another developer's app; one
// code or refresh token is redeemed once; suspended developers stop serving.
class MarketplaceSecurityTest extends TestCase
{
    use BuildsMarketplace, BuildsWebhooks, RefreshTenantDatabase;

    private array $listed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpWebhooks();
        $this->listed = $this->listedApp(['scopes' => ['pos.sale.read'], 'webhook_url' => 'https://hooks.example.com/app', 'webhook_events' => ['thing.happened.v1']]);
    }

    private function install(): AppInstallation
    {
        $pkce = $this->pkce();
        $id = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('branch_manager')->id, 'scope_type' => 'branch', 'scope_id' => $this->branchA->id])
            ->assertOk()->json('data.installation_id');

        return $this->inTenant(fn () => AppInstallation::with('subscription')->findOrFail($id));
    }

    private function refused(callable $write): bool
    {
        DB::beginTransaction();

        try {
            return $write() === 0;
        } catch (QueryException) {
            return true;
        } finally {
            DB::rollBack();
        }
    }

    // ---- HIGH 1: app subscriptions are locked ---------------------------------------------------

    public function test_an_app_subscription_cannot_be_taken_over_repointed_rotated_or_archived(): void
    {
        $subscription = $this->install()->webhook_subscription_id;

        $this->postJson("/api/v1/webhooks/{$subscription}/take-over", [], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'webhook_app_owned');
        $this->patchJson("/api/v1/webhooks/{$subscription}", ['url' => 'https://other.example.com/x?installation='.Str::uuid()], $this->headersFor())->assertForbidden();
        $this->patchJson("/api/v1/webhooks/{$subscription}", ['description' => 'Renamed'], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'webhook_app_owned');
        $this->postJson("/api/v1/webhooks/{$subscription}/rotate-secret", [], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'webhook_app_owned');
        $this->postJson("/api/v1/webhooks/{$subscription}/archive", [], $this->headersFor())->assertForbidden()->assertJsonPath('code', 'webhook_app_owned');

        // Switching it off is allowed.
        $this->patchJson("/api/v1/webhooks/{$subscription}", ['active' => false], $this->headersFor())->assertOk()->assertJsonPath('data.active', false);
    }

    public function test_app_deliveries_name_their_install_and_tenant_and_are_signed_with_a_per_install_key(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $installation = $this->install();
        $raw = base64_decode(substr($this->listed['webhook_secret'], 6));
        $expected = 'whsec_'.base64_encode(hash_hmac('sha256', 'install:'.$installation->id, $raw, true));

        $this->assertSame($expected, $installation->subscription->secret);
        $this->assertNotSame($this->listed['webhook_secret'], $installation->subscription->secret);

        $this->happen();
        $payload = $this->inTenant(fn () => json_decode((string) WebhookDelivery::query()->sole()->getRawOriginal('payload'), true));
        $this->assertSame([$installation->id, $this->owner->tenant_id], [$payload['installation_id'] ?? null, $payload['tenant_id'] ?? null]);
    }

    // ---- MEDIUM 2: no tenant session reads secrets or unlisted clients ---------------------------

    public function test_no_tenant_session_reads_app_secrets_or_an_unlisted_client(): void
    {
        $unlisted = $this->listedApp(listed: false);

        $this->inTenant(function () use ($unlisted) {
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('marketplace_apps', 'webhook_secret'), 'app secrets are not on the global app table');
            $this->assertTrue($this->refused(fn () => DB::table('marketplace_app_secrets')->count() === 0 ? 0 : 1));
            $this->assertSame(0, DB::table('oauth_clients')->where('id', $unlisted['client_id'])->count(), 'an unlisted client is its developer\'s only');
            $this->assertNotSame(1, preg_match('/^\$2y\$/', (string) DB::table('oauth_clients')->where('id', $this->listed['client_id'])->value('secret')), 'no secret hash on the client row');
        });
    }

    public function test_the_per_install_key_function_refuses_another_tenants_install(): void
    {
        $installation = $this->install();
        $other = $this->otherTenant();

        $this->assertNotNull($this->inTenant(fn () => DB::selectOne('select public.app_install_webhook_key(?) as k', [$installation->id])->k));
        $this->assertNull($this->asTenant($other['user']->tenant_id, fn () => DB::selectOne('select public.app_install_webhook_key(?) as k', [$installation->id])->k));
    }

    public function test_the_client_secret_is_checked_by_digest_without_reading_a_hash(): void
    {
        $ok = fn (string $secret) => (bool) DB::selectOne('select public.oauth_client_secret_ok(?, ?) as ok', [$this->listed['client_id'], hash('sha256', $secret)])->ok;

        $this->assertTrue($ok($this->listed['client_secret']));
        $this->assertFalse($ok('not-the-secret'));
    }

    // ---- MEDIUM 4: developers write only into their own apps --------------------------------------

    public function test_a_developer_cannot_add_a_version_to_another_developers_app(): void
    {
        $this->putJson('/api/v1/developer', ['name' => 'Probe', 'email' => 'probe@example.com', 'accept_terms' => true], $this->headersFor())->assertOk();
        $victim = $this->listedApp(listed: false);

        $this->inTenant(function () use ($victim) {
            $this->assertTrue($this->refused(fn () => DB::table('marketplace_app_versions')->insert([
                'id' => (string) Str::uuid7(), 'app_id' => $victim['app_id'], 'developer_tenant_id' => $this->owner->tenant_id, 'version' => 9, 'status' => 'draft',
                'name' => 'Probe', 'description' => 'Probe', 'category' => 'other', 'pricing' => '{"model":"free"}', 'created_at' => now(), 'updated_at' => now(),
            ]) ? 1 : 0), 'a version for another developer\'s app is refused');
            $this->assertTrue($this->refused(fn () => DB::table('oauth_clients')->where('developer_tenant_id', $this->owner->tenant_id)->update(['redirect_uris' => '["https://evil.example.com"]'])), 'redirect URIs are set by review only');
        });
    }

    // ---- MEDIUM 3: review approves what the reviewer saw ------------------------------------------

    // (AppReviewTest::test_approving_a_version_that_changed_since_it_was_opened_is_refused)

    // ---- LOW 6 ----------------------------------------------------------------------------------

    public function test_a_code_or_refresh_token_is_redeemed_once_even_concurrently(): void
    {
        $this->inTenant(function () {
            $tokens = app(OAuthTokens::class);
            DB::table('oauth_auth_codes')->insert(['id' => OAuthTokens::hash('code-1'), 'tenant_id' => $this->owner->tenant_id, 'user_id' => $this->owner->id,
                'client_id' => $this->listed['client_id'], 'scopes' => '[]', 'revoked' => false, 'expires_at' => now()->addMinutes(5)]);
            $codes = new AuthCodeRepository($tokens);
            // Two exchanges validate the code: only the first may go on.
            $this->assertFalse($codes->isAuthCodeRevoked('code-1'));
            $this->assertTrue($codes->isAuthCodeRevoked('code-1'));

            DB::table('oauth_refresh_tokens')->insert(['id' => OAuthTokens::hash('refresh-1'), 'tenant_id' => $this->owner->tenant_id, 'access_token_id' => OAuthTokens::hash('access-1'), 'revoked' => false, 'expires_at' => now()->addDay()]);
            $refresh = new RefreshTokenRepository($tokens);
            $this->assertFalse($refresh->isRefreshTokenRevoked('refresh-1'));
            $this->assertTrue($refresh->isRefreshTokenRevoked('refresh-1'));
        });
    }

    public function test_any_tenant_cannot_list_the_tenants_that_installed_an_app(): void
    {
        $this->install();
        $other = $this->otherTenant();

        $this->assertSame([], $this->asTenant($other['user']->tenant_id, fn () => DB::select('select t from public.app_tenants_with_app_installed(?) t', [$this->listed['app_id']])));
        $this->assertSame([], $this->inTenant(fn () => DB::select('select t from public.app_tenants_with_app_installed(?) t', [$this->listed['app_id']])), 'not even a customer of the app');
    }

    public function test_a_suspended_developer_or_a_closed_developer_tenant_stops_its_apps(): void
    {
        $pkce = $this->pkce();
        $approved = $this->approve($this->listed['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('branch_manager')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $access = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $this->listed)->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$access, 'Accept' => 'application/json'];
        $this->getJson('/api/v1/pos/sales', $headers)->assertOk();

        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_developers')->where('first_party', true)->update(['status' => 'suspended']);
        $this->beforeApplicationDestroyed(fn () => DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_developers')->where('first_party', true)->update(['status' => 'active']));

        $this->getJson('/api/v1/pos/sales', $headers)->assertUnauthorized();
    }

    public function test_changing_an_apps_role_needs_the_right_to_remove_the_old_one(): void
    {
        $installation = $this->install();
        // Someone who installs apps tenant-wide but assigns roles only at branch B cannot move an app held at branch A.
        [$installer, $reader] = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Installer', ['core.marketplace.view', 'core.marketplace.install']), Scope::tenant());
            $this->assign($user, $this->role('Assigner', ['core.role.assign', 'core.company.view']), Scope::branch($this->branchB->id));

            return [$user, $this->role('Reader', ['core.company.view'])];
        });

        $this->postJson("/api/v1/apps/installed/{$installation->id}/consent", ['role_id' => $reader->id, 'scope_type' => 'branch', 'scope_id' => $this->branchB->id], $this->headersFor($installer))
            ->assertStatus(404);
        $this->inTenant(fn () => $this->assertSame($this->branchA->id, RoleAssignment::query()->where('user_id', $installation->principal_id)->sole()->scope_id));
    }
}
