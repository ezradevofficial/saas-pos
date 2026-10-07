<?php

namespace App\Core\Tenancy\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;

/** TEN-04, RBAC-04: branches are created at their company's scope, managed at their own. */
class BranchPolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function view(User $user, Branch $branch): bool
    {
        return $this->resolver->can($user, 'core.branch.view', $branch->scope());
    }

    public function create(User $user, Company $company): bool
    {
        return $this->resolver->can($user, 'core.branch.create', $company->scope());
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->resolver->can($user, 'core.branch.edit', $branch->scope());
    }

    public function archive(User $user, Branch $branch): bool
    {
        return $this->resolver->can($user, 'core.branch.archive', $branch->scope());
    }

    public function restore(User $user, Branch $branch): bool
    {
        return $this->archive($user, $branch);
    }
}
