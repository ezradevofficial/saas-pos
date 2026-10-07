<?php

namespace App\Core\Tenancy\Policies;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;

/** TEN-05, RBAC-04: locations are created at their branch's scope, managed at their own. */
class LocationPolicy
{
    public function __construct(private readonly ScopeResolver $resolver) {}

    public function view(User $user, Location $location): bool
    {
        return $this->resolver->can($user, 'core.location.view', $location->scope());
    }

    public function create(User $user, Branch $branch): bool
    {
        return $this->resolver->can($user, 'core.location.create', $branch->scope());
    }

    public function update(User $user, Location $location): bool
    {
        return $this->resolver->can($user, 'core.location.edit', $location->scope());
    }

    public function archive(User $user, Location $location): bool
    {
        return $this->resolver->can($user, 'core.location.archive', $location->scope());
    }

    public function restore(User $user, Location $location): bool
    {
        return $this->archive($user, $location);
    }
}
