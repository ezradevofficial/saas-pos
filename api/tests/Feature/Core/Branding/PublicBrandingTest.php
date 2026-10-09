<?php

namespace Tests\Feature\Core\Branding;

use App\Core\Audit\AuditEntry;
use App\Core\Branding\BrandingServiceProvider;
use App\Core\Branding\Domains\DnsResolver;
use App\Core\Branding\PublicBranding;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsOrganisation;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Branding\FakeDnsResolver;
use Tests\TestCase;

/**
 * BR-04, BR-05, BR-07, TEN-01: the branded sign-in page. A host is looked
 * up before anyone signs in, by subdomain or verified custom domain,
 * through a security-definer function that returns only public fields;
 * "Powered by" is hidden only by the platform.
 */
class PublicBrandingTest extends TestCase
{
    use BuildsOrganisation, RefreshTenantDatabase;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['branding.base_domain' => 'example.app']);
        $this->dns = new FakeDnsResolver;
        $this->app->instance(DnsResolver::class, $this->dns);
        $this->setUpOrganisation();
    }

    private function branding(string $host): TestResponse
    {
        app(TenantContext::class)->set(null);

        return $this->getJson('/api/v1/public/branding?host='.urlencode($host))->assertOk();
    }

    private function publishLoginTheme(): void
    {
        $upload = fn (string $kind, int $w, int $h) => $this->postJson('/api/v1/branding/assets', [
            'kind' => $kind, 'file' => UploadedFile::fake()->image("{$kind}.png", $w, $h),
        ], $this->headersFor())->assertCreated()->json('data.id');

        $id = $this->postJson('/api/v1/config/theme', ['scope_type' => 'tenant', 'payload' => [
            'preset' => 'warm',
            'colors' => ['primary' => '#7c2d12', 'accent' => '#1e3a8a'],
            'corners' => 'soft',
            'logo_light' => $upload('logo', 300, 80),
            'logo_dark' => $upload('logo', 300, 80),
            'favicon' => $upload('favicon', 64, 64),
            'login' => ['background' => $upload('background', 1600, 900), 'welcome' => 'Karibu. Sign in to run the shop.'],
        ]], $this->headersFor())->assertCreated()->json('data.id');
        $this->postJson("/api/v1/config/theme/{$id}/publish", [], $this->headersFor())->assertOk();
    }

    public function test_a_subdomain_shows_the_tenants_published_branding(): void
    {
        $this->putJson('/api/v1/branding/settings', ['slug' => 'Acme'], $this->headersFor())->assertOk()
            ->assertJsonPath('data.slug', 'acme')->assertJsonPath('data.host', 'acme.example.app');

        // Before any theme is published: the tenant's name and the default look.
        $this->branding('acme.example.app')->assertJsonPath('data.tenant_name', $this->inTenant(fn () => Tenant::query()->value('name')))
            ->assertJsonPath('data.theme.preset', 'light')->assertJsonPath('data.logo_light_url', null)->assertJsonPath('data.hide_platform', false);

        $this->publishLoginTheme();
        $data = $this->branding('ACME.example.app:3018')->json('data');

        $this->assertSame('warm', $data['theme']['preset']);
        $this->assertEquals(['primary' => '#7c2d12', 'accent' => '#1e3a8a'], $data['theme']['colors']);
        $this->assertSame('#7c2d12', $data['tokens']['light']['primary']);
        $this->assertSame('16px', $data['tokens']['light']['radius-lg']);
        $this->assertSame('Karibu. Sign in to run the shop.', $data['welcome']);
        $this->assertArrayNotHasKey('logo_light', $data['theme']);

        // Signed URLs that serve the files without signing in.
        foreach (['logo_light_url', 'logo_dark_url', 'favicon_url', 'background_url'] as $key) {
            $this->assertNotNull($data[$key], $key);
            $this->get($data[$key])->assertOk()->assertHeader('Content-Type', 'image/png');
        }

        // Only public fields: no ids of the tenant, its documents or its assets.
        $tenantId = $this->owner->tenant_id;
        $body = json_encode(array_diff_key($data, array_flip(['logo_light_url', 'logo_dark_url', 'favicon_url', 'background_url'])));
        $this->assertStringNotContainsString($tenantId, $body);

        // Other hosts: the platform default.
        $this->branding('nobody.example.app')->assertJsonPath('data', null);
        $this->branding('a.b.example.app')->assertJsonPath('data', null);
        $this->branding('example.app')->assertJsonPath('data', null);
        $this->branding('localhost:3008')->assertJsonPath('data', null);
        $this->getJson('/api/v1/public/branding')->assertOk()->assertJsonPath('data', null);
    }

    public function test_a_verified_custom_domain_shows_the_branding_and_a_pending_one_does_not(): void
    {
        $this->publishLoginTheme();
        $domain = $this->postJson('/api/v1/branding/domains', ['host' => 'erp.company.co.ke'], $this->headersFor())->assertCreated()->json('data');

        $this->branding('erp.company.co.ke')->assertJsonPath('data', null);

        $this->dns->publish($domain['record']['name'], $domain['record']['value']);
        $this->postJson("/api/v1/branding/domains/{$domain['id']}/check", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'verified');

        $this->branding('erp.company.co.ke')->assertJsonPath('data.theme.preset', 'warm')->assertJsonPath('data.welcome', 'Karibu. Sign in to run the shop.');

        // Another tenant's subdomain shows that tenant, never this one.
        $other = $this->otherTenant();
        $this->putJson('/api/v1/branding/settings', ['slug' => 'other'], $this->bearer($this->tokenFor($other['user'])))->assertOk();
        $this->branding('other.example.app')->assertJsonPath('data.theme.preset', 'light')->assertJsonPath('data.welcome', null);

        // A slug belongs to one tenant.
        $this->putJson('/api/v1/branding/settings', ['slug' => 'other'], $this->headersFor())->assertUnprocessable()->assertJsonPath('code', 'slug_taken');
        $this->putJson('/api/v1/branding/settings', ['slug' => 'www'], $this->headersFor())->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_powered_by_is_hidden_only_by_the_platform(): void
    {
        $this->putJson('/api/v1/branding/settings', ['slug' => 'acme', 'hide_platform' => true], $this->headersFor())->assertOk()
            ->assertJsonPath('data.hide_platform', false);
        $this->getJson('/api/v1/me', $this->headersFor())->assertJsonPath('data.tenant.hide_platform', false);

        $this->artisan('tenant:branding', ['tenant' => $this->owner->tenant_id, '--hide-platform' => true])->assertSuccessful();

        $this->branding('acme.example.app')->assertJsonPath('data.hide_platform', true);
        $this->getJson('/api/v1/me', $this->headersFor())->assertJsonPath('data.tenant.hide_platform', true);
        $this->inTenant(fn () => $this->assertTrue(AuditEntry::query()->where('action', 'core.branding.hide_platform')->exists()));

        $this->artisan('tenant:branding', ['tenant' => $this->owner->tenant_id, '--show-platform' => true])->assertSuccessful();
        $this->branding('acme.example.app')->assertJsonPath('data.hide_platform', false);
        $this->artisan('tenant:branding', ['tenant' => $this->owner->tenant_id])->assertExitCode(2);
    }

    public function test_the_public_lookups_are_rate_limited(): void
    {
        RateLimiter::for(BrandingServiceProvider::PUBLIC_LIMITER, fn () => Limit::perMinute(2)->by('test'));
        RateLimiter::for(BrandingServiceProvider::TLS_LIMITER, fn () => Limit::perMinute(2)->by('test'));

        $this->getJson('/api/v1/public/branding?host=a.example.app')->assertOk();
        $this->getJson('/api/v1/public/branding?host=a.example.app')->assertOk();
        $this->getJson('/api/v1/public/branding?host=a.example.app')->assertStatus(429);

        $this->get('/api/v1/tls/ask?domain=a.example.org')->assertNotFound();
        $this->get('/api/v1/tls/ask?domain=a.example.org')->assertNotFound();
        $this->get('/api/v1/tls/ask?domain=a.example.org')->assertStatus(429);
    }

    public function test_hosts_are_parsed_strictly(): void
    {
        $this->assertSame(['slug' => 'acme', 'host' => null], PublicBranding::parse('Acme.Example.App.'));
        $this->assertSame(['slug' => null, 'host' => 'erp.company.co.ke'], PublicBranding::parse('erp.company.co.ke:443'));
        $this->assertNull(PublicBranding::parse("acme.example.app'; drop table tenants; --"));
        $this->assertNull(PublicBranding::parse(''));
        $this->assertNull(PublicBranding::parse(str_repeat('a', 300).'.com'));
    }
}
