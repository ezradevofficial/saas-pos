<?php

namespace App\Core\Rbac\Http\Controllers;

use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET me/companies (RBAC-04, RBAC-09; stage 7B walkthrough): the companies
 * the signed-in user holds any role in, for the company switcher, without
 * `core.company.view`: a tenant-wide role covers every company, a company
 * role its company, a branch or location role the company it belongs to.
 * Only what the switcher needs (id, name, country, base currency, time
 * zone); archived companies and archived roles are left out. Screens still
 * check each permission at the company they show.
 */
class MyCompaniesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $assignments = RoleAssignment::query()
            ->join('roles', 'roles.id', '=', 'role_assignments.role_id')
            ->whereNull('roles.archived_at')
            ->where('role_assignments.user_id', $request->user()->getKey())
            ->toBase()->get(['role_assignments.scope_type', 'role_assignments.scope_id']);

        $companies = Company::query()->whereNull('archived_at');

        if (! $assignments->contains('scope_type', Scope::TENANT)) {
            $ids = fn (string $type) => $assignments->where('scope_type', $type)->pluck('scope_id')->all();
            $companies->where(function ($q) use ($ids) {
                $q->whereIn('id', $ids(Scope::COMPANY))
                    ->orWhereIn('id', DB::table('branches')->whereIn('id', $ids(Scope::BRANCH))->select('company_id'))
                    ->orWhereIn('id', DB::table('locations')->join('branches', 'branches.id', '=', 'locations.branch_id')
                        ->whereIn('locations.id', $ids(Scope::LOCATION))->select('branches.company_id'));
            });
        }

        return new JsonResponse(['data' => $companies->orderBy('name')->orderBy('id')->get()
            ->map(fn (Company $company) => [
                'id' => $company->id, 'name' => $company->name, 'country' => $company->country,
                'base_currency' => $company->base_currency, 'timezone' => $company->timezone, 'archived_at' => null,
            ])->values()->all()]);
    }
}
