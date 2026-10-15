<?php

namespace App\Core\Rbac;

use App\Core\Rbac\Models\TenantModule;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Known modules and whether the current tenant has each one active
 * (RBAC-08). `core` is always active; any other module is active only with
 * an `active` row in tenant_modules. Unknown modules are never active.
 */
class ModuleRegistry
{
    public const CORE = 'core';

    /** @var array<string, true> */
    private array $modules = [self::CORE => true];

    /** @var array<string, list<string>> registry module => the catalogue keys that switch it on (default: its own name) */
    private array $soldAs = [];

    /**
     * Stage 7B performance review: whether each module is active, memoised
     * per tenant for the request or queued job (flushed when a tenant's
     * modules change, and before every job: RbacServiceProvider).
     *
     * @var array<string, array<string, bool>> tenant id => module => active
     */
    private array $memo = [];

    /** @var array<string, list<string>> tenant id => its active optional modules (active()) */
    private array $activeLists = [];

    public function __construct(private readonly TenantContext $tenants) {}

    public function register(string $module): void
    {
        $this->modules[$module] = true;
    }

    /**
     * SUB-07: $module follows any of these catalogue keys instead of its own
     * name (Accounting is sold as Essentials or Pro, docs/modules/accounting.md).
     *
     * @param  list<string>  $catalogueKeys
     */
    public function soldAs(string $module, array $catalogueKeys): void
    {
        $this->soldAs[$module] = array_values(array_unique($catalogueKeys));
    }

    /** @return list<string> the catalogue keys that switch $module on */
    public function catalogueKeys(string $module): array
    {
        return $this->soldAs[$module] ?? [$module];
    }

    /**
     * The registered optional modules that $catalogueKeys (a plan's or a
     * trial's selection) switch on.
     *
     * @param  list<string>  $catalogueKeys
     * @return list<string>
     */
    public function coveredBy(array $catalogueKeys): array
    {
        return array_values(array_filter(
            array_diff($this->registered(), [self::CORE]),
            fn (string $module) => array_intersect($this->catalogueKeys($module), $catalogueKeys) !== [],
        ));
    }

    /** @return list<string> */
    public function registered(): array
    {
        return array_keys($this->modules);
    }

    public function isActive(string $module): bool
    {
        if ($module === self::CORE) {
            return true;
        }

        $tenantId = $this->tenants->id();

        if (! isset($this->modules[$module]) || $tenantId === null) {
            return false;
        }

        return $this->memo[$tenantId][$module] ??= TenantModule::where('module', $module)->where('status', TenantModule::ACTIVE)->exists();
    }

    /** Forget what was memoised (a tenant's modules changed, or a new request or job starts). */
    public function flush(): void
    {
        $this->memo = [];
        $this->activeLists = [];
    }

    /** @return list<string> core first, then the tenant's active modules */
    public function active(): array
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return [self::CORE];
        }

        $optional = $this->activeLists[$tenantId] ??= TenantModule::where('status', TenantModule::ACTIVE)
            ->whereIn('module', array_diff($this->registered(), [self::CORE]))
            ->orderBy('module')
            ->pluck('module')
            ->all();

        return [self::CORE, ...$optional];
    }

    /**
     * Activate $module for the current tenant. System roles pick up the
     * module's permissions from their templates (RBAC-03).
     */
    public function activate(string $module): TenantModule
    {
        $this->assertOptional($module);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($module) {
            $row = TenantModule::firstOrNew(['module' => $module]);
            // SUB-07: re-subscribing restores the module with the data it kept.
            $row->fill([
                'status' => TenantModule::ACTIVE,
                'activated_at' => now(),
                'deactivated_at' => null,
                'switched_off_at' => null,
                'purge_flagged_at' => null,
            ])->save();

            $this->flush();
            app(RoleTemplates::class)->refresh();

            return $row;
        });
    }

    public function deactivate(string $module): TenantModule
    {
        $this->assertOptional($module);

        $row = TenantModule::firstOrNew(['module' => $module]);
        // SUB-07: hidden (RBAC-08) with its data kept; due for purge 12 months after it was switched off.
        $row->fill(['status' => TenantModule::INACTIVE, 'deactivated_at' => now(), 'switched_off_at' => $row->switched_off_at ?? now()])->save();
        $this->flush();

        return $row;
    }

    private function assertOptional(string $module): void
    {
        if ($module === self::CORE || ! isset($this->modules[$module])) {
            throw new InvalidArgumentException("Module [{$module}] cannot be switched.");
        }
    }
}
