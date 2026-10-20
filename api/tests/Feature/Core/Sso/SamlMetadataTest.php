<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Saml\HostAddresses;
use App\Core\Sso\Saml\SamlMetadata;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\Support\Sso\FakeSamlIdp;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023: SAML metadata fetched by URL. The SSRF guard refuses
 * carrier-grade NAT, NAT64 and 6to4 addresses as it refuses private ones;
 * the body is read with a running byte cap; a daily refresh (one server,
 * tenants from a security-definer function) adds a certificate the IdP
 * rotated in, as the second slot, and never follows another entity id.
 */
class SamlMetadataTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase, WithoutOwnerConnection;

    private const URL = 'https://idp.example.com/federationmetadata.xml';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpSso();
        $this->app->instance(HostAddresses::class, new class extends HostAddresses
        {
            public function resolve(string $host): array
            {
                return [
                    'idp.example.com' => ['203.0.113.10'],
                    'cgnat.example.com' => ['100.64.12.1'],
                    'nat64.example.com' => ['64:ff9b::a00:1'],
                    'sixtofour.example.com' => ['2002:a00:1::1'],
                ][$host] ?? [];
            }
        });
    }

    private static function metadata(array $certs, string $entityId = FakeSamlIdp::ENTITY_ID): string
    {
        $keys = '';
        foreach ($certs as $cert) {
            $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $cert);
            $keys .= '<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>'.$body.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>';
        }

        return '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="'.$entityId.'">'
            .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'.$keys
            .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="'.FakeSamlIdp::SSO_URL.'"/>'
            .'</md:IDPSSODescriptor></md:EntityDescriptor>';
    }

    public function test_carrier_grade_nat_nat64_and_6to4_addresses_are_refused(): void
    {
        foreach (['cgnat', 'nat64', 'sixtofour'] as $host) {
            $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'SSRF', 'metadata_url' => "https://{$host}.example.com/metadata"], $this->headersFor())
                ->assertStatus(422)->assertJsonPath('code', 'metadata_unreachable');
        }

        foreach (['100.64.0.1', '100.127.255.254', '64:ff9b::1', '2002::1'] as $ip) {
            $this->assertFalse(SamlMetadata::isPublic($ip), $ip);
        }
        $this->assertTrue(SamlMetadata::isPublic('100.128.0.1'));
    }

    public function test_the_body_is_read_with_a_running_byte_cap(): void
    {
        config(['sso.metadata_max_bytes' => 10_000]);
        $pumped = 0;
        // No Content-Length: only a running count can stop it.
        $body = new PumpStream(function (int $length) use (&$pumped) {
            if ($pumped >= 5_000_000) {
                return false;
            }
            $pumped += $length;

            return str_repeat('x', $length);
        });
        Http::fake([self::URL => fn () => new FulfilledPromise(new PsrResponse(200, [], $body))]);

        $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'Big', 'metadata_url' => self::URL], $this->headersFor())
            ->assertStatus(422)->assertJsonPath('code', 'metadata_unreachable');
        $this->assertLessThan(100_000, $pumped, 'stopped soon after the cap, not at the end of the body');
    }

    public function test_a_daily_refresh_adds_a_rotated_certificate_as_the_second_slot(): void
    {
        $old = FakeSamlIdp::pair()['cert'];
        $new = FakeSamlIdp::otherPair()['cert'];
        $served = self::metadata([$old]);
        Http::fake([self::URL => function () use (&$served) {
            return Http::response($served);
        }]);

        $id = $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'By URL', 'metadata_url' => self::URL], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.settings.metadata_url', self::URL)->json('data.id');
        $this->markTested($id);
        $this->postJson("/api/v1/sso/connections/{$id}/enable", [], $this->headersFor())->assertOk();
        $certs = fn () => $this->inTenant(fn () => SsoConnection::query()->findOrFail($id)->settings['idp_certificates']);
        $this->assertCount(1, $certs());

        // The IdP publishes its next certificate next to the current one.
        $served = self::metadata([$old, $new]);
        $this->withoutOwnerConnection();
        $this->artisan('sso:refresh-metadata')->assertSuccessful();

        $this->assertCount(2, $certs());
        $this->assertStringContainsString(preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $new), preg_replace('/\s+/', '', $certs()[1]));
        $connection = $this->inTenant(fn () => SsoConnection::query()->findOrFail($id));
        $this->assertTrue($connection->enabled, 'a rotation by the same IdP keeps the connection');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'core.sso.metadata_refresh')->count()));

        // Metadata naming another entity is never followed.
        $served = self::metadata([FakeSamlIdp::pair()['cert']], 'https://other-idp.example.com');
        $this->artisan('sso:refresh-metadata')->assertSuccessful();
        $this->assertSame(FakeSamlIdp::ENTITY_ID, $this->inTenant(fn () => SsoConnection::query()->findOrFail($id)->settings['idp_entity_id']));
        $this->inTenant(fn () => $this->assertSame('entity_changed', AuditEntry::query()->where('action', 'core.sso.metadata_refresh_failed')->sole()->after['reason']));
    }
}
