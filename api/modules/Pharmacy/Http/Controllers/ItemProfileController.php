<?php

namespace Modules\Pharmacy\Http\Controllers;

use App\Core\MasterData\Items\Item;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Pharmacy\Http\Requests\ItemProfileRequest;
use Modules\Pharmacy\Http\Requests\ListItemProfilesRequest;
use Modules\Pharmacy\Http\Resources\ProfileResource;
use Modules\Pharmacy\Items\Profiles;
use Modules\Pharmacy\Models\ItemProfile;

/** PHA-03, PHA-20, PHA-21: items' pharmacy profiles. */
class ItemProfileController
{
    public function __construct(private readonly Profiles $profiles) {}

    public function index(ListItemProfilesRequest $request): AnonymousResourceCollection
    {
        $query = ItemProfile::query()->with('item')->join('items', 'items.id', '=', 'pharmacy_item_profiles.item_id')->select('pharmacy_item_profiles.*');

        foreach (['class', 'product_kind'] as $filter) {
            if ($request->filled($filter)) {
                $query->where('pharmacy_item_profiles.'.$filter, $request->validated($filter));
            }
        }

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';
            $query->where(fn ($q) => $q->where('items.name', 'ilike', $like)->orWhere('items.code', 'ilike', $like));
        }

        return ProfileResource::collection($query->orderBy('items.name')->orderBy('pharmacy_item_profiles.id')
            ->paginate((int) $request->validated('per_page', ListRequest::PER_PAGE))->withQueryString());
    }

    public function show(ItemProfileRequest $request, Item $pharmacyItem): ProfileResource
    {
        return ProfileResource::forItem($pharmacyItem, $this->profiles->of($pharmacyItem->id));
    }

    public function update(ItemProfileRequest $request, Item $pharmacyItem): ProfileResource
    {
        $profile = $this->profiles->save($pharmacyItem, $request->validated());
        // PUT answers 200 whether the item had a profile yet or not.
        $profile->wasRecentlyCreated = false;

        return ProfileResource::forItem($pharmacyItem, $profile);
    }
}
