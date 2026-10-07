<?php

namespace App\Core\Tenancy\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Company;

/** TEN-03, RBAC-04: companies are created at tenant scope, managed at their own. */
class CompanyPolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function view(User $user, Company $company): bool
    {
        return $this->resolver->can($user, 'core.company.view', $company->scope());
    }

    public function create(User $user): bool
    {
        return $this->resolver->can($user, 'core.company.create', Scope::tenant());
    }

    public function update(User $user, Company $company): bool
    {
        return $this->resolver->can($user, 'core.company.edit', $company->scope());
    }

    public function archive(User $user, Company $company): bool
    {
        return $this->resolver->can($user, 'core.company.archive', $company->scope());
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->archive($user, $company);
    }
}
