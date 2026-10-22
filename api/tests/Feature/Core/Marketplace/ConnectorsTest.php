<?php

namespace Tests\Feature\Core\Marketplace;

use App\Core\Http\ApiException;
use App\Core\Marketplace\Connectors\Courier\Couriers;
use App\Core\Marketplace\Connectors\Courier\FakeCourier;
use App\Core\Marketplace\Connectors\Courier\ShipmentRequest;
use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Rbac\Console\SyncPermissions;
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
        foreach (FirstParty::appIds() as $id) {
            $this->assertFalse($byId[$id]['configured']);
            $this->assertTrue($byId[$id]['developer']['first_party']);
        }

        $this->getJson('/api/v1/marketplace/apps?search=stock', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/marketplace/apps?category=analytics', $this->headersFor())->assertOk()->assertJsonPath('data.0.id', FirstParty::CONNECTORS['bi']['app']);
        $this->getJson('/api/v1/marketplace/apps/'.FirstParty::CONNECTORS['courier']['app'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.pricing.model', 'free')->assertJsonPath('data.webhook_events', ['order.paid.v1', 'order.status_changed.v1']);
        $this->get("/api/v1/marketplace/logos/{$app['version_id']}")->assertNotFound();

        $status = collect($this->getJson('/api/v1/marketplace/connectors', $this->headersFor())->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(['connector_service', 'courier_provider'], $status['courier']['missing']);
        $this->assertSame(['connector_service'], $status['bi']['missing']);

        // Not configured: nobody can install it.
        $pkce = $this->pkce();
        $this->getJson('/api/v1/oauth/authorize?'.http_build_query($this->authorizeQuery(FirstParty::CONNECTORS['bi']['client'], $pkce['challenge'], ['redirect_uri' => 'https://bi.example.com/cb'])), $this->headersFor())
            ->assertStatus(400);
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

        $this->artisan('marketplace:connector', ['connector' => 'bi', '--disable' => true])->assertSuccessful();
        $this->getJson('/api/v1/items', [...$headers, 'Accept' => 'application/json'])->assertUnauthorized();
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
