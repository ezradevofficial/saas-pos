<?php

namespace Tests\Feature\Core\Observability;

use App\Core\Observability\Http\SecurityHeaders;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * NFR-06: security headers on every response; HSTS only in production
 * over HTTPS; a data-only CSP that leaves pages and files to their route.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshTenantDatabase;

    public function test_api_responses_carry_the_headers(): void
    {
        $response = $this->getJson('/api/v1/me')->assertUnauthorized();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $response->assertHeader('Content-Security-Policy', SecurityHeaders::API_CSP);
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_only_in_production_over_https(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->get('http://localhost/health/live')->assertHeaderMissing('Strict-Transport-Security');
            $this->get('https://localhost/health/live')->assertHeader('Strict-Transport-Security', SecurityHeaders::HSTS);
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->get('https://localhost/health/live')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_html_pages_and_files_keep_their_own_policy(): void
    {
        Route::get('/_test/page', fn () => response('<p>Page</p>', 200, ['Content-Type' => 'text/html; charset=utf-8']));
        Route::get('/_test/strict', fn () => response()->json([])->header('Referrer-Policy', 'same-origin')->header('Content-Security-Policy', "default-src 'self'"));

        $this->get('/_test/page')->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/_test/strict')->assertHeader('Referrer-Policy', 'same-origin')->assertHeader('Content-Security-Policy', "default-src 'self'");
    }
}
