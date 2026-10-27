<?php

namespace Modules\VanSales\Http\Controllers;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Location;
use Illuminate\Http\JsonResponse;
use Modules\VanSales\Http\Requests\BoundRequest;
use Modules\VanSales\Http\Requests\StoreVanRequest;
use Modules\VanSales\Http\Requests\UpdateVanRequest;
use Modules\VanSales\Http\Requests\ViewRequest;
use Modules\VanSales\Http\Resources\Views;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Support\Errors;
use Modules\VanSales\Support\Places;

/** VAN-02: vans, their rep, device and stock location. */
class VanController
{
    public function __construct(private readonly Places $places, private readonly Views $views) {}

    public function index(ViewRequest $request): JsonResponse
    {
        $query = Van::query()->orderBy('name')->orderBy('id');
        $this->places->filterLocations($query, $request->user(), $request->permission());

        foreach (['company_id', 'branch_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }

        return response()->json(['data' => $this->views->vans($query->get())]);
    }

    public function store(StoreVanRequest $request): JsonResponse
    {
        $branch = Branch::query()->findOrFail($request->input('branch_id'));
        $rep = $this->rep((string) $request->input('rep_id'));

        $van = Errors::transaction(function () use ($request, $branch, $rep) {
            if ($request->filled('location_id')) {
                $location = Location::query()->find($request->input('location_id'));

                if ($location === null || $location->branch_id !== $branch->id || $location->archived_at !== null) {
                    throw Errors::make(422, 'location_unknown', field: 'location_id');
                }

                // The van's location is also checked for the person's reach, so a stranger's location cannot be taken over.
                if (! $this->places->at($request->user(), 'van_sales.van.manage', $location->id)) {
                    throw Errors::make(403, 'not_permitted', field: 'location_id');
                }
            } else {
                // A van is a core location of type `store` (decision 2 of the spec).
                $location = Location::create(['branch_id' => $branch->id, 'name' => $request->input('name'), 'type' => 'store']);
            }

            $this->assertDevice($request->input('device_id'), $location->id);

            return Errors::named(fn () => Van::create([
                'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'location_id' => $location->id,
                'device_id' => $request->input('device_id'), 'name' => $request->input('name'), 'plate' => $request->input('plate'), 'rep_id' => $rep->id,
            ]));
        });

        return response()->json(['data' => $this->views->van($van->refresh())], 201);
    }

    public function update(UpdateVanRequest $request, Van $vsVan): JsonResponse
    {
        $data = $request->validated();

        if (array_key_exists('rep_id', $data)) {
            $this->rep($data['rep_id']);
        }

        if (array_key_exists('device_id', $data)) {
            $this->assertDevice($data['device_id'], $vsVan->location_id);
        }

        Errors::named(fn () => $vsVan->fill($data)->save());

        return response()->json(['data' => $this->views->van($vsVan->refresh())]);
    }

    public function archive(BoundRequest $request, Van $vsVan): JsonResponse
    {
        if (Run::query()->where('van_id', $vsVan->id)->whereIn('status', ['open', 'counting'])->exists()) {
            throw Errors::make(409, 'van_has_open_run');
        }

        $vsVan->archive();

        return response()->json(['data' => $this->views->van($vsVan->refresh())]);
    }

    private function rep(string $id): User
    {
        $user = User::query()->find($id);

        if ($user === null || ! $user->isActive()) {
            throw Errors::make(422, 'rep_unknown', field: 'rep_id');
        }

        return $user;
    }

    private function assertDevice(?string $deviceId, string $locationId): void
    {
        if ($deviceId !== null && ! Device::query()->whereKey($deviceId)->where('location_id', $locationId)->exists()) {
            throw Errors::make(422, 'device_unknown', field: 'device_id');
        }
    }
}
