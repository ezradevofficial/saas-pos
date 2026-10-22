<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Api\ApiScopes;
use App\Core\Audit\AuditEntry;
use App\Core\Http\ApiException;
use App\Core\Marketplace\AppSecrets;
use App\Core\Marketplace\Connectors\Courier\Couriers;
use App\Core\Marketplace\Connectors\Courier\FakeCourier;
use App\Core\Marketplace\Connectors\Courier\ShipmentRequest;
use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMarketplace;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

// MKT-01, MKT-04 (ADR 024): the listing, and the first-party connectors
// listed as apps: not configured (and not installable) until the owner
// sets up their connector service; the Power BI and Excel connector reads
// the public lists as CSV feeds within its scopes; the courier interface.
class ConnectorsTest extends TestCase
{
    use BuildsMarketplace, BuildsOrganisation, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpOrganisation();
        // The set-up command writes the connector's client on the platform connection (committed): put it back.
        $this->beforeApplicationDestroyed(fn () => DB::connection(SyncPermissions::OWNER_CONNECTION)->table('oauth_clients')
            ->whereIn('id', array_column(FirstParty::CONNECTORS, 'client'))->update(['revoked' => true, 'secret' => null, 'redirect_uris' => '[]']));
    }

    public function test_the_listing_shows_listed_apps_and_the_connectors_not_configured(): void
    {
        $app = $this->listedApp(['name' => 'Stock sync']);
        $listing = $this->getJson('/api/v1/marketplace/apps', $this->headersFor())->assertOk();
        $byId = collect($listing->json('data'))->keyBy('id');

        $this->assertTrue($byId[$app['app_id']]['configured']);
        foreach (FirstParty::appIds() as $key => $id) {
            if (! FirstParty::CONNECTORS[$key]['available']) {
                continue;
            }
            $this->assertFalse($byId[$id]['configured']);
            $this->assertTrue($byId[$id]['developer']['first_party']);
        }

        $this->getJson('/api/v1/marketplace/apps?search=stock', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/marketplace/apps?category=analytics', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', FirstParty::CONNECTORS['bi']['app']);
        $this->getJson('/api/v1/marketplace/apps/'.FirstParty::CONNECTORS['messaging']['app'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.pricing.model', 'free')->assertJsonPath('data.webhook_events', ['order.status_changed.v1', 'sale.completed.v1']);
        $this->get("/api/v1/marketplace/logos/{$app['version_id']}")->assertNotFound();

        $status = collect($this->getJson('/api/v1/marketplace/connectors', $this->headersFor())->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(['connector_service', 'courier_provider'], $status['courier']['missing']);
        $this->assertSame(['connector_service'], $status['biometric']['missing']);
        $this->assertSame(['connector_service'], $status['bi']['missing']);

        // Not configured: nobody can install it.
        $pkce = $this->pkce();
        $this->getJson('/api/v1/oauth/authorize?'.http_build_query($this->authorizeQuery(FirstParty::CONNECTORS['bi']['client'], $pkce['challenge'], ['redirect_uri' => 'https://bi.example.com/cb'])), $this->headersFor())
            ->assertStatus(400);
    }

    /**
     * MKT-04 (Phase 8 review L6 closed): the courier and biometric connectors have their public
     * routes, so they are listed, shown and can be set up like the others; nothing names the
     * public API as missing.
     */
    public function test_the_courier_and_biometric_connectors_are_listed_shown_and_can_be_set_up(): void
    {
        $listed = array_column($this->getJson('/api/v1/marketplace/apps', $this->headersFor())->assertOk()->json('data'), 'id');
        $status = collect($this->getJson('/api/v1/marketplace/connectors', $this->headersFor())->assertOk()->json('data'))->keyBy('key');

        foreach (['courier', 'biometric'] as $key) {
            $connector = FirstParty::CONNECTORS[$key];
            $this->assertTrue($connector['available']);
            $this->assertFalse(FirstParty::unavailable($connector['app']));
            $this->assertContains($connector['app'], $listed);
            $this->getJson("/api/v1/marketplace/apps/{$connector['app']}", $this->headersFor())->assertOk()->assertJsonPath('data.id', $connector['app']);
            $this->assertTrue($status[$key]['available']);
            $this->assertNotContains('public_api', $status[$key]['missing']);

            config(["marketplace.connectors.{$key}.redirect_uri" => "https://{$key}.example.com/cb"]);
            $this->artisan('marketplace:connector', ['connector' => $key])->assertSuccessful();
            $this->assertFalse((bool) DB::connection(SyncPermissions::OWNER_CONNECTION)->table('oauth_clients')->where('id', $connector['client'])->value('revoked'));
        }
        $this->assertSame([], FirstParty::unavailableAppIds());
    }

    /**
     * Phase 8 review L6: an available connector can do what it asks for. Each of its scopes is
     * served by a public API route (a write route for a write scope), or, for a read scope, is
     * what a webhook event it receives requires. A connector marked not available yet must
     * still lack one, so the flag is removed once its routes exist.
     */
    public function test_every_available_connector_scope_is_served_by_a_public_route(): void
    {
        $served = [];
        foreach (app('router')->getRoutes() as $route) {
            $resource = ApiScopes::resourceOf($route);

            if ($resource !== null) {
                $served["{$resource}.read"] = true;

                if (array_diff($route->methods(), ['GET', 'HEAD']) !== []) {
                    $served["{$resource}.write"] = true;
                }
            }
        }
        $this->assertNotEmpty($served);

        foreach (FirstParty::CONNECTORS as $key => $connector) {
            $byEvent = [];
            foreach ($connector['events'] as $event) {
                $permission = app(WebhookCatalogue::class)->find($event)?->permission;
                if ($permission !== null) {
                    $byEvent[implode('.', array_slice(explode('.', $permission), 0, 2)).'.read'] = true;
                }
            }
            $unserved = array_values(array_filter($connector['scopes'], fn (string $scope) => ! isset($served[$scope]) && ! isset($byEvent[$scope])));

            $connector['available']
                ? $this->assertSame([], $unserved, "the {$key} connector asks for scopes no public route serves")
                : $this->assertNotSame([], $unserved, "every scope of the {$key} connector is served now: make it available");
        }
    }

    public function test_setting_up_the_bi_connector_lets_it_read_csv_feeds_within_its_scopes(): void
    {
        $this->artisan('marketplace:connector', ['connector' => 'bi'])->assertFailed();
        config(['marketplace.connectors.bi.redirect_uri' => 'https://bi.example.com/cb']);

        $this->assertSame(0, Artisan::call('marketplace:connector', ['connector' => 'bi']));
        preg_match('/client_secret: (\S+)/', Artisan::output(), $match);
        $app = ['client_id' => FirstParty::CONNECTORS['bi']['client'], 'client_secret' => $match[1]];

        $status = collect($this->getJson('/api/v1/marketplace/connectors', $this->headersFor())->assertOk()->json('data'))->keyBy('key');
        $this->assertTrue($status['bi']['configured']);

        $pkce = $this->pkce();
        $approved = $this->postJson('/api/v1/oauth/authorize', [
            ...$this->authorizeQuery($app['client_id'], $pkce['challenge'], ['redirect_uri' => 'https://bi.example.com/cb']),
            'decision' => 'approve', 'role_id' => $this->roles->get('read_only_auditor')->id, 'scope_type' => 'tenant',
        ], $this->headersFor())->assertOk();
        $tokens = $this->post('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $app['client_id'], 'client_secret' => $app['client_secret'],
            'redirect_uri' => 'https://bi.example.com/cb', 'code' => self::codeFrom($approved->json('data.redirect_to')), 'code_verifier' => $pkce['verifier'],
        ], ['Accept' => 'application/json'])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$tokens->json('access_token')];

        $feed = $this->get('/api/v1/items?format=csv', $headers)->assertOk();
        $this->assertStringContainsString('text/csv', (string) $feed->headers->get('Content-Type'));
        // Read only: no write scope.
        $this->postJson('/api/v1/items', ['name' => 'Tea'], [...$headers, 'Accept' => 'application/json'])->assertForbidden()->assertJsonPath('code', 'api_scope_missing');

        // Phase 8 review L7: rotating and disabling reach the installing tenant's own audit chain.
        // The platform role lists installs on its own connection, which cannot see this test's uncommitted install.
        $tenant = $this->owner->tenant_id;
        $this->partialMock(AppSecrets::class, fn ($mock) => $mock->shouldReceive('installedTenants')->with(FirstParty::CONNECTORS['bi']['app'])->andReturn([$tenant]));
        $this->artisan('marketplace:connector', ['connector' => 'bi', '--rotate' => true])->assertSuccessful();
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::where('action', 'core.app.connector_rotated')->count()));

        $this->artisan('marketplace:connector', ['connector' => 'bi', '--disable' => true])->assertSuccessful();
        $this->getJson('/api/v1/items', [...$headers, 'Accept' => 'application/json'])->assertUnauthorized();
        $this->inTenant(function () {
            $entry = AuditEntry::where('action', 'core.app.connector_disabled')->sole();
            $this->assertSame('bi', $entry->after['connector'] ?? null);
        });
    }

    public function test_the_courier_interface_is_not_configured_until_a_courier_is_chosen(): void
    {
        $request = new ShipmentRequest('order-1', ['name' => 'Shop', 'phone' => '+254700000000', 'address' => 'Moi Avenue'], ['name' => 'Amani', 'phone' => '+254711111111', 'address' => 'Westlands'], [['description' => 'Box', 'weight_grams' => 500]], 'KES');

        try {
            app(Couriers::class)->driver()->quote($request);
            $this->fail('an unconfigured courier must refuse');
        } catch (ApiException $e) {
            $this->assertSame([503, 'courier_not_configured'], [$e->getStatusCode(), $e->errorCode]);
        }

        $fake = new FakeCourier;
        $shipment = $fake->create($request);
        $this->assertSame($shipment, $fake->create($request), 'booking is idempotent on the reference');
        $this->assertSame('cancelled', $fake->cancel($shipment->providerReference)->status);
        $this->assertSame(FakeCourier::FEE_PER_PARCEL_MINOR, $fake->quote($request)->feeMinor);
    }
}
