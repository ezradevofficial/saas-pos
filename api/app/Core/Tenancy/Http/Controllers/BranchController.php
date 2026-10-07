<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Http\Requests\StoreBranchRequest;
use App\Core\Tenancy\Http\Requests\UpdateBranchRequest;
use App\Core\Tenancy\Http\Resources\BranchResource;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** TEN-04, TEN-06: branches, filtered to the user's scope (RBAC-04). */
class BranchController
{
    use ChecksScope;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Visibility $visibility,
        private readonly Archiver $archiver,
    ) {}

    /** Every visible branch, for the organisation tree. */
    public function all(ListRequest $request): AnonymousResourceCollection
    {
        return $this->list($request, Branch::query());
    }

    public function index(ListRequest $request, Company $company): AnonymousResourceCollection
    {
        abort_unless($this->visibility->reaches($request->user(), 'core.branch.view', $company), 404);

        return $this->list($request, $company->branches()->getQuery());
    }

    public function store(StoreBranchRequest $request, Company $company): BranchResource
    {
        $this->ensureActiveParent($company);

        $data = $request->validated();
        $data['address'] = $data['address'] ?? [];

        return BranchResource::make($company->branches()->create($data));
    }

    public function show(Request $request, Branch $branch): BranchResource
    {
        $this->visibleOr404($request, $branch);

        return BranchResource::make($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        $data = $request->validated();

        if (array_key_exists('address', $data)) {
            $data['address'] ??= [];
        }

        $branch->fill($data)->save();

        return BranchResource::make($branch);
    }

    public function archive(Request $request, Branch $branch): BranchResource
    {
        $this->authorizeInScope($request, 'archive', $branch);

        return BranchResource::make($this->archiver->archive($branch));
    }

    public function restore(Request $request, Branch $branch): BranchResource
    {
        $this->authorizeInScope($request, 'restore', $branch);

        return BranchResource::make($this->archiver->restore($branch));
    }

    private function list(ListRequest $request, Builder $query): AnonymousResourceCollection
    {
        $query = $this->resolver->visibleIds($request->user(), 'core.branch.view')->applyTo($query, Scope::BRANCH);

        return BranchResource::collection(
            $request->applyStatus($query)->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }
}
