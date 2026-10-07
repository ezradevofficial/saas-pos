<?php

namespace App\Providers;

use App\Core\Tenancy\Rls;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
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

        // TEN-01: a new or re-established connection has no tenant setting.
        // On the first connect there is nothing to apply, so no query runs.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            $this->resyncTenant($event->connection, onlyWhenSet: true);
        });
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
