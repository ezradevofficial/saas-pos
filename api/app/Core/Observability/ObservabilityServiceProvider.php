<?php

namespace App\Core\Observability;

use App\Core\Observability\Console\BackupVerify;
use App\Core\Observability\Console\ReencryptColumns;
use App\Core\Observability\Console\StatusSnapshot;
use App\Core\Observability\Health\Readiness;
use App\Core\Observability\Http\HealthController;
use App\Core\Observability\Http\MetricsController;
use App\Core\Observability\Metrics\HttpMetrics;
use App\Core\Observability\Metrics\MetricsStore;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * NFR-01, NFR-07, NFR-10: health checks, metrics, the status snapshot,
 * backup verification, and the tenant id on logs and Sentry events.
 * The middleware (RequestId, SecurityHeaders, RecordMetrics) is
 * registered in bootstrap/app.php. See docs/observability.md.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MetricsStore::class, fn ($app) => new MetricsStore(
            (string) $app['config']->get('observability.metrics.store', 'redis'),
            (string) $app['config']->get('observability.metrics.redis_connection', 'default'),
        ));
        $this->app->singleton(HttpMetrics::class, fn ($app) => new HttpMetrics(
            $app->make(MetricsStore::class),
            array_map('floatval', (array) $app['config']->get('observability.metrics.buckets', [])),
        ));
        $this->app->singleton(Readiness::class);
    }

    public function boot(): void
    {
        // Outside the api group: no session, no auth, no tenant. /metrics is
        // guarded in its controller (token or private network).
        Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
        Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
        Route::get('metrics', MetricsController::class)->name('metrics');

        if ($this->app->runningInConsole()) {
            $this->commands([StatusSnapshot::class, BackupVerify::class, ReencryptColumns::class]);
        }

        // The tenant id (never a name) on every log line and Sentry event.
        $this->app->make(TenantContext::class)->onChange(function (?string $tenantId) {
            $tenantId === null ? Context::forget('tenant_id') : Context::add('tenant_id', $tenantId);

            if (config('sentry.dsn')) {
                configureScope(fn (Scope $scope) => $tenantId === null ? $scope->removeTag('tenant_id') : $scope->setTag('tenant_id', $tenantId));
            }
        });
    }
}
