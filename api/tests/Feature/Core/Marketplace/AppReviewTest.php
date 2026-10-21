<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Audit\AuditEntry;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

// ADM-05 (ADR 024): staff review submitted versions (approve with the
// approved redirect URIs, reject with a reason), suspend and reinstate
// apps; only approved versions are listed; a scope change waits for each
// tenant's consent; every decision is audited in the platform chain and,
// for a suspension, in each installing tenant's chain.
class AppReviewTest extends TestCase
{
    use BuildsMarketplace, BuildsOrganisation, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
    }

    private function reviewer(): array
    {
        return $this->staffBearer($this->staff(PlatformUser::ROLE_COMPLIANCE));
    }

    public function test_approving_a_submitted_version_lists_it(): void
    {
        $app = $this->listedApp(listed: false);
        $this->getJson("/api/v1/marketplace/apps/{$app['app_id']}", $this->headersFor())->assertNotFound();

        $queue = $this->getJson('/api/v1/platform/marketplace/apps', $this->reviewer())->assertOk();
        $this->assertContains($app['app_id'], array_column($queue->json('data'), 'id'));
        $this->getJson("/api/v1/platform/marketplace/apps/{$app['app_id']}", $this->reviewer())->assertOk()
            ->assertJsonPath('data.changes.scopes.added', ['core.item.read', 'core.party.write']);

        $this->postJson("/api/v1/platform/marketplace/versions/{$app['version_id']}/approve", ['note' => 'Reviewed'], $this->reviewer())->assertOk()
            ->assertJsonPath('data.listed_version', 1);

        $this->getJson("/api/v1/marketplace/apps/{$app['app_id']}", $this->headersFor())->assertOk()->assertJsonPath('data.name', 'Stock sync');
        $this->assertSame(1, PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.marketplace.approve')->count());
        $this->postJson("/api/v1/platform/marketplace/versions/{$app['version_id']}/approve", [], $this->reviewer())->assertStatus(409);
    }

    public function test_rejecting_needs_a_reason_and_keeps_the_app_unlisted(): void
    {
        $app = $this->listedApp(listed: false);

        $this->postJson("/api/v1/platform/marketplace/versions/{$app['version_id']}/reject", ['reason' => 'short'], $this->reviewer())->assertUnprocessable();
        $this->postJson("/api/v1/platform/marketplace/versions/{$app['version_id']}/reject", ['reason' => 'Asks for more data than it uses.'], $this->reviewer())->assertOk()
            ->assertJsonPath('data.latest_status', 'rejected');
        $this->getJson("/api/v1/marketplace/apps/{$app['app_id']}", $this->headersFor())->assertNotFound();
    }

    public function test_a_new_version_with_more_scopes_waits_for_the_tenants_consent(): void
    {
        $app = $this->listedApp();
        $pkce = $this->pkce();
        $installation = $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk()->json('data.installation_id');

        // The developer submitted version 2 asking for stock too; staff approve it.
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $v2 = (string) Str::uuid7();
        $row = (array) $owner->table('marketplace_app_versions')->where('id', $app['version_id'])->first();
        $owner->table('marketplace_app_versions')->insert([...$row, 'id' => $v2, 'version' => 2, 'status' => 'draft', 'scopes' => json_encode(['core.item.read', 'core.party.write', 'inventory.stock.read'])]);
        $owner->table('marketplace_app_versions')->where('id', $v2)->update(['status' => 'submitted']);
        $this->getJson("/api/v1/platform/marketplace/apps/{$app['app_id']}", $this->reviewer())->assertOk()
            ->assertJsonPath('data.changes.scopes.added', ['inventory.stock.read'])->assertJsonPath('data.changes.needs_consent', true);
        $this->postJson("/api/v1/platform/marketplace/versions/{$v2}/approve", [], $this->reviewer())->assertOk();

        // The install keeps its consented scopes until the tenant consents again.
        $shown = $this->getJson("/api/v1/apps/installed/{$installation}", $this->headersFor())->assertOk()
            ->assertJsonPath('data.needs_consent', true)->assertJsonPath('data.version', 1);
        $this->assertSame(['inventory.stock.read'], array_column($shown->json('data.new_scopes'), 'scope'));
        $this->inTenant(fn () => $this->assertNotContains('inventory.stock.read', AppInstallation::findOrFail($installation)->apiKey->scopeList()));

        $this->postJson("/api/v1/apps/installed/{$installation}/consent", ['role_id' => $this->roles->get('admin')->id, 'scope_type' => 'tenant'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.needs_consent', false)->assertJsonPath('data.version', 2);
        $this->inTenant(function () use ($installation) {
            $this->assertContains('inventory.stock.read', AppInstallation::findOrFail($installation)->apiKey->scopeList());
            $this->assertSame(1, AuditEntry::where('action', 'core.app.consent')->count());
        });
    }

    public function test_suspending_stops_the_app_and_tells_each_installing_tenant(): void
    {
        $app = $this->listedApp();
        $pkce = $this->pkce();
        $approved = $this->approve($app['client_id'], $pkce['challenge'], $this->headersFor(), ['role' => $this->roles->get('admin')->id, 'scope_type' => 'tenant', 'scope_id' => null])->assertOk();
        $access = $this->exchange(self::codeFrom($approved->json('data.redirect_to')), $pkce['verifier'], $app)->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$access, 'Accept' => 'application/json'];

        $this->postJson("/api/v1/platform/marketplace/apps/{$app['app_id']}/suspend", ['reason' => 'Sends data to an unknown host.'], $this->reviewer())->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->getJson('/api/v1/items', $headers)->assertUnauthorized();
        $this->getJson('/api/v1/marketplace/apps', $this->headersFor())->assertOk()->assertJsonMissing(['id' => $app['app_id']]);
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.app.suspended')->count()));

        $this->postJson("/api/v1/platform/marketplace/apps/{$app['app_id']}/reinstate", ['reason' => 'The developer fixed the host.'], $this->reviewer())->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/items', $headers)->assertOk();
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.app.reinstated')->count()));
        $this->assertSame(2, PlatformDatabase::connection()->table('platform_audit_logs')->whereIn('action', ['platform.marketplace.suspend', 'platform.marketplace.reinstate'])->count());
    }

    public function test_only_reviewers_decide_and_tenant_tokens_are_refused(): void
    {
        $app = $this->listedApp(listed: false);
        $support = $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT));

        $this->getJson('/api/v1/platform/marketplace/apps', $support)->assertOk();
        $this->postJson("/api/v1/platform/marketplace/versions/{$app['version_id']}/approve", [], $support)->assertForbidden();
        $this->postJson("/api/v1/platform/marketplace/apps/{$app['app_id']}/suspend", ['reason' => 'Not allowed for support.'], $support)->assertForbidden();
        $this->getJson('/api/v1/platform/marketplace/apps', $this->staffBearer($this->staff(PlatformUser::ROLE_SALES)))->assertForbidden();
        $this->getJson('/api/v1/platform/marketplace/apps', $this->headersFor())->assertUnauthorized();
    }
}
