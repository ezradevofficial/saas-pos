<?php

use App\Core\Billing\Http\EnforceSubscription;
use App\Core\Http\ApiErrorRenderer;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\SetLocale;
use App\Core\Observability\Http\RecordMetrics;
use App\Core\Observability\Http\RequestId;
use App\Core\Observability\Http\SecurityHeaders;
use App\Core\Rbac\Http\Middleware\EnsureModuleActive;
use App\Core\Support\Http\EnforceEnvironment;
use App\Core\Support\TrustedProxies;
use App\Core\Sync\Http\SyncLagHeader;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use App\Core\Tenancy\Http\RequireTenant;
use App\Core\Tenancy\Http\ResetTenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TEN-01: every request starts without a tenant; routes that need one
        // use `tenant` after auth.
        $middleware->prepend(ResetTenantContext::class);
        // NFR-10: the request id comes first, so every log line of the
        // request (even a refused one) and every job it queues carries it.
        $middleware->prepend(RequestId::class);
        // NFR-06: security headers on every response; NFR-02, NFR-10:
        // request counts and latency for /metrics.
        $middleware->append(SecurityHeaders::class);
        $middleware->append(RecordMetrics::class);
        // NFR-06: no request served with development drivers (EnvironmentGuard).
        // Appended, so it runs after PreventRequestsDuringMaintenance: while
        // the app is down (deploys), every request gets the 503 page.
        $middleware->append(EnforceEnvironment::class);
        // NFR-04: /up reports the device sync lag (headers; warning in the log).
        $middleware->append(SyncLagHeader::class);
        // L10N-01: first in the group, so even an authentication error is
        // translated; ApplyTenantLocale repeats the choice after auth.
        $middleware->api(prepend: [SetLocale::class]);
        // Only the load balancer may report the client's address (TRUSTED_PROXIES,
        // empty by default): the payment callback allowlist and rate limits
        // use the real client address, never a spoofed X-Forwarded-For.
        if (($proxies = TrustedProxies::parse(env('TRUSTED_PROXIES'))) !== []) {
            $middleware->trustProxies(at: $proxies, headers: TrustedProxies::HEADERS);
        }
        // RBAC-08: `module:{name}` after `tenant`.
        $middleware->alias(['module' => EnsureModuleActive::class]);
        // TEN-01, SUB-05: `tenant` refuses a request without a tenant, then
        // writes while the subscription is read-only (EnforceSubscription).
        $middleware->group('tenant', [RequireTenant::class, EnforceSubscription::class]);
        // TEN-05: the token kind is checked straight after authentication,
        // before route-model binding, so a device token on a back-office
        // route is 401 whether or not the id exists.
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureUserToken::class);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureDeviceToken::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // NFR-10: unhandled exceptions reach Sentry (a no-op without a DSN),
        // scrubbed by App\Core\Observability\SentryEvents.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API errors: {message, code, errors?} with a translated message.
        $exceptions->render(fn (Throwable $e, Request $request) => ApiErrorRenderer::render($e, $request));
    })->create();
