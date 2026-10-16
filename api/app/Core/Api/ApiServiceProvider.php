<?php

namespace App\Core\Api;

use App\Core\Api\Auth\ApiKeyAwareGuard;
use App\Core\Api\Console\ApiDocsCommand;
use App\Core\Api\Http\Middleware\PublicApi;
use App\Core\Api\Sandbox\Sandbox;
use App\Core\Api\Sandbox\SetUpSandbox;
use App\Core\Tenancy\Events\TenantProvisioned;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The public API (API-01..API-05, docs/adr/020-public-api.md): API keys as
 * service principals on Sanctum's guard, the `public-api` route marker
 * (scopes, rate limits, idempotency, deprecation headers), the developer
 * sandbox and the OpenAPI document (Scramble, public routes only).
 */
class ApiServiceProvider extends ServiceProvider
{
    /** The OpenAPI document, served to the developer portal (per IP). */
    public const DOCS_LIMITER = 'api-docs';

    public function register(): void
    {
        $this->app->singleton(ApiKeyContext::class);
        $this->app->singleton(ApiScopes::class);
        $this->app->singleton(Sandbox::class);
        $this->app->singleton(ApiKeyAuthenticator::class);

        // API-04: Scramble documents the public routes only; its own UI and
        // JSON routes are not served (the portal serves the committed document).
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        $this->app->make(Router::class)->aliasMiddleware(PublicApi::ALIAS, PublicApi::class);

        // API-02: Sanctum's `sanctum` guard driver, taught API keys (sk_...).
        // Registered after Sanctum's own provider, so this definition wins.
        Auth::resolved(function ($auth) {
            $auth->extend('sanctum', function ($app, $name, array $config) use ($auth) {
                return tap(new RequestGuard(
                    new ApiKeyAwareGuard($auth, config('sanctum.expiration'), $config['provider'] ?? null, config('sanctum.last_used_at', true)),
                    $app['request'],
                    $auth->createUserProvider($config['provider'] ?? null),
                ), fn ($guard) => $app->refresh('request', $guard, 'setRequest'));
            });
        });

        RateLimiter::for(self::DOCS_LIMITER, fn (Request $request) => Limit::perMinute(60)->by('api-docs|'.$request->ip()));

        // A key's scopes are memoised per request; each queued job starts afresh.
        Event::listen(JobProcessing::class, fn () => $this->app->make(ApiScopes::class)->flush());

        // API-05: a sandbox's modules and sample data, after the roles and the onboarding record.
        Event::listen(TenantProvisioned::class, SetUpSandbox::class);

        Scramble::configure()
            ->routes(fn (Route $route) => ApiScopes::resourceOf($route) !== null);

        if ($this->app->runningInConsole()) {
            $this->commands([ApiDocsCommand::class]);
        }
    }
}
