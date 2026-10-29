<?php

namespace App\Core\MasterData\Defaults;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ONB-03, RBAC-08: the activation defaults each module declares, whatever
 * route switches the module on (onboarding, a plan change, staff). A module
 * calls `register('pos', SalesDefaults::class)` from its service provider;
 * the core runs the defaults of a module when it is activated
 * (ModuleActivated) and for a company created while it is active
 * (CompanyCreated), so no module reaches into another's tables.
 *
 * A failing default is reported and skipped: the activation stands, and
 * `module-defaults:seed` picks it up on the next deploy.
 */
class ModuleDefaults
{
    /** @var array<string, list<class-string<ModuleDefault>>> */
    private array $defaults = [];

    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @param class-string<ModuleDefault> $default */
    public function register(string $module, string $default): void
    {
        if (! in_array($default, $this->defaults[$module] ?? [], true)) {
            $this->defaults[$module][] = $default;
        }
    }

    /** @return list<class-string<ModuleDefault>> */
    public function for(string $module): array
    {
        return $this->defaults[$module] ?? [];
    }

    /** Seed the defaults of every active module (or $onlyModule) in every live company of the current tenant; returns records created. */
    public function seedTenant(?string $onlyModule = null): int
    {
        $created = 0;

        foreach (Company::query()->whereNull('archived_at')->orderBy('id')->pluck('id') as $companyId) {
            $created += $this->seedCompany(Company::query()->findOrFail($companyId), $onlyModule);
        }

        return $created;
    }

    /** Seed the defaults of $onlyModule (or of every active module) in one company, each in its own transaction. */
    public function seedCompany(Company $company, ?string $onlyModule = null): int
    {
        $created = 0;

        if (! config('app.seed_module_defaults')) {
            return 0;
        }

        foreach ($this->defaults as $module => $classes) {
            if (($onlyModule !== null && $module !== $onlyModule) || ! $this->modules->isActive($module)) {
                continue;
            }

            foreach ($classes as $class) {
                try {
                    $created += DB::connection(TenantContext::CONNECTION)->transaction(
                        fn () => app($class)->seed(Company::query()->findOrFail($company->id)),
                    );
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $created;
    }
}
