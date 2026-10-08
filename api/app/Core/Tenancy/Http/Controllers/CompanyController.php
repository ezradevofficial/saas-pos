<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Http\Requests\ArchiveRequest;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Http\Requests\StoreCompanyRequest;
use App\Core\Tenancy\Http\Requests\UpdateCompanyRequest;
use App\Core\Tenancy\Http\Resources\CompanyResource;
use App\Core\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** TEN-03, TEN-06: companies of the tenant, filtered to the user's scope (RBAC-04). */
class CompanyController
{
    use ChecksScope;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Archiver $archiver,
    ) {}

    public function index(ListRequest $request): AnonymousResourceCollection
    {
        $query = $this->resolver->visibleIds($request->user(), 'core.company.view')
            ->applyTo(Company::query(), Scope::COMPANY);

        return CompanyResource::collection(
            $request->applyStatus($query)->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }

    public function store(StoreCompanyRequest $request): CompanyResource
    {
        return CompanyResource::make(Company::create($request->companyAttributes()));
    }

    public function show(Request $request, Company $company): CompanyResource
    {
        $this->visibleOr404($request, $company);

        return CompanyResource::make($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $data = $request->validated();

        if (array_key_exists('address', $data)) {
            $data['address'] ??= [];
        }

        $company->fill($data)->save();

        return CompanyResource::make($company);
    }

    public function archive(ArchiveRequest $request, Company $company): CompanyResource
    {
        $this->authorizeInScope($request, 'archive', $company);

        return CompanyResource::make($this->archiver->archive($company));
    }

    public function restore(ArchiveRequest $request, Company $company): CompanyResource
    {
        $this->authorizeInScope($request, 'restore', $company);

        return CompanyResource::make($this->archiver->restore($company));
    }
}
