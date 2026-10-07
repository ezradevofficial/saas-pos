<?php

namespace App\Providers;

use App\Core\Tenancy\Rls;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

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
    }
}
