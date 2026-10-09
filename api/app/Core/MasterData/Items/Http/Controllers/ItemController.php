<?php

namespace App\Core\MasterData\Items\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\CustomFields\CustomFieldLists;
use App\Core\CustomFields\CustomFieldWriter;
use App\Core\CustomFields\Entities\ItemEntity;
use App\Core\Exports\ListExport;
use App\Core\MasterData\Duplicates\DuplicateFinder;
use App\Core\MasterData\Items\Barcode;
use App\Core\MasterData\Items\Http\Requests\ItemActionRequest;
use App\Core\MasterData\Items\Http\Requests\ItemRequest;
use App\Core\MasterData\Items\Http\Requests\ItemRules;
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
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Prices\CurrentPrices;
use App\Core\MasterData\Sharing\MasterDataSharing;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
        private readonly Auditor $auditor,
        private readonly ItemReferences $references,
        private readonly CustomFieldWriter $customFields,
        private readonly CustomFieldLists $customLists,
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
        $data = $request->validated();
        $companyId = $data['company_id'] ?? null;

        $item = $this->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($data, $companyId) {
            // Lock order, as in MasterDataSharing::switch: the sharing lock first, then rows.
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $this->assertModeUnchanged($companyId);

            $barcodes = ItemRules::barcodes($data, $data['base_uom_id']) ?? [];
            $this->uniqueness->assert($companyId, $data['code'], array_column($barcodes, 'barcode'), null);
            $this->references->assertActive(
                $companyId, $data['category_id'] ?? null, $data['tax_category_id'] ?? null, $data['base_uom_id'], array_column($data['uoms'] ?? [], 'uom_id'),
            );

            $item = new Item(['company_id' => $companyId, ...ItemRules::attributes($data)]);
            // CF-01, CF-02: defaults, formulas and unique fields, then the item's files.
            $this->customFields->fill(ItemEntity::KEY, $item, $data['custom'] ?? null, creating: true);
            $item->save();
            $this->customFields->saved(ItemEntity::KEY, $item);
            $this->syncUoms($item, $data['uoms'] ?? []);
            $this->syncBarcodes($item, $barcodes);

            return $item;
        }));

        return $this->respond($request, $item, 201, duplicates: true);
    }

    /** With the item's prices per price list the user reads (MD-03 follow-up). */
    public function show(ItemRequest $request, Item $item, CurrentPrices $prices): JsonResponse
    {
        return ItemResource::make($item->load(self::RELATIONS))->withPrices($prices->forItem($item, $request->user()))->response();
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $data = $request->validated();

        $item = $this->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item, $data) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $companyId = array_key_exists('company_id', $data) ? $data['company_id'] : $item->company_id;
            $this->assertModeUnchanged($companyId);

            $baseUomId = $data['base_uom_id'] ?? $item->base_uom_id;
            $barcodes = ItemRules::barcodes($data, $baseUomId);
            $this->uniqueness->assert(
                $companyId,
                $data['code'] ?? (string) $item->code,
                $barcodes === null ? $this->storedBarcodes($item) : array_column($barcodes, 'barcode'),
                $item->id,
            );
            $this->references->assertActive(
                $companyId,
                array_key_exists('category_id', $data) ? $data['category_id'] : $item->category_id,
                array_key_exists('tax_category_id', $data) ? $data['tax_category_id'] : $item->tax_category_id,
                $baseUomId,
                array_key_exists('uoms', $data) ? array_column($data['uoms'], 'uom_id') : ItemUom::query()->where('item_id', $item->id)->pluck('uom_id')->all(),
            );

            $item->fill(['company_id' => $companyId, ...ItemRules::attributes($data)]);
            $this->customFields->fill(ItemEntity::KEY, $item, $data['custom'] ?? null, creating: false);
            $item->save();
            $this->customFields->saved(ItemEntity::KEY, $item);

            if (array_key_exists('uoms', $data)) {
                $this->syncUoms($item, $data['uoms']);
            }

            if ($barcodes !== null) {
                $this->syncBarcodes($item, $barcodes);
            }

            return $item;
        }));

        return $this->respond($request, $item, duplicates: true);
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
        $item = $this->writing(fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($item) {
            $this->sharing->lockForWrite([ItemSharing::DATA_TYPE]);
            $item = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();

            if ($item->isArchived()) {
                $this->uniqueness->assert($item->company_id, (string) $item->code, $this->storedBarcodes($item), $item->id);
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

    /**
     * Replace the item's other units; one audit entry for the change.
     *
     * @param  list<array{uom_id: string, factor: int|string, is_sales_default?: bool, is_purchase_default?: bool}>  $uoms
     */
    private function syncUoms(Item $item, array $uoms): void
    {
        $before = $this->unitsOf($item);
        ItemUom::query()->where('item_id', $item->id)->get()->each(fn (ItemUom $uom) => $uom->delete());

        foreach ($uoms as $uom) {
            ItemUom::create([
                'item_id' => $item->id,
                'uom_id' => $uom['uom_id'],
                'factor' => (string) BigDecimal::of((string) $uom['factor']),
                'is_sales_default' => (bool) ($uom['is_sales_default'] ?? false),
                'is_purchase_default' => (bool) ($uom['is_purchase_default'] ?? false),
            ]);
        }

        $after = $this->unitsOf($item);

        if ($after !== $before) {
            $this->auditor->record('core.item.units_update', $item, ['uoms' => $before], ['uoms' => $after]);
        }
    }

    /** @param list<array{barcode: string, uom_id: ?string}> $barcodes */
    private function syncBarcodes(Item $item, array $barcodes): void
    {
        $before = $this->barcodesOf($item);
        ItemBarcode::query()->where('item_id', $item->id)->get()->each(fn (ItemBarcode $barcode) => $barcode->delete());

        foreach ($barcodes as $barcode) {
            ItemBarcode::create(['item_id' => $item->id, ...$barcode]);
        }

        $after = $this->barcodesOf($item);

        if ($after !== $before) {
            $this->auditor->record('core.item.barcodes_update', $item, ['barcodes' => $before], ['barcodes' => $after]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function unitsOf(Item $item): array
    {
        return ItemUom::query()->where('item_id', $item->id)->orderBy('uom_id')->get()
            ->map(fn (ItemUom $uom) => [
                'uom_id' => $uom->uom_id,
                'factor' => (string) BigDecimal::of($uom->factor)->strippedOfTrailingZeros(),
                'is_sales_default' => $uom->is_sales_default,
                'is_purchase_default' => $uom->is_purchase_default,
            ])->all();
    }

    /** @return list<array{barcode: string, uom_id: ?string}> */
    private function barcodesOf(Item $item): array
    {
        return ItemBarcode::query()->where('item_id', $item->id)->orderBy('barcode')->get()
            ->map(fn (ItemBarcode $barcode) => ['barcode' => $barcode->barcode, 'uom_id' => $barcode->uom_id])->all();
    }

    /** @return list<string> */
    private function storedBarcodes(Item $item): array
    {
        return ItemBarcode::query()->where('item_id', $item->id)->pluck('barcode')->all();
    }

    /** Re-checked under the sharing lock: the mode may have changed since validation (TEN-08). */
    private function assertModeUnchanged(?string $companyId): void
    {
        if (! ItemSharing::fits($companyId)) {
            throw ValidationException::withMessages(['company_id' => __('core.master_data.sharing_changed')]);
        }
    }

    /** A unique index caught a race the checks under the lock could not see: same errors as the checks. */
    private function writing(callable $write): Item
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->uniqueness->fromViolation($e) ?? $e;
        }
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
