<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Audit\AuditEntry;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MKT-01 (ADR 024): a tenant registers as a developer and manages apps:
// secrets shown once, drafts edited and submitted for review, reviewed
// versions fixed, other tenants never reaching them, review fields out of
// the developer's reach.
class DeveloperAppsTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        Storage::fake('local');
    }

    private function register(): void
    {
        $this->putJson('/api/v1/developer', ['name' => 'Acme Labs', 'email' => 'dev@acme.example', 'accept_terms' => true], $this->headersFor())->assertOk();
    }

    /** @return array<string, mixed> */
    private function appBody(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'acme-sync',
            'name' => 'Acme sync',
            'tagline' => 'Sync items',
            'description' => 'Keeps your items and customers in step with the Acme warehouse system, every hour.',
            'category' => 'productivity',
            'support_email' => 'help@acme.example',
            'redirect_uris' => ['https://sync.acme.example/callback'],
            'scopes' => ['core.item.read'],
            'webhook_url' => 'https://sync.acme.example/hooks',
            'webhook_events' => ['sale.completed.v1'],
            'pricing' => ['model' => 'paid', 'monthly' => ['KES' => 50000, 'USD' => 500]],
        ], $overrides);
    }

    public function test_registering_needs_the_terms_and_the_catalogue_is_offered(): void
    {
        $this->putJson('/api/v1/developer', ['name' => 'Acme Labs', 'email' => 'dev@acme.example'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
        $this->getJson('/api/v1/developer', $this->headersFor())->assertOk()->assertJsonPath('data.developer', null)
            ->assertJsonPath('data.catalogue.share_percent', 80);

        $this->register();
        $this->getJson('/api/v1/developer', $this->headersFor())->assertOk()->assertJsonPath('data.developer.name', 'Acme Labs');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.developer.register')->count()));
    }

    public function test_creating_an_app_returns_its_secrets_once(): void
    {
        $this->postJson('/api/v1/developer/apps', $this->appBody(), $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'developer_not_registered');
        $this->register();

        $created = $this->postJson('/api/v1/developer/apps', $this->appBody(), $this->headersFor())->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
        $id = $created->json('data.id');
        $secret = $created->json('secrets.client_secret');
        $this->assertStringStartsWith('whsec_', $created->json('secrets.webhook_secret'));
        $created->assertJsonPath('data.versions.0.status', 'draft')->assertJsonPath('data.versions.0.pricing.monthly.KES', 50000);

        $show = $this->getJson("/api/v1/developer/apps/{$id}", $this->headersFor())->assertOk();
        $this->assertArrayNotHasKey('secrets', $show->json());
        $this->assertStringNotContainsString($secret, $show->getContent());

        $this->inTenant(function () use ($id, $secret) {
            $client = OAuthClient::query()->where('app_id', $id)->sole();
            $this->assertTrue(Hash::check($secret, $client->secret));
            $this->assertNotSame($secret, DB::table('oauth_clients')->where('id', $client->id)->value('secret'));
        });

        $this->postJson('/api/v1/developer/apps', $this->appBody(), $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'slug_taken');
    }

    public function test_versions_are_validated_submitted_withdrawn_and_fixed_once_reviewed(): void
    {
        $this->register();
        $this->postJson('/api/v1/developer/apps', $this->appBody(['redirect_uris' => ['http://sync.acme.example/cb', 'https://ok.example/#frag'], 'webhook_url' => 'http://10.0.0.1/x', 'pricing' => ['model' => 'paid', 'monthly' => ['EUR' => 1]]]), $this->headersFor())
            ->assertUnprocessable()->assertJsonValidationErrors(['redirect_uris.0', 'redirect_uris.1', 'webhook_url', 'pricing.monthly.EUR']);

        $id = $this->postJson('/api/v1/developer/apps', $this->appBody(['description' => 'Too short']), $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/developer/apps/{$id}/submit", [], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'version_incomplete')->assertJsonValidationErrors('description');

        $this->patchJson("/api/v1/developer/apps/{$id}/draft", ['description' => $this->appBody()['description'], 'scopes' => ['core.item.read', 'core.party.read']], $this->headersFor())->assertOk()
            ->assertJsonPath('data.versions.0.scopes', ['core.item.read', 'core.party.read']);
        $this->postJson("/api/v1/developer/apps/{$id}/logo", ['logo' => UploadedFile::fake()->image('logo.png', 256, 256)], $this->headersFor())->assertOk()->assertJsonPath('data.versions.0.has_logo', true);
        $this->get("/api/v1/developer/apps/{$id}/logo", $this->headersFor())->assertOk();

        $this->postJson("/api/v1/developer/apps/{$id}/submit", [], $this->headersFor())->assertOk()->assertJsonPath('data.versions.0.status', 'submitted');
        $this->patchJson("/api/v1/developer/apps/{$id}/draft", ['name' => 'Changed'], $this->headersFor())->assertStatus(409);
        $this->postJson("/api/v1/developer/apps/{$id}/versions", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'version_open');
        $this->postJson("/api/v1/developer/apps/{$id}/withdraw", [], $this->headersFor())->assertOk()->assertJsonPath('data.versions.0.status', 'draft');
        $this->postJson("/api/v1/developer/apps/{$id}/submit", [], $this->headersFor())->assertOk();

        $this->inTenant(function () use ($id) {
            $this->assertSame(['core.developer_app.create', 'core.developer_app.logo', 'core.developer_app.submit', 'core.developer_app.version_update', 'core.developer_app.withdraw'],
                AuditEntry::where('action', 'like', 'core.developer_app.%')->distinct()->orderBy('action')->pluck('action')->all());
            $version = MarketplaceAppVersion::where('app_id', $id)->sole();

            // The developer can never approve or list its own app (policies and column grants).
            $this->assertRefused(fn () => DB::table('marketplace_app_versions')->where('id', $version->id)->update(['status' => 'approved']));
            $this->assertRefused(fn () => DB::table('marketplace_app_versions')->where('id', $version->id)->update(['reviewed_by' => $version->id]));
            $this->assertRefused(fn () => DB::table('marketplace_apps')->where('id', $id)->update(['listed_version_id' => $version->id]));
        });
    }

    public function test_rotating_secrets_and_archiving(): void
    {
        $this->register();
        $created = $this->postJson('/api/v1/developer/apps', $this->appBody(), $this->headersFor())->assertCreated();
        $id = $created->json('data.id');

        $rotated = $this->postJson("/api/v1/developer/apps/{$id}/client-secret", [], $this->headersFor())->assertOk();
        $this->assertNotSame($created->json('secrets.client_secret'), $rotated->json('secrets.client_secret'));
        $webhook = $this->postJson("/api/v1/developer/apps/{$id}/webhook-secret", [], $this->headersFor())->assertOk()->json('secrets.webhook_secret');
        $this->assertNotSame($created->json('secrets.webhook_secret'), $webhook);

        $this->postJson("/api/v1/developer/apps/{$id}/archive", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'archived');
        $this->postJson("/api/v1/developer/apps/{$id}/restore", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_other_tenants_and_people_without_the_permission_never_reach_an_app(): void
    {
        $this->register();
        $id = $this->postJson('/api/v1/developer/apps', $this->appBody(), $this->headersFor())->assertCreated()->json('data.id');
        $other = $this->otherTenant();

        foreach (['GET' => "developer/apps/{$id}", 'PATCH' => "developer/apps/{$id}/draft"] as $method => $uri) {
            $this->json($method, "/api/v1/{$uri}", [], $this->headersFor($other['user']))->assertNotFound();
        }
        $this->postJson("/api/v1/developer/apps/{$id}/client-secret", [], $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/developer/apps', $this->headersFor($other['user']))->assertOk()->assertJsonCount(0, 'data');
        // An unlisted app is not in the marketplace, not even its page.
        $this->getJson("/api/v1/marketplace/apps/{$id}", $this->headersFor($other['user']))->assertNotFound();
        $this->asTenant($other['user']->tenant_id, fn () => $this->assertSame(0, MarketplaceApp::whereKey($id)->count()));

        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        $this->getJson('/api/v1/developer/apps', $this->headersFor($cashier))->assertForbidden();
        $this->postJson('/api/v1/developer/apps', $this->appBody(['slug' => 'other-slug']), $this->headersFor($cashier))->assertForbidden();
        $this->putJson('/api/v1/developer', ['name' => 'X', 'email' => 'x@example.com', 'accept_terms' => true], $this->headersFor($cashier))->assertForbidden();
    }

    private function assertRefused(callable $write): void
    {
        DB::beginTransaction();

        try {
            $changed = $write();
            $this->assertSame(0, $changed, 'the write should change nothing');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        } finally {
            DB::rollBack();
        }
    }
}
