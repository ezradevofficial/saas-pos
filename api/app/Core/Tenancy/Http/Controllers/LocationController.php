<?php

namespace App\Core\Tenancy\Http\Controllers;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Archiver;
use App\Core\Tenancy\Http\Requests\ListRequest;
use App\Core\Tenancy\Http\Requests\StoreLocationRequest;
use App\Core\Tenancy\Http\Requests\UpdateLocationRequest;
use App\Core\Tenancy\Http\Resources\LocationResource;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** TEN-05, TEN-06: locations, filtered to the user's scope (RBAC-04). */
class LocationController
{
    use ChecksScope;

    public function __construct(
        private readonly ScopeResolver $resolver,
        private readonly Visibility $visibility,
        private readonly Archiver $archiver,
    ) {}

    /** Every visible location, for the organisation tree. */
    public function all(ListRequest $request): AnonymousResourceCollection
    {
        return $this->list($request, Location::query());
    }

    public function index(ListRequest $request, Branch $branch): AnonymousResourceCollection
    {
        abort_unless($this->visibility->reaches($request->user(), 'core.location.view', $branch), 404);

        return $this->list($request, $branch->locations()->getQuery());
    }

    public function store(StoreLocationRequest $request, Branch $branch): LocationResource
    {
        // TEN-06: nothing new under an archived branch.
        return LocationResource::make(DB::transaction(
            fn () => $this->archiver->lockActive(Branch::class, $branch->id)->locations()->create($request->validated()),
        ));
    }

    public function show(Request $request, Location $location): LocationResource
    {
        $this->visibleOr404($request, $location);

        return LocationResource::make($location);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $location->fill($request->validated())->save();

        return LocationResource::make($location);
    }

    public function archive(Request $request, Location $location): LocationResource
    {
        $this->authorizeInScope($request, 'archive', $location);

        return LocationResource::make($this->archiver->archive($location));
    }

    public function restore(Request $request, Location $location): LocationResource
    {
        $this->authorizeInScope($request, 'restore', $location);

        return LocationResource::make($this->archiver->restore($location));
    }

    private function list(ListRequest $request, Builder $query): AnonymousResourceCollection
    {
        $query = $this->resolver->visibleIds($request->user(), 'core.location.view')->applyTo($query, Scope::LOCATION);

        return LocationResource::collection(
            $request->applyStatus($query)->with('branch:id,name')->orderBy('name')->orderBy('id')->paginate($request->perPage())->withQueryString(),
        );
    }
}
