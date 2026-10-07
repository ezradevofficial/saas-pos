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

    public function __construct(private readonly TenantContext $tenants) {}

    public function register(string $module): void
    {
        $this->modules[$module] = true;
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

        if (! isset($this->modules[$module]) || $this->tenants->id() === null) {
            return false;
        }

        return TenantModule::where('module', $module)->where('status', TenantModule::ACTIVE)->exists();
    }

    /** @return list<string> core first, then the tenant's active modules */
    public function active(): array
    {
        $optional = $this->tenants->id() === null ? [] : TenantModule::where('status', TenantModule::ACTIVE)
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
            $row->fill([
                'status' => TenantModule::ACTIVE,
                'activated_at' => now(),
                'deactivated_at' => null,
            ])->save();

            app(RoleTemplates::class)->refresh();

            return $row;
        });
    }

    public function deactivate(string $module): TenantModule
    {
        $this->assertOptional($module);

        $row = TenantModule::firstOrNew(['module' => $module]);
        $row->fill(['status' => TenantModule::INACTIVE, 'deactivated_at' => now()])->save();

        return $row;
    }

    private function assertOptional(string $module): void
    {
        if ($module === self::CORE || ! isset($this->modules[$module])) {
            throw new InvalidArgumentException("Module [{$module}] cannot be switched.");
        }
    }
}
