<?php

namespace App\Core\Api;

use App\Core\Api\Auth\ApiKeyAwareGuard;
use App\Core\Api\Console\ApiDocsCommand;
use App\Core\Api\Console\ExpireSandboxesCommand;
use App\Core\Api\Console\FlushApiUsageCommand;
use App\Core\Api\Console\PruneIdempotencyKeysCommand;
use App\Core\Api\Http\Middleware\PublicApi;
use App\Core\Api\Sandbox\Sandbox;
use App\Core\Api\Sandbox\SandboxOutbox;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\RequestGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
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

    /** API-05 (review): sandbox sign-ups, per IP, per email domain and overall, an hour. */
    public const SANDBOX_SIGN_UP_LIMITER = 'sandbox-sign-up';

    public function register(): void
    {
        $this->app->scoped(ApiKeyContext::class);
        $this->app->singleton(ApiScopes::class);
        $this->app->scoped(Sandbox::class);
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
        RateLimiter::for(self::SANDBOX_SIGN_UP_LIMITER, function (Request $request) {
            if (! $request->boolean('sandbox')) {
                return Limit::none();
            }

            $email = $request->input('email');
            $domain = is_string($email) && str_contains($email, '@') ? mb_strtolower(trim(substr($email, strrpos($email, '@') + 1))) : null;
            $limits = [
                Limit::perHour(max(1, (int) config('api.sandbox.sign_ups.per_ip_per_hour', 3)))->by('sandbox-ip|'.$request->ip()),
                Limit::perHour(max(1, (int) config('api.sandbox.sign_ups.per_hour', 50)))->by('sandbox-all'),
            ];

            if ($domain !== null && $domain !== '') {
                $limits[] = Limit::perHour(max(1, (int) config('api.sandbox.sign_ups.per_domain_per_hour', 5)))->by('sandbox-domain|'.$domain);
            }

            return $limits;
        });

        // A key's scopes are memoised per request; each queued job starts afresh.
        Event::listen(JobProcessing::class, fn () => $this->app->make(ApiScopes::class)->flush());

        // API-05 (review): mail sent in a sandbox is kept in its outbox, not sent.
        Event::listen(MessageSending::class, fn (MessageSending $event) => $this->app->make(SandboxOutbox::class)->onMessageSending($event));

        Scramble::configure()
            ->routes(fn (Route $route) => ApiScopes::resourceOf($route) !== null);

        if ($this->app->runningInConsole()) {
            $this->commands([ApiDocsCommand::class, FlushApiUsageCommand::class, PruneIdempotencyKeysCommand::class, ExpireSandboxesCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // API-03: cached request counters to the daily usage table.
            $schedule->command('api:flush-usage')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
            // API-01: stored idempotent responses past their 24 hours.
            $schedule->command('api:prune-idempotency')->hourly()->withoutOverlapping()->onOneServer();
            // API-05: inactive sandboxes are warned, then closed.
            $schedule->command('sandbox:expire')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
        });
    }
}
