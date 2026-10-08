<?php

namespace App\Providers;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Console\VerifyAuditChain;
use App\Core\Tenancy\Rls;
use App\Core\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // AUD-02: who/where of the current request; reset between requests.
        $this->app->scoped(AuditContext::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyAuditChain::class]);
        }

        // TEN-05: public device pairing, 10 attempts a minute per IP and 300
        // a minute overall, so many addresses cannot sweep the code space.
        RateLimiter::for('device-pair', fn (Request $request) => [
            Limit::perMinute(10)->by('ip|'.$request->ip()),
            Limit::perMinute(300)->by('global'),
        ]);

        // TEN-01: tenant_id uuid not null, defaulting to the session tenant,
        // indexed, FK to tenants (restrict). Pair with Rls::enable($table).
        Blueprint::macro('tenantId', function (): ColumnDefinition {
            /** @var Blueprint $this */
            $column = $this->uuid('tenant_id')->default(new Expression(Rls::TENANT_EXPRESSION));
            $this->index('tenant_id');
            $this->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();

            return $column;
        });

        // TEN-01: a rollback (full or to a savepoint) reverts set_config. Once
        // the connection is usable again, re-apply the PHP tenant.
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) {
            $this->resyncTenant($event->connection, onlyWhenSet: false);
        });

        $this->resetContextBetweenJobs();

        // TEN-01: a new or re-established connection has no tenant setting.
        // On the first connect there is nothing to apply, so no query runs.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $this->resyncTenant($event->connection, onlyWhenSet: true);
        });
    }

    /**
     * Review focus 3: a queue worker is one long process. Before and after
     * every job, and on every loop, the tenant and the audit context are
     * cleared, so nothing a job set leaks into the next one. TenantAware
     * jobs then enter their own tenant. Jobs on the `sync` connection run
     * inline inside the caller (a request or a command) and keep its
     * context: TenantAware restores it after the job.
     */
    private function resetContextBetweenJobs(): void
    {
        $reset = function (bool $onlyWhenSet = false): void {
            $tenants = $this->app->make(TenantContext::class);

            // The loop runs every few seconds when idle: no query unless needed.
            if (! $onlyWhenSet || $tenants->id() !== null) {
                $tenants->set(null);
            }

            $this->app->make(AuditContext::class)->reset();
        };

        Queue::before(function (JobProcessing $event) use ($reset) {
            if ($event->connectionName !== 'sync') {
                $reset();
            }
        });

        Queue::after(function (JobProcessed $event) use ($reset) {
            if ($event->connectionName !== 'sync') {
                $reset();
            }
        });

        Queue::exceptionOccurred(function (JobExceptionOccurred $event) use ($reset) {
            if ($event->connectionName !== 'sync') {
                $reset();
            }
        });

        Queue::looping(fn () => $reset(onlyWhenSet: true));
    }

    private function resyncTenant(Connection $connection, bool $onlyWhenSet): void
    {
        if ($connection->getName() !== TenantContext::CONNECTION
            || ! $this->app->resolved(TenantContext::class)) {
            return;
        }

        $context = $this->app->make(TenantContext::class);

        if ($onlyWhenSet && $context->id() === null) {
            return;
        }

        try {
            // Uses $connection directly, never the manager: no recursion.
            $context->syncTo($connection);
        } catch (Throwable $e) {
            // Still inside an aborted outer transaction: its own rollback
            // fires this listener again. Outside one, the connection is
            // broken: drop it so the reconnect re-applies the tenant.
            if ($connection->transactionLevel() === 0) {
                $connection->disconnect();
                report($e);
            }
        }
    }
}
