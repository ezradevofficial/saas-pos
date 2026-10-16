<?php

namespace Modules\Ecommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ecommerce\Catalogue\Stores;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\StoreRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Access;

/**
 * ECOM-01, ECOM-23, ECOM-24: stores. Read with `ecommerce.store.view`,
 * changed with `ecommerce.store.manage`, both at the store's location
 * (RBAC-04: a branch or company role covers it); out of scope is 404.
 */
class StoreController
{
    public function __construct(private readonly Access $access, private readonly Stores $stores) {}

    public function index(ListRequest $request): JsonResponse
    {
        $query = $this->access->byLocation(Store::query(), $request->user(), 'ecommerce.store.view')
            ->when($request->validated('status', 'active') !== 'all', fn ($q) => $request->validated('status', 'active') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at'))
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->validated('company')))
            ->orderBy('name');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Store $s) => Present::store($s))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $this->access->assertAtLocation($request->user(), 'ecommerce.store.manage', (string) $request->validated('location_id'));

        return response()->json(['data' => Present::store($this->stores->create($request->validated()), true)], 201);
    }

    public function show(ListRequest $request, Store $ecomStore): JsonResponse
    {
        $this->visible($request, $ecomStore, 'ecommerce.store.view');

        return response()->json(['data' => Present::store($ecomStore, true)]);
    }

    public function update(StoreRequest $request, Store $ecomStore): JsonResponse
    {
        $this->visible($request, $ecomStore, 'ecommerce.store.manage');

        if ($request->has('location_id')) {
            $this->access->assertAtLocation($request->user(), 'ecommerce.store.manage', (string) $request->validated('location_id'));
        }

        return response()->json(['data' => Present::store($this->stores->update($ecomStore, $request->validated()), true)]);
    }

    public function archive(ListRequest $request, Store $ecomStore): JsonResponse
    {
        $this->visible($request, $ecomStore, 'ecommerce.store.manage');
        $ecomStore->archive();

        return response()->json(['data' => Present::store($ecomStore->refresh(), true)]);
    }

    public function restore(ListRequest $request, Store $ecomStore): JsonResponse
    {
        $this->visible($request, $ecomStore, 'ecommerce.store.manage');
        $ecomStore->restore();

        return response()->json(['data' => Present::store($ecomStore->refresh(), true)]);
    }

    /** ECOM-24: a new WhatsApp webhook token, shown once. */
    public function whatsappToken(ListRequest $request, Store $ecomStore): JsonResponse
    {
        $this->visible($request, $ecomStore, 'ecommerce.store.manage');
        $token = $this->stores->rotateWhatsApp($ecomStore);

        return response()->json(['data' => ['token' => $token, 'webhook_url' => rtrim((string) config('app.url'), '/').'/api/v1/shop/whatsapp/'.$token]], 201);
    }

    private function visible(Request $request, Store $store, string $permission): void
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $store->location_id), 404);

        if ($permission !== 'ecommerce.store.view') {
            $this->access->assertAtLocation($request->user(), $permission, $store->location_id);
        }
    }
}
