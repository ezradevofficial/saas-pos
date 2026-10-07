<?php

namespace App\Core\Rbac;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Console\SyncPermissions;
use App\Core\Rbac\Listeners\ProvisionTenantRoles;
use App\Core\Tenancy\Events\TenantProvisioned;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

/**
 * RBAC (RBAC-01..RBAC-09): the catalogue, module flags, the per-tenant
 * permission cache, the Gate hook and role provisioning on sign-up.
 */
class RbacServiceProvider extends ServiceProvider
{
    public const CACHE_PREFIX = 'permission.cache.';

    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(RoleTemplates::class);
        $this->app->singleton(ScopeResolver::class);
        $this->app->singleton(FieldRules::class);
        $this->app->singleton(LimitRules::class);
        $this->app->singleton(OwnerGuard::class);
    }

    public function boot(): void
    {
        $this->app->make(PermissionRegistry::class)->register(ModuleRegistry::CORE, PermissionRegistry::CORE);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissions::class]);
        }

        // Spatie binds its registrar while booting, before this provider
        // boots: replace it with the transaction-safe one before first use.
        $this->app->singleton(PermissionRegistrar::class, TenantPermissionRegistrar::class);
        $this->keyPermissionCacheByTenant();

        // RBAC-09: `$user->can('module.resource.action', $target)` is answered
        // by ScopeResolver when the target has a scope (a HasScope model or a
        // Scope), or when there is no target or only a class name ("anywhere").
        // Any other argument (e.g. a User) has no scope of its own: return
        // null so that model's policy decides, never "anywhere" (fail closed).
        // Other abilities fall through to policies too.
        Gate::before(function ($user, string $ability, array $arguments) {
            if (! $user instanceof User || ! PermissionRegistry::isPermissionName($ability)) {
                return null;
            }

            $target = $arguments[0] ?? null;
            $scope = match (true) {
                $target instanceof HasScope => $target->scope(),
                $target instanceof Scope => $target,
                $target === null, is_string($target) && class_exists($target) => null,
                default => false,
            };

            if ($scope === false) {
                return null;
            }

            return $this->app->make(ScopeResolver::class)->can($user, $ability, $scope);
        });

        Event::listen(TenantProvisioned::class, ProvisionTenantRoles::class);
    }

    /**
     * Review focus 4: Spatie's cache holds which roles carry each
     * permission. Key it by tenant and drop the in-memory copy whenever the
     * tenant changes, so one tenant's roles are never served to another and
     * a flush only clears the current tenant. Idempotent.
     */
    private function keyPermissionCacheByTenant(): void
    {
        $apply = function (PermissionRegistrar $registrar, ?string $tenantId): void {
            $registrar->cacheKey = self::CACHE_PREFIX.($tenantId ?? 'none');
            $registrar->clearPermissionsCollection();
        };

        $this->app->afterResolving(PermissionRegistrar::class, function (PermissionRegistrar $registrar) use ($apply) {
            $apply($registrar, $this->app->make(TenantContext::class)->id());
        });

        $this->app->make(TenantContext::class)->onChange(function (?string $tenantId) use ($apply) {
            if ($this->app->resolved(PermissionRegistrar::class)) {
                $apply($this->app->make(PermissionRegistrar::class), $tenantId);
            }
        });
    }
}
