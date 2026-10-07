<?php

namespace Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\Rbac\Models\Role;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Location;
use Illuminate\Support\Str;

/**
 * Companies, branches, locations, roles and scoped assignments for RBAC
 * tests. Call inside the tenant's context.
 */
trait BuildsRbac
{
    use CreatesIdentities;

    protected function company(string $name = 'Acme'): Company
    {
        return Company::create([
            'name' => $name,
            'legal_name' => $name,
            'country' => 'KE',
            'base_currency' => 'KES',
            'fiscal_year_start_month' => 1,
            'timezone' => 'Africa/Nairobi',
        ]);
    }

    protected function branch(Company $company, string $code): Branch
    {
        return $company->branches()->create(['name' => 'Branch '.$code, 'code' => $code]);
    }

    protected function location(Branch $branch, string $name = 'Outlet'): Location
    {
        return $branch->locations()->create(['name' => $name, 'type' => 'outlet']);
    }

    /** @param list<string> $permissions */
    protected function role(string $name, array $permissions = []): Role
    {
        $role = Role::create(['name' => $name]);
        $role->givePermissionTo($permissions);

        return $role;
    }

    protected function assign(User $user, Role $role, Scope $scope): RoleAssignment
    {
        return RoleAssignment::create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scope->type,
            'scope_id' => $scope->id ?? $user->tenant_id,
        ]);
    }

    protected function colleague(User $of, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Colleague',
            'email' => 'user-'.Str::lower(Str::random(8)).'@example.com',
            'password' => $this->password,
            'status' => 'active',
            'email_verified_at' => now(),
        ], $attributes));
    }
}
