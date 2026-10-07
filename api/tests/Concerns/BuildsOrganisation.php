<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\RoleTemplates;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * A tenant with an Owner (system role at tenant scope), one company, two
 * branches (A, B) and one location in each, for organisation API tests.
 */
trait BuildsOrganisation
{
    use BuildsRbac;

    protected User $owner;

    protected Company $acme;

    protected Branch $branchA;

    protected Branch $branchB;

    protected Location $locationA;

    protected Location $locationB;

    /** @var Collection<string, Role> system roles by template key */
    protected Collection $roles;

    protected function setUpOrganisation(): void
    {
        $this->owner = $this->createUser();

        $this->asTenant($this->owner->tenant_id, function () {
            $this->roles = app(RoleTemplates::class)->provision(Tenant::findOrFail($this->owner->tenant_id));
            $this->assign($this->owner, $this->roles->get('owner'), Scope::tenant());

            $this->acme = $this->company('Acme');
            $this->branchA = $this->branch($this->acme, 'A');
            $this->branchB = $this->branch($this->acme, 'B');
            $this->locationA = $this->location($this->branchA, 'Outlet A');
            $this->locationB = $this->location($this->branchB, 'Outlet B');
        });
    }

    /** A colleague of the owner holding the system role $template at $scope. */
    protected function userWith(string $template, Scope $scope): User
    {
        return $this->asTenant($this->owner->tenant_id, function () use ($template, $scope) {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->roles->get($template), $scope);

            return $user;
        });
    }

    /** @var array<string, string> tokens by user id, so a test signs each user in once */
    private array $organisationTokens = [];

    /** Bearer headers for a signed-in $user (defaults to the owner). */
    protected function headersFor(?User $user = null): array
    {
        $user ??= $this->owner;

        return $this->bearer($this->organisationTokens[$user->id] ??= $this->tokenFor($user));
    }

    /** Another tenant with its own owner, company, branch and location. */
    protected function otherTenant(): array
    {
        $user = $this->createUser();

        return $this->asTenant($user->tenant_id, function () use ($user) {
            $roles = app(RoleTemplates::class)->provision(Tenant::findOrFail($user->tenant_id));
            $this->assign($user, $roles->get('owner'), Scope::tenant());
            $company = $this->company('Other');
            $branch = $this->branch($company, 'O');
            $location = $this->location($branch, 'Other outlet');

            return compact('user', 'company', 'branch', 'location');
        });
    }

    protected function inTenant(callable $fn): mixed
    {
        return $this->asTenant($this->owner->tenant_id, $fn);
    }
}
