<?php

namespace App\Core\Automation;

use App\Core\Automation\Models\AutomationRule;
use App\Core\Identity\Models\User;
use App\Core\MasterData\CompanyReach;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;

/**
 * RBAC-04 for automation rules (AUTO-01..AUTO-07): a company's rule (and
 * its runs) is seen with `core.automation.view` or `edit` at, above or
 * beneath the company; a rule for every company with either anywhere.
 * Rules are changed with `core.automation.edit` at the company, or at
 * tenant scope for a rule of every company.
 */
class AutomationAccess
{
    public const PERMISSIONS = ['core.automation.view', 'core.automation.edit'];

    public function __construct(private readonly CompanyReach $reach) {}

    public function anywhere(User $user): bool
    {
        return $this->reach->anywhere($user, self::PERMISSIONS);
    }

    public function sees(User $user, AutomationRule $rule): bool
    {
        return $rule->tenant_id === $user->tenant_id && $this->reach->reachesRecord($user, $rule->company_id, self::PERMISSIONS);
    }

    public function mayEdit(User $user, ?string $companyId): bool
    {
        return $user->can('core.automation.edit', $companyId === null ? Scope::tenant() : Scope::company($companyId));
    }

    /** Whether the user reaches $company for automation at all (else it is not found for them). */
    public function reachesCompany(User $user, Company $company): bool
    {
        return $this->reach->reaches($user, $company, [...self::PERMISSIONS, 'core.company.view']);
    }

    /** @return list<string>|null the companies whose rules the user sees, null for all */
    public function companyIds(User $user): ?array
    {
        return $this->reach->companyIds($user, self::PERMISSIONS);
    }
}
