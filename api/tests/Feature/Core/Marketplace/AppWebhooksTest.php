<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Marketplace\AppSecrets;
use App\Core\Marketplace\Jobs\RollAppWebhookSecret;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\WebhookSubscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsWebhooks;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// MKT-02, HOOK-01 (ADR 021, ADR 024): an app's consented webhook events
// reach it through a subscription owned by its install's key: to its URL
// with the install's id, signed with the app's secret, filtered to the
// scope its role is held at, and stopped on uninstall or suspension.
class AppWebhooksTest extends TestCase
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

    public function test_events_reach_the_app_signed_with_its_secret_at_its_scope_only(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $installation = $this->install();
        $subscription = $installation->subscription;

        $this->assertSame([WebhookSubscriptions::KEY_PRINCIPAL, $installation->api_key_id, $this->branchA->id], [$subscription->principal_type, $subscription->principal_id, $subscription->branch_id]);
        $this->assertSame(AppSecrets::deriveInstallKey($this->listed['webhook_secret'], $installation->id), $subscription->secret);

        $this->happen();
        $this->happen(['branchId' => $this->branchB->id, 'locationId' => $this->locationB->id, 'thingId' => 'thing-b']);

        $this->assertCount(1, $this->deliveries());
        Http::assertSent(function (HttpRequest $request) use ($installation) {
            $expected = WebhookSignature::sign(AppSecrets::deriveInstallKey($this->listed['webhook_secret'], $installation->id), $request->header('Webhook-Id')[0], (int) $request->header('Webhook-Timestamp')[0], $request->body());

            return $request->url() === 'https://hooks.example.com/app?installation='.$installation->id
                && str_contains($request->header('Webhook-Signature')[0], $expected);
        });
    }

    public function test_uninstalling_or_suspending_stops_the_events(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $installation = $this->install();

        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_apps')->where('id', $this->listed['app_id'])->update(['status' => 'suspended']);
        $this->happen();
        $this->assertCount(0, $this->deliveries());
        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_apps')->where('id', $this->listed['app_id'])->update(['status' => 'active']);

        $this->postJson("/api/v1/apps/installed/{$installation->id}/uninstall", [], $this->headersFor())->assertOk();
        $subscription = $this->subscription($installation->webhook_subscription_id);
        $this->assertFalse($subscription->active);
        $this->assertSame('uninstalled', $subscription->disabled_reason);
        $this->happen();
        $this->assertCount(0, $this->deliveries());
    }

    public function test_a_rolled_app_secret_reaches_the_install_with_an_overlap(): void
    {
        $installation = $this->install();
        $new = AppSecrets::newWebhookKey();
        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_app_secrets')->where('app_id', $this->listed['app_id'])->update(['webhook_key' => DB::raw("decode('".bin2hex($new['raw'])."', 'hex')")]);

        RollAppWebhookSecret::dispatchSync($this->owner->tenant_id, $this->listed['app_id'], now()->addDay()->toIso8601String());

        $subscription = $this->subscription($installation->webhook_subscription_id);
        $old = AppSecrets::deriveInstallKey($this->listed['webhook_secret'], $installation->id);
        $this->assertSame([AppSecrets::deriveInstallKey($new['display'], $installation->id), $old], [$subscription->secret, $subscription->previous_secret]);
    }

    /** Security review 6: a leaked secret can be ended at once, with no overlap. */
    public function test_a_rolled_app_secret_can_end_the_previous_key_at_once(): void
    {
        $installation = $this->install();
        $new = AppSecrets::newWebhookKey();
        DB::connection(SyncPermissions::OWNER_CONNECTION)->table('marketplace_app_secrets')->where('app_id', $this->listed['app_id'])->update(['webhook_key' => DB::raw("decode('".bin2hex($new['raw'])."', 'hex')")]);

        RollAppWebhookSecret::dispatchSync($this->owner->tenant_id, $this->listed['app_id'], null);

        $subscription = $this->subscription($installation->webhook_subscription_id);
        $this->assertSame([AppSecrets::deriveInstallKey($new['display'], $installation->id), null, null], [$subscription->secret, $subscription->previous_secret, $subscription->previous_secret_expires_at]);
    }

    public function test_people_cannot_repoint_an_apps_subscription(): void
    {
        $installation = $this->install();

        $this->patchJson("/api/v1/webhooks/{$installation->webhook_subscription_id}", ['url' => 'https://other.example.com/steal'], $this->headersFor())
            ->assertForbidden()->assertJsonPath('code', 'webhook_not_owner');
    }
}
