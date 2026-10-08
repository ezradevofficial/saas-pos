<?php

namespace App\Core\MasterData\Items\Http\Controllers;

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

    public function index(ListItemCategoriesRequest $request): AnonymousResourceCollection
    {
        $query = ItemCategory::query();
        $companies = $this->policy->listableCompanies($request->user());

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        return ItemCategoryResource::collection(
            $request->applyStatus($query)->orderByRaw('coalesce(name_en, name_fr)')->orderBy('id')
                ->paginate($request->perPage())->withQueryString(),
        );
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
            $category = ItemCategory::query()->whereKey($itemCategory->id)->lockForUpdate()->firstOrFail();
            $category->fill($this->attributes($data))->save();

            return $category;
        });

        return ItemCategoryResource::make($category->refresh());
    }

    public function archive(ItemCategoryActionRequest $request, ItemCategory $itemCategory): ItemCategoryResource
    {
        if (! $itemCategory->isArchived()) {
            $inUse = ItemCategory::query()->active()->where('parent_id', $itemCategory->id)->exists()
                || Item::query()->active()->where('category_id', $itemCategory->id)->exists();

            if ($inUse) {
                throw new ApiException(422, 'category_in_use', __('core.item_category.in_use'));
            }

            $itemCategory->archive();
        }

        return ItemCategoryResource::make($itemCategory);
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

    private function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['parent_id', 'name_en', 'name_fr', 'colour']));
    }
}
