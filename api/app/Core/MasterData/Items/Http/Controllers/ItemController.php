<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\Items\Barcode;
use App\Core\MasterData\Items\Http\Requests\ItemActionRequest;
use App\Core\MasterData\Items\Http\Requests\ItemRequest;
use App\Core\MasterData\Items\Http\Requests\ListItemsRequest;
use App\Core\MasterData\Items\Http\Requests\StoreItemRequest;
use App\Core\MasterData\Items\Http\Requests\UpdateItemRequest;
use App\Core\MasterData\Items\Http\Resources\ItemResource;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\MasterData\Items\ItemReferences;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Items\ItemUniqueness;
use App\Core\MasterData\Items\ItemWriter;
use App\Core\MasterData\Prices\CurrentPrices;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MD-02: the items catalogue, shared or per company (TEN-08). Codes and
 * barcodes are unique among active items in the sharing scope, checked
 * under the items sharing lock (review focus 4). Create and update answer
 * `meta.possible_duplicates` (MD-06), never blocking. Archived, never
 * deleted (TEN-06); every change audited on the item (MD-07), unit and
 * barcode lists as `core.item.units_update` and `core.item.barcodes_update`.
 */
class ItemController
{
    private const RELATIONS = ['uoms.uom:id,code', 'barcodes', 'images'];

    public function __construct(
        private readonly ItemPolicy $policy,
        private readonly MasterDataSharing $sharing,
        private readonly ItemUniqueness $uniqueness,
        private readonly DuplicateFinder $duplicates,
        private readonly ItemReferences $references,
        private readonly CustomFieldWriter $customFields,
        private readonly CustomFieldLists $customLists,
        private readonly ItemWriter $writer,
    ) {}

    public function index(ListItemsRequest $request, ListExport $export): AnonymousResourceCollection|StreamedResponse
    {
        $query = Item::query()->with(self::RELATIONS);
        $companies = $this->policy->listableCompanies($request->user());

        if ($companies !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhereIn('company_id', $companies));
        }

        if ($request->filled('company')) {
            $query->where(fn (Builder $q) => $q->whereNull('company_id')->orWhere('company_id', $request->validated('company')));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }

        if ($request->filled('category')) {
            $query->whereIn('category_id', ItemCategory::withDescendants([$request->validated('category')]));
        }

        if ($request->filled('barcode')) {
            $query->whereIn('id', ItemBarcode::query()->select('item_id')->where('barcode', Barcode::normalise($request->validated('barcode'))));
        }

        // CF-03: `?custom[key]=` filters (validated: known, visible fields).
        $this->customLists->apply($query, ItemEntity::KEY, $request->validated('custom'));

        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $this->search($query, $search, $request);
        }

        // Most similar names first when searching (and names are visible), unless a sort is asked for.
        $relevance = $search === '' || $request->hidesField('name') ? null : fn (Builder $q) => $q->orderByRaw('similarity(name, ?) desc', [$search]);
        $request->applySort($request->applyStatus($query), $relevance);

        if ($request->wantsExport()) {
            return $export->download($request, $query);
        }

        return ItemResource::collection($query->paginate($request->perPage())->withQueryString());
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        return $this->respond($request, $this->writer->create($request->validated()), 201, duplicates: true);
    }

    /** With the item's prices per price list the user reads (MD-03 follow-up). */
    public function show(ItemRequest $request, Item $item, CurrentPrices $prices): JsonResponse
    {
        return ItemResource::make($item->load(self::RELATIONS))->withPrices($prices->forItem($item, $request->user()))->response();
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        return $this->respond($request, $this->writer->update($item, $request->validated()), duplicates: true);
    }

    public function archive(ItemActionRequest $request, Item $item): JsonResponse
    {
        if (! $item->isArchived()) {
            $item->archive();
        }

        return $this->respond($request, $item);
    }

    /**
     * A restored item takes its code and barcodes back: refused (422) while
     * active items use them, or while its category, tax category or units
     * are archived.
     */
    public function restore(ItemActionRequest $request, Item $item): JsonResponse
    {
        $item = $this->writer->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->isArchived()) {
                $this->uniqueness->assert($item->company_id, (string) $item->code, $this->writer->storedBarcodes($item), $item->id);
                $this->references->assertItem($item);
                $this->customFields->assertRestorable(ItemEntity::KEY, $item);
                $item->restore();
            }

            return $item;
        }));

        return $this->respond($request, $item);
    }

    /**
     * Code prefix, name (contains, or trigram-similar), or the exact
     * barcode. A field hidden from the user by field rules is never
     * matched, or the results would reveal its value (RBAC-05).
     */
    private function search(Builder $query, string $search, ListItemsRequest $request): void
    {
        $like = '%'.addcslashes($search, '\\%_').'%';
        $prefix = addcslashes(mb_strtolower($search), '\\%_').'%';
        $barcode = Barcode::normalise($search);
        $visible = fn (string $field) => ! $request->hidesField($field);

        $query->where(function (Builder $q) use ($search, $like, $prefix, $barcode, $visible) {
            $q->whereRaw('false');

            if ($visible('code')) {
                $q->orWhereRaw('lower(code::text) like ?', [$prefix]);
            }

            if ($visible('name')) {
                $q->orWhere('name', 'ilike', $like)->orWhereRaw('name % ?', [$search]);
            }

            if ($barcode !== null && $visible('barcodes')) {
                $q->orWhereIn('id', ItemBarcode::query()->select('item_id')->where('barcode', $barcode));
            }
        });
    }

    private function respond(Request $request, Item $item, int $status = 200, bool $duplicates = false): JsonResponse
    {
        $resource = ItemResource::make($item->refresh()->load(self::RELATIONS));

        if ($duplicates) {
            $resource->additional(['meta' => ['possible_duplicates' => $this->duplicates->forItem($item, $request->user())]]);
        }

        return $resource->response()->setStatusCode($status);
    }
}
