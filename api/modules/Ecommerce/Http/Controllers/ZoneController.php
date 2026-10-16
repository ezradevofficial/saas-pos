<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\ZoneRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Models\Zone;
use Modules\Ecommerce\Support\Access;

/** ECOM-10: a store's delivery zones and fees, as configuration. Archived, never deleted. */
class ZoneController
{
    public function __construct(private readonly Access $access) {}

    public function index(ListRequest $request, Store $ecomStore): JsonResponse
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $ecomStore->location_id), 404);
        $zones = Zone::query()->where('store_id', $ecomStore->id)
            ->when($request->validated('status', 'active') !== 'all', fn ($q) => $q->whereNull('archived_at'))
            ->orderBy('position')->orderBy('name')->get();

        return response()->json(['data' => $zones->map(fn (Zone $z) => Present::zone($z))->values()]);
    }

    public function store(ZoneRequest $request, Store $ecomStore): JsonResponse
    {
        $this->manage($request, $ecomStore);
        $zone = Zone::create(['store_id' => $ecomStore->id, ...$request->validated()]);

        return response()->json(['data' => Present::zone($zone->refresh())], 201);
    }

    public function update(ZoneRequest $request, Zone $ecomZone): JsonResponse
    {
        $this->manage($request, Store::query()->findOrFail($ecomZone->store_id));
        $ecomZone->fill($request->validated())->save();

        return response()->json(['data' => Present::zone($ecomZone->refresh())]);
    }

    public function archive(ListRequest $request, Zone $ecomZone): JsonResponse
    {
        $this->manage($request, Store::query()->findOrFail($ecomZone->store_id));
        $ecomZone->forceFill(['active' => false])->archive();

        return response()->json(['data' => Present::zone($ecomZone->refresh())]);
    }

    private function manage(Request $request, Store $store): void
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $store->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.store.manage', $store->location_id);
    }
}
