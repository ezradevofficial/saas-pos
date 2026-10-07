<?php

namespace Tests\Feature\Core\Localisation;

use App\Core\Localisation\Http\SetLocale;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app('router')->aliasMiddleware('tenant-for-test', SetTestTenant::class);

        Route::middleware('api')->get('api/v1/_test/locale', fn () => response()->json([
            'locale' => app()->getLocale(),
        ]));
    }

    public function test_uses_the_app_locale_without_a_header(): void
    {
        $this->getJson('/api/v1/_test/locale')->assertJson(['locale' => 'en']);
    }

    public function test_picks_french_from_accept_language(): void
    {
        $this->getJson('/api/v1/_test/locale', ['Accept-Language' => 'fr-CD,fr;q=0.9,en;q=0.8'])
            ->assertJson(['locale' => 'fr']);
    }

    public function test_picks_the_best_supported_language_by_quality(): void
    {
        $this->getJson('/api/v1/_test/locale', ['Accept-Language' => 'de, en;q=0.5, fr;q=0.8'])
            ->assertJson(['locale' => 'fr']);
    }

    public function test_unsupported_language_falls_back_to_the_app_locale(): void
    {
        $this->getJson('/api/v1/_test/locale', ['Accept-Language' => 'de-DE,de;q=0.9'])
            ->assertJson(['locale' => 'en']);
    }

    public function test_unsupported_language_falls_back_to_the_tenant_default_locale(): void
    {
        $tenant = Tenant::provision(['name' => 'Acme', 'default_locale' => 'fr']);

        // The group runs before auth, so a tenant is only known if a later
        // middleware sets it; this route sets it first, outside the api group.
        Route::get('api/v1/_test/tenant-locale', fn () => response()->json(['locale' => app()->getLocale()]))
            ->middleware([
                'tenant-for-test:'.$tenant->id,
                SetLocale::class,
            ]);

        $this->getJson('/api/v1/_test/tenant-locale', ['Accept-Language' => 'de'])
            ->assertJson(['locale' => 'fr']);
    }

    public function test_an_explicit_supported_language_beats_the_tenant_default(): void
    {
        $tenant = Tenant::provision(['name' => 'Acme', 'default_locale' => 'fr']);

        Route::get('api/v1/_test/tenant-locale', fn () => response()->json(['locale' => app()->getLocale()]))
            ->middleware([
                'tenant-for-test:'.$tenant->id,
                SetLocale::class,
            ]);

        $this->getJson('/api/v1/_test/tenant-locale', ['Accept-Language' => 'en'])
            ->assertJson(['locale' => 'en']);
    }
}

class SetTestTenant
{
    public function handle($request, $next, string $tenantId)
    {
        app(TenantContext::class)->set($tenantId);

        return $next($request);
    }
}
