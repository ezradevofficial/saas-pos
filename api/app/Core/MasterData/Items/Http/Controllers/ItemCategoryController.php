<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\Exports\ListExport;
use App\Core\Http\ApiException;
use App\Core\MasterData\Items\Http\Requests\ItemCategoryActionRequest;
use App\Core\MasterData\Items\Http\Requests\ItemCategoryRequest;
use App\Core\MasterData\Items\Http\Requests\ListItemCategoriesRequest;
use App\Core\MasterData\Items\Http\Requests\StoreItemCategoryRequest;
use App\Core\MasterData\Items\Http\Requests\UpdateItemCategoryRequest;
use App\Core\MasterData\Items\Http\Resources\ItemCategoryResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemCategoryPolicy;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-02: item categories, a tree, shared or per company as items are
 * (TEN-08). A category with active subcategories or items is not archived
 * (`category_in_use`); a parent is restored before its children
 * (`parent_archived`). Archived, never deleted (TEN-06); audited (MD-07).
 */
class ItemCategoryController
{
    public function __construct(
        private readonly ItemCategoryPolicy $policy,
        private readonly MasterDataSharing $sharing,
    ) {}

    public function index(ListItemCategoriesRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = ItemCategory::query();
        $companies = $this->policy->listableCompanies($request->user());

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        // A search never matches a field the user can't see (RBAC-05).
        $request->applySort($request->applySearch($request->applyStatus($query), ['name' => 'name']));

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ItemCategoryResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreItemCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $companyId = $data['company_id'] ?? null;

        $category = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $companyId) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);

            if (! ItemSharing::fits($companyId)) {
                throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
            }

            $this->assertParent($data['parent_id'] ?? null, $companyId, null);

            return ItemCategory::create(['company_id' => $companyId, ...$this->attributes($data)]);
        });

        return ItemCategoryResource::make($category->refresh())->response()->setStatusCode(201);
    }

    public function show(ItemCategoryRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        return ItemCategoryResource::make($itemCategory);
    }

    public function update(UpdateItemCategoryRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        $data = $request->validated();

        $category = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($itemCategory, $data) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);

            // One parent change at a time per tenant, so two moves cannot
            // build a cycle together (lock order: sharing, tree, rows).
            if (array_key_exists('parent_id', $data)) {
                DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtext(?))', ['item_category_tree:'.app(TenantContext::class)->require()]);
            }

            $category = ItemCategory::query()->whereKey($itemCategory->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('parent_id', $data)) {
                $this->assertParent($data['parent_id'], $category->company_id, $category);
            }

            $category->fill($this->attributes($data))->save();

            return $category;
        });

        return ItemCategoryResource::make($category->refresh());
    }

    /**
     * Under the items sharing lock and the category's row lock (FOR UPDATE),
     * so an item or subcategory writer (which reads it FOR SHARE) either
     * sees the archive or is seen here.
     */
    public function archive(ItemCategoryActionRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        $category = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($itemCategory) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $category = ItemCategory::query()->whereKey($itemCategory->id)->lockForUpdate()->firstOrFail();

            if (! $category->isArchived()) {
                $inUse = ItemCategory::query()->active()->where('parent_id', $category->id)->exists()
                    || Item::query()->active()->where('category_id', $category->id)->exists();

                if ($inUse) {
                    throw new ApiException(422, 'category_in_use', __('core.item_category.in_use'));
                }

                $category->archive();
            }

            return $category;
        });

        return ItemCategoryResource::make($category);
    }

    public function restore(ItemCategoryActionRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        if ($itemCategory->isArchived()) {
            if ($itemCategory->parent_id !== null && ItemCategory::query()->whereKey($itemCategory->parent_id)->whereNotNull('archived_at')->exists()) {
                throw new ApiException(422, 'parent_archived', __('core.item_category.parent_archived'));
            }

            $itemCategory->restore();
        }

        return ItemCategoryResource::make($itemCategory);
    }

    /**
     * Re-checked under the locks (validation ran before them): the parent is
     * active, in the same scope, and not this category or beneath it.
     */
    private function assertParent(?string $parentId, ?string $companyId, ?ItemCategory $category): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = ItemCategory::query()->whereKey($parentId)->sharedLock()->first();

        $message = match (true) {
            $parent === null || $parent->isArchived() || $parent->company_id !== $companyId => __('core.item_category.parent_other_scope'),
            $category !== null && $category->isAncestorOf($parentId) => __('core.item_category.parent_cycle'),
            default => null,
        };

        if ($message !== null) {
            throw ValidationException::withMessages(['parent_id' => $message]);
        }
    }

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['parent_id', 'name', 'colour']));
    }
}
