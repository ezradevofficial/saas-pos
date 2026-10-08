<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\TenantCurrencies;
use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Http\Requests\ArchiveRequest;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Http\Requests\StoreCompanyRequest;
use App\Core\Tenancy\Http\Requests\UpdateCompanyRequest;
use App\Core\Tenancy\Http\Resources\CompanyResource;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** TEN-03, TEN-06: companies of the tenant, filtered to the user's scope (RBAC-04). */
class CompanyController
{
    use ChecksScope;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Archiver $archiver,
        private readonly TenantCurrencies $currencies,
        private readonly BaseCurrencyLock $baseCurrencyLock,
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
        // CUR-01: the company's country currencies and base currency are activated for the tenant.
        $company = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request) {
            $company = Company::create($request->companyAttributes());
            $this->currencies->provisionFor($company);

            return $company;
        });

        return CompanyResource::make($company);
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

        $company = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data) {
            $company = Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();

            // CUR-02: a base currency locked by a posting never changes.
            if (array_key_exists('base_currency', $data)) {
                $this->baseCurrencyLock->assertCanChange($company, $data['base_currency']);
            }

            $company->fill($data)->save();

            if ($company->wasChanged('base_currency')) {
                $this->currencies->activate($company->base_currency);
            }

            return $company;
        });

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
