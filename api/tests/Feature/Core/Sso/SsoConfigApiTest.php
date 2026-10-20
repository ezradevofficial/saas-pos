<?php

namespace Tests\Feature\Core\Sso;

use App\Core\Audit\AuditEntry;
use App\Core\Rbac\Scope;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoDomain;
use App\Core\Sso\Saml\HostAddresses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSso;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\WithoutOwnerConnection;
use Tests\Support\Sso\FakeOidcIdp;
use Tests\Support\Sso\FakeSamlIdp;
use Tests\TestCase;

/**
 * AUTH-11, ADR 023, RBAC-01, TEN-01, AUD-01: the SSO settings API.
 * `core.sso.manage` (Owner only, never the Admin template), other tenants'
 * rows not found, validation per provider, the secret never shown and
 * stored encrypted, enabling only after a passing test, the test flow,
 * SAML metadata by XML or a guarded URL, email domains by DNS (first
 * verifier wins, daily re-check) and the audit of every change.
 */
class SsoConfigApiTest extends TestCase
{
    use BuildsSso, RefreshTenantDatabase, WithoutOwnerConnection;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpSso();
    }

    private function entraBody(array $overrides = []): array
    {
        return ['provider' => 'entra', 'name' => 'Microsoft', 'client_id' => FakeOidcIdp::CLIENT_ID, 'client_secret' => 'entra-secret-value', 'directory_id' => FakeOidcIdp::DIRECTORY, ...$overrides];
    }

    public function test_only_holders_of_core_sso_manage_reach_the_settings(): void
    {
        $admin = $this->userWith('admin', Scope::tenant());
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        $id = $this->postJson('/api/v1/sso/connections', $this->entraBody(), $this->headersFor())->assertCreated()->json('data.id');
        $domain = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.co.ke'], $this->headersFor())->assertCreated()->json('data.id');

        foreach ([$admin, $manager] as $user) {
            $headers = $this->headersFor($user);
            $this->getJson('/api/v1/sso', $headers)->assertForbidden();
            $this->putJson('/api/v1/sso/settings', ['enforce' => false], $headers)->assertForbidden();
            $this->postJson('/api/v1/sso/connections', $this->entraBody(), $headers)->assertForbidden();
            $this->patchJson("/api/v1/sso/connections/{$id}", ['name' => 'Hijacked'], $headers)->assertForbidden();
            foreach (['enable', 'disable', 'archive', 'test'] as $action) {
                $this->postJson("/api/v1/sso/connections/{$id}/{$action}", [], $headers)->assertForbidden();
            }
            $this->postJson('/api/v1/sso/domains', ['domain' => 'evil.co.ke'], $headers)->assertForbidden();
            $this->postJson("/api/v1/sso/domains/{$domain}/check", [], $headers)->assertForbidden();
            $this->postJson("/api/v1/sso/domains/{$domain}/archive", [], $headers)->assertForbidden();
        }

        $this->getJson('/api/v1/sso')->assertUnauthorized();
        $this->getJson('/api/v1/sso', $this->headersFor())->assertOk()->assertJsonPath('data.connections.0.name', 'Microsoft');
    }

    public function test_another_tenants_connections_and_domains_are_not_found(): void
    {
        $id = $this->postJson('/api/v1/sso/connections', $this->entraBody(), $this->headersFor())->assertCreated()->json('data.id');
        $domain = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.co.ke'], $this->headersFor())->assertCreated()->json('data.id');

        $other = $this->otherTenant();
        $headers = $this->bearer($this->tokenFor($other['user']));
        $this->getJson('/api/v1/sso', $headers)->assertOk()->assertJsonPath('data.connections', [])->assertJsonPath('data.domains', []);
        $this->patchJson("/api/v1/sso/connections/{$id}", ['name' => 'Hijacked'], $headers)->assertNotFound();
        foreach (['enable', 'disable', 'archive', 'test'] as $action) {
            $this->postJson("/api/v1/sso/connections/{$id}/{$action}", [], $headers)->assertNotFound();
        }
        $this->postJson("/api/v1/sso/domains/{$domain}/check", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/sso/domains/{$domain}/archive", [], $headers)->assertNotFound();

        $this->inTenant(fn () => $this->assertSame('Microsoft', SsoConnection::query()->findOrFail($id)->name));
        $this->inTenant(fn () => $this->assertNull(SsoDomain::query()->findOrFail($domain)->archived_at));
    }

    public function test_connections_are_validated_per_provider_and_the_secret_is_never_shown(): void
    {
        $headers = $this->headersFor();
        $this->postJson('/api/v1/sso/connections', $this->entraBody(['directory_id' => 'common']), $headers)->assertStatus(422)->assertJsonValidationErrors('directory_id');
        $this->postJson('/api/v1/sso/connections', $this->entraBody(['client_secret' => null]), $headers)->assertStatus(422)->assertJsonValidationErrors('client_secret');
        $this->postJson('/api/v1/sso/connections', $this->entraBody(['provider' => 'okta']), $headers)->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->postJson('/api/v1/sso/connections', ['provider' => 'google', 'name' => 'G', 'client_id' => 'x', 'client_secret' => 'y', 'hosted_domain' => 'not a domain'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('hosted_domain');
        $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'S', 'idp_entity_id' => 'x', 'idp_sso_url' => 'http://insecure.example.com', 'idp_certificates' => ['not a certificate'], 'client_secret' => 'x'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['idp_sso_url', 'idp_certificates.0', 'client_secret']);

        $created = $this->postJson('/api/v1/sso/connections', $this->entraBody(), $headers)->assertCreated()->json('data');
        $this->assertSame(['client_id' => FakeOidcIdp::CLIENT_ID, 'directory_id' => FakeOidcIdp::DIRECTORY], $created['settings']);
        $this->assertTrue($created['has_secret']);
        $this->assertFalse($created['enabled']);
        $this->assertSame('https://api.example.test/api/v1/auth/sso/oidc/callback', $created['sp']['redirect_uri']);
        $this->assertStringNotContainsString('entra-secret-value', json_encode($this->getJson('/api/v1/sso', $headers)->json()));

        // Encrypted at rest; the audit says only that a secret was set.
        $raw = $this->inTenant(fn () => DB::table('sso_connections')->where('id', $created['id'])->value('client_secret'));
        $this->assertNotSame('entra-secret-value', $raw);
        $this->assertSame('entra-secret-value', decrypt($raw, false));
        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.sso.connection_create')->sole());
        $this->assertTrue($audit->after['secret_set']);
        $this->assertStringNotContainsString('entra-secret-value', json_encode($audit->after));
    }

    public function test_enabling_needs_a_passing_test_and_editing_clears_it(): void
    {
        $headers = $this->headersFor();
        $id = $this->postJson('/api/v1/sso/connections', $this->entraBody(), $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/sso/connections/{$id}/enable", [], $headers)->assertStatus(409)->assertJsonPath('code', 'connection_untested');

        // The test flow: the Owner signs in at the IdP, the result is stored, nobody is signed in.
        $this->verifyDomain('acme.co.ke');
        $this->inTenant(fn () => $this->owner->forceFill(['email' => 'owner@acme.co.ke'])->save());
        $redirect = $this->postJson("/api/v1/sso/connections/{$id}/test", ['origin' => 'https://app.example.test'], $headers)->assertOk()->json('data.redirect_url');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('owner@acme.co.ke', $query['nonce'], ['amr' => ['mfa']]));
        $this->assertSame('https://app.example.test/settings/sso#test=ok', $this->oidcReturn($query['state']));

        $connection = $this->getJson('/api/v1/sso', $headers)->json('data.connections.0');
        $this->assertTrue($connection['last_test']['ok']);
        // The tester is an Owner: never linked by email, they link from Settings, Security (ADR 023).
        $this->assertSame(['owner@acme.co.ke', true, 'owner_link', $this->owner->id], [$connection['last_test']['email'], $connection['last_test']['mfa'], $connection['last_test']['action'], $connection['last_test']['user_id']]);
        $this->inTenant(fn () => $this->assertSame(0, DB::table('sso_identities')->count()));

        $this->postJson("/api/v1/sso/connections/{$id}/enable", [], $headers)->assertOk()->assertJsonPath('data.enabled', true);

        // A new secret clears the test, but the connection stays enabled.
        $this->patchJson("/api/v1/sso/connections/{$id}", ['client_secret' => 'rotated-secret'], $headers)->assertOk()
            ->assertJsonPath('data.tested_at', null)->assertJsonPath('data.enabled', true);
        $this->patchJson("/api/v1/sso/connections/{$id}", ['provider' => 'google'], $headers)->assertStatus(422)->assertJsonValidationErrors('provider');

        // A failed test is recorded too.
        $redirect = $this->postJson("/api/v1/sso/connections/{$id}/test", [], $headers)->json('data.redirect_url');
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->idp->idToken = FakeOidcIdp::sign(FakeOidcIdp::entraClaims('owner@acme.co.ke', 'wrong-nonce'));
        $this->assertSame('https://app.example.test/settings/sso#test=failed', $this->oidcReturn($query['state']));
        $this->assertSame(['ok' => false, 'reason' => 'nonce_mismatch'], array_intersect_key($this->getJson('/api/v1/sso', $headers)->json('data.connections.0.last_test'), ['ok' => 1, 'reason' => 1]));

        $actions = $this->inTenant(fn () => AuditEntry::query()->where('auditable_id', $id)->orderBy('seq')->pluck('action')->all());
        $this->assertSame(['core.sso.connection_create', 'core.sso.connection_test', 'core.sso.connection_enable', 'core.sso.connection_update', 'core.sso.connection_test'], $actions);
    }

    public function test_saml_settings_come_from_pasted_metadata_or_a_guarded_url(): void
    {
        $cert = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', FakeSamlIdp::pair()['cert']);
        $metadata = '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" entityID="'.FakeSamlIdp::ENTITY_ID.'">'
            .'<md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">'
            .'<md:KeyDescriptor use="signing"><ds:KeyInfo><ds:X509Data><ds:X509Certificate>'.$cert.'</ds:X509Certificate></ds:X509Data></ds:KeyInfo></md:KeyDescriptor>'
            .'<md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="'.FakeSamlIdp::SSO_URL.'"/>'
            .'</md:IDPSSODescriptor></md:EntityDescriptor>';
        $headers = $this->headersFor();

        $created = $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'From XML', 'metadata_xml' => $metadata], $headers)->assertCreated()->json('data');
        $this->assertSame([FakeSamlIdp::ENTITY_ID, FakeSamlIdp::SSO_URL], [$created['settings']['idp_entity_id'], $created['settings']['idp_sso_url']]);
        $this->assertCount(1, $created['settings']['idp_certificates']);
        $this->assertSame("https://api.example.test/api/v1/auth/sso/saml/{$created['id']}", $created['sp']['entity_id']);
        $this->assertSame("https://api.example.test/api/v1/auth/sso/saml/{$created['id']}/acs", $created['sp']['acs_url']);

        $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'Bad', 'metadata_xml' => '<nope/>'], $headers)->assertStatus(422)->assertJsonPath('code', 'metadata_invalid');

        // By URL: https only, public addresses only, pinned; the IdP is faked.
        $addresses = new class extends HostAddresses
        {
            public array $map = ['idp.example.com' => ['203.0.113.10'], 'internal.example.com' => ['10.0.0.5'], 'mixed.example.com' => ['203.0.113.11', '127.0.0.1']];

            public function resolve(string $host): array
            {
                return $this->map[$host] ?? [];
            }
        };
        $this->app->instance(HostAddresses::class, $addresses);
        Http::fake(['https://idp.example.com/metadata' => Http::response($metadata)]);

        $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'From URL', 'metadata_url' => 'https://idp.example.com/metadata'], $headers)->assertCreated()
            ->assertJsonPath('data.settings.idp_entity_id', FakeSamlIdp::ENTITY_ID);
        foreach (['https://internal.example.com/metadata', 'https://mixed.example.com/metadata', 'https://unknown.example.com/metadata', 'https://169.254.169.254/latest'] as $url) {
            $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'SSRF', 'metadata_url' => $url], $headers)->assertStatus(422)->assertJsonPath('code', 'metadata_unreachable');
        }
        $this->postJson('/api/v1/sso/connections', ['provider' => 'saml', 'name' => 'Plain', 'metadata_url' => 'http://idp.example.com/metadata'], $headers)->assertStatus(422)->assertJsonValidationErrors('metadata_url');
        Http::assertSentCount(1);
    }

    public function test_email_domains_are_proven_by_dns_and_rechecked_daily(): void
    {
        $headers = $this->headersFor();
        $this->postJson('/api/v1/sso/domains', ['domain' => 'localhost'], $headers)->assertStatus(422)->assertJsonValidationErrors('domain');
        config(['branding.base_domain' => 'example.app']);
        $this->postJson('/api/v1/sso/domains', ['domain' => 'tenant.example.app'], $headers)->assertStatus(422)->assertJsonValidationErrors('domain');

        // An address is accepted for its domain.
        $domain = $this->postJson('/api/v1/sso/domains', ['domain' => 'Someone@Acme.co.ke'], $headers)->assertCreated()->json('data');
        $this->assertSame(['acme.co.ke', 'pending', '_platform-verify.acme.co.ke'], [$domain['domain'], $domain['status'], $domain['record']['name']]);
        $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.co.ke'], $headers)->assertStatus(422)->assertJsonPath('code', 'domain_added');

        // A second tenant claims it too; neither is verified yet.
        $other = $this->otherTenant();
        $this->extraTenants[] = $other['user']->tenant_id;
        $headersB = $this->bearer($this->tokenFor($other['user']));
        $claimB = $this->postJson('/api/v1/sso/domains', ['domain' => 'acme.co.ke'], $headersB)->assertCreated()->json('data');

        // The scheduled check (no owner connection) proves A's record.
        $this->dns->publish($domain['record']['name'], $domain['record']['value']);
        $this->dns->publish($claimB['record']['name'], $claimB['record']['value']);
        $this->withoutOwnerConnection();
        $this->artisan('domains:verify')->assertSuccessful();

        $statuses = [
            $this->getJson('/api/v1/sso', $headers)->json('data.domains.0.status'),
            $this->getJson('/api/v1/sso', $headersB)->json('data.domains.0.status'),
        ];
        sort($statuses);
        $this->assertSame(['failed', 'verified'], $statuses, 'the first proof wins; the other fails as claimed elsewhere');
        $winner = $this->getJson('/api/v1/sso', $headers)->json('data.domains.0.status') === 'verified' ? $headers : $headersB;
        $loser = $winner === $headers ? $headersB : $headers;
        $this->assertSame('claimed_elsewhere', $this->getJson('/api/v1/sso', $loser)->json('data.domains.0.failure'));

        // The record goes away: three daily misses drop the domain, and discovery stops.
        $this->dns->records = [];
        foreach ([1, 2, 3] as $day) {
            $this->travel(21)->hours();
            $this->artisan('domains:verify')->assertSuccessful();
        }
        foreach ([$this->owner->tenant_id, $other['user']->tenant_id] as $tenantId) {
            $this->asTenant($tenantId, fn () => $this->assertSame(0, SsoDomain::query()->where('status', SsoDomain::VERIFIED)->count()));
        }
        $this->postJson('/api/v1/auth/sso/start', ['email' => 'someone@acme.co.ke'])->assertNotFound();
        $this->inTenant(fn () => $this->assertContains('core.sso.domain_add', AuditEntry::query()->pluck('action')->all()));
    }

    public function test_settings_changes_are_audited(): void
    {
        $this->putJson('/api/v1/sso/settings', ['jit_role_id' => $this->roles->get('cashier')->id, 'jit_scope_type' => 'location', 'jit_scope_id' => $this->locationA->id], $this->headersFor())
            ->assertOk()->assertJsonPath('data.settings.jit_scope_type', 'location')->assertJsonMissingPath('data.settings.jit_set_by');
        $this->putJson('/api/v1/sso/settings', ['jit_role_id' => $this->roles->get('cashier')->id, 'jit_scope_type' => 'location', 'jit_scope_id' => '00000000-0000-0000-0000-000000000000'], $this->headersFor())
            ->assertNotFound();

        $entry = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.sso.settings_update')->sole());
        $this->assertSame([null, $this->roles->get('cashier')->id], [$entry->before['jit_role_id'], $entry->after['jit_role_id']]);
        $this->assertSame($this->owner->id, $entry->after['jit_set_by']);
    }
}
