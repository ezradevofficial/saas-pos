<?php

namespace Modules\Ecommerce\Http\Controllers;

use App\Core\MasterData\Items\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Ecommerce\Http\Requests\ListRequest;
use Modules\Ecommerce\Http\Requests\ProductRequest;
use Modules\Ecommerce\Http\Resources\Present;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Access;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-02: a store's catalogue, chosen from the company's or shared core
 * items (active, not kits). Added as unpublished drafts with a slug from
 * the item's name (made unique in the store); published, described and
 * ordered afterwards. Archived, never deleted.
 */
class ProductController
{
    public function __construct(private readonly Access $access) {}

    public function index(ListRequest $request, Store $ecomStore): JsonResponse
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $ecomStore->location_id), 404);
        $search = trim((string) $request->validated('search', ''));
        $query = Product::query()->where('store_id', $ecomStore->id)->with('item')
            ->when($request->validated('status', 'active') !== 'all', fn ($q) => $request->validated('status', 'active') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at'))
            ->when($search !== '', fn ($q) => $q->whereHas('item', fn ($i) => $i->where(fn ($w) => $w->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orWhere('code', 'ilike', '%'.addcslashes($search, '\\%_').'%'))))
            ->orderBy('position')->orderBy('slug');
        $page = $query->paginate($request->perPage());

        return response()->json(['data' => $page->getCollection()->map(fn (Product $p) => Present::product($p))->values(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage()]]);
    }

    public function store(ProductRequest $request, Store $ecomStore): JsonResponse
    {
        $this->manage($request, $ecomStore);

        $products = Tx::run(function () use ($request, $ecomStore) {
            $items = Item::query()->whereKey($request->validated('item_ids'))->whereNull('archived_at')->where('type', '!=', 'kit')
                ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $ecomStore->company_id))->get();

            if ($items->count() !== count($request->validated('item_ids'))) {
                throw EcomError::make('item_not_sellable', field: 'item_ids');
            }

            $created = [];

            foreach ($items as $item) {
                $existing = Product::query()->where('store_id', $ecomStore->id)->where('item_id', $item->id)->first();

                if ($existing !== null) {
                    $created[] = $existing;

                    continue;
                }

                $created[] = Product::create([
                    'store_id' => $ecomStore->id, 'item_id' => $item->id, 'slug' => $this->slug($ecomStore, (string) $item->name, (string) $item->code),
                    'published' => (bool) $request->validated('published', false), 'track_stock' => $item->type === 'stock',
                ]);
            }

            return $created;
        });

        return response()->json(['data' => collect($products)->map(fn (Product $p) => Present::product($p))->values()], 201);
    }

    public function update(ProductRequest $request, Product $ecomProduct): JsonResponse
    {
        $store = Store::query()->findOrFail($ecomProduct->store_id);
        $this->manage($request, $store);

        if ($request->has('slug') && Product::query()->where('store_id', $store->id)->where('slug', $request->validated('slug'))->whereKeyNot($ecomProduct->id)->exists()) {
            throw EcomError::make('slug_taken', field: 'slug');
        }

        $ecomProduct->fill($request->safe()->only(['slug', 'description', 'published', 'featured', 'position', 'track_stock', 'in_stock']))->save();

        return response()->json(['data' => Present::product($ecomProduct->refresh())]);
    }

    public function archive(ListRequest $request, Product $ecomProduct): JsonResponse
    {
        $this->manage($request, Store::query()->findOrFail($ecomProduct->store_id));
        $ecomProduct->forceFill(['published' => false])->archive();

        return response()->json(['data' => Present::product($ecomProduct->refresh())]);
    }

    public function restore(ListRequest $request, Product $ecomProduct): JsonResponse
    {
        $this->manage($request, Store::query()->findOrFail($ecomProduct->store_id));
        $ecomProduct->restore();

        return response()->json(['data' => Present::product($ecomProduct->refresh())]);
    }

    private function manage(Request $request, Store $store): void
    {
        abort_unless($this->access->atLocation($request->user(), 'ecommerce.store.view', $store->location_id), 404);
        $this->access->assertAtLocation($request->user(), 'ecommerce.store.manage', $store->location_id);
    }

    private function slug(Store $store, string $name, string $code): string
    {
        $base = Str::slug($name) ?: Str::slug($code) ?: 'product';
        $base = mb_substr(trim($base, '-'), 0, 100);
        $slug = $base;
        $n = 2;

        while (Product::query()->where('store_id', $store->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
